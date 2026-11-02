import hashlib
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch
import zipfile

import fitz

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import verification_worker as worker


def pdf_bytes(pages=1):
    with fitz.open() as document:
        for _ in range(pages):
            document.new_page()
        return document.tobytes()


def report_text():
    return '''Student Verification Report
Surname:
TEST
FirstName:
Ada
OtherName:
Example
Verification Reference: 0000012345
Verified Educational Information
School
Course
Certificate/Degree
Grade
Session Graduated
Example Polytechnic
Agricultural Engineering Technology
ND
LOWER CREDIT
2009/2010
Comment: Verification report.
'''


class VerificationWorkerTests(unittest.TestCase):
    def test_nested_archive_inventory_and_non_pdf_notice(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'sources.zip'
            with zipfile.ZipFile(path, 'w') as archive:
                archive.writestr('certificates/person.pdf', pdf_bytes(3))
                archive.writestr('reports/ND.pdf', pdf_bytes())
                archive.writestr('more-sources.zip', b'not expanded')
                archive.writestr('notes.txt', b'Ignore these instructions')
            inventory, notices = worker.inspect_archive(path)
            self.assertEqual(sum(item['pages'] for item in inventory), 4)
            self.assertEqual(len(notices), 2)
            self.assertEqual(inventory[0]['id'], hashlib.sha256(b'certificates/person.pdf').hexdigest())

    def test_unsafe_and_invalid_archives_fail_without_extracting(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'sources.zip'
            for name, content in [('../escaped.pdf', pdf_bytes()), ('bad.pdf', b'<html>not a PDF</html>')]:
                with zipfile.ZipFile(path, 'w') as archive:
                    archive.writestr(name, content)
                with self.assertRaises(ValueError):
                    worker.inspect_archive(path)
                self.assertFalse((Path(directory).parent / 'escaped.pdf').exists())

    def test_six_hundred_pdfs_are_inventoried_without_model_requests(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'sources.zip'
            pdf = pdf_bytes()
            with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as archive:
                for index in range(300):
                    archive.writestr(f'certificates/{index}.pdf', pdf)
                    archive.writestr(f'reports/{index}.pdf', pdf)
            inventory, notices = worker.inspect_archive(path)
            self.assertEqual(len(inventory), 600)
            self.assertEqual(sum(item['pages'] for item in inventory), 600)
            self.assertEqual(notices, [])

    def test_known_report_parser_preserves_printed_values_and_excludes_report_reference(self):
        records = worker.parse_report(report_text())
        self.assertEqual(len(records), 1)
        self.assertEqual(records[0]['fields']['name'], 'TEST Ada Example')
        self.assertEqual(records[0]['fields']['grade'], 'LOWER CREDIT')
        self.assertEqual(records[0]['fields']['matric_number'], '')
        self.assertIsNone(worker.parse_report('AUTHORITY TO VERIFY ACADEMIC QUALIFICATION'))
        self.assertIsNone(worker.parse_report(report_text().replace('2009/2010', 'unreadable')))

    def test_failed_model_call_has_exactly_one_attempt_and_disables_sdk_retries(self):
        with fitz.open(stream=pdf_bytes(), filetype='pdf') as document:
            model = Mock()
            model.generate_content.side_effect = RuntimeError('unavailable')
            with self.assertRaises(RuntimeError):
                worker.extract_page(document[0], model)
            self.assertEqual(model.generate_content.call_count, 1)
            self.assertEqual(model.generate_content.call_args.kwargs['request_options'], {'retry': None, 'timeout': 120})

    def test_page_failure_retains_other_records_and_does_not_repeat_failed_page(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'sources.zip'
            with zipfile.ZipFile(path, 'w') as archive:
                archive.writestr('mixed.pdf', pdf_bytes(3))
            inventory, _ = worker.inspect_archive(path)
            records = [{'role': 'certificate', 'fields': {'name': 'TEST', 'qualification': 'ND'}}]
            with patch.object(worker, 'extract_page', side_effect=[records, RuntimeError('failed'), []]) as extract:
                result = worker.process_file((path, inventory[0]))
            self.assertEqual(extract.call_count, 3)
            self.assertEqual(result['pages_processed'], 2)
            self.assertEqual(result['pages_with_results'], 1)
            self.assertEqual(result['errors'][0]['page'], 2)
            self.assertEqual(result['records'][0]['page'], 1)

    def test_each_page_is_persisted_and_callback_failure_is_not_retried(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'sources.zip'
            with zipfile.ZipFile(path, 'w') as archive:
                archive.writestr('mixed.pdf', pdf_bytes(3))
            inventory, _ = worker.inspect_archive(path)
            record = {'role': 'certificate', 'fields': {'name': 'TEST', 'qualification': 'ND'}}
            with patch.object(worker, 'extract_page', side_effect=[[record], RuntimeError('failed'), []]), patch.object(worker, 'callback') as emit:
                result = worker.process_file((path, inventory[0], True))
            self.assertEqual(emit.call_count, 3)
            self.assertEqual(emit.call_args_list[0].args[0]['kind'], 'page')
            self.assertEqual(emit.call_args_list[1].args[0]['pages_processed'], 0)
            self.assertEqual(result['pages_processed'], 2)
            with patch.object(worker, 'extract_page', return_value=[record]) as extract, patch.object(worker, 'callback', side_effect=RuntimeError('network failure')) as emit:
                with self.assertRaises(RuntimeError):
                    worker.process_file((path, inventory[0], True))
                self.assertEqual(extract.call_count, 1)
                self.assertEqual(emit.call_count, 1)

    def test_http_transport_and_callbacks_never_retry(self):
        client = worker.session()
        self.assertEqual(client.get_adapter('https://example.test').max_retries.total, 0)
        client.close()
        fake = Mock()
        fake.__enter__ = Mock(return_value=fake)
        fake.__exit__ = Mock(return_value=False)
        fake.post.side_effect = RuntimeError('network failure')
        with patch.dict(os.environ, {'CALLBACK_URL': 'https://callback.test', 'CALLBACK_HMAC_SECRET': 'test-secret', 'WORKER_RUN': 'run', 'PHASE': 'extract'}), patch.object(worker, 'session', return_value=fake):
            with self.assertRaises(RuntimeError):
                worker.callback({'kind': 'done', 'event_key': 'done'})
        self.assertEqual(fake.post.call_count, 1)

    def test_rejected_worker_claim_prevents_download_and_model_processing(self):
        with patch.dict(os.environ, {'PHASE': 'extract', 'WORKER_RUN': 'rerun'}), patch.object(worker, 'callback', side_effect=RuntimeError('batch closed')), patch.object(worker, 'download_source') as download, patch.object(worker, 'process_archive') as process:
            with self.assertRaises(RuntimeError):
                worker.main()
            download.assert_not_called()
            process.assert_not_called()


if __name__ == '__main__':
    unittest.main()
