import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import pandas as pd
from openpyxl import load_workbook

import aggregate_results
from certificate_agent import CertificatePDFExtractor
from certificate_schema import ALL_CERTIFICATE_COLUMNS, normalize_all_certificate


class AllCertificateTests(unittest.TestCase):
    def test_template_pass_rules_leave_academic_and_professional_grades_intact(self):
        for kind in ['NYSC', 'WAEC', 'NECO', 'NABTEB']:
            record = normalize_all_certificate({'certificate_type': kind, 'grade': 'F9', 'matric_number': '00211007'})
            self.assertEqual((record['course'], record['qualification'], record['grade']), (kind, kind, 'PASS'))
            self.assertEqual(record['matric_number'], '00211007')
        for kind in ['ACADEMIC', 'ICAN', 'CALL TO BAR']:
            self.assertEqual(normalize_all_certificate({'certificate_type': kind, 'grade': 'DISTINCTION'})['grade'], 'DISTINCTION')

    def test_multiple_certificates_count_as_one_page_with_results(self):
        extractor = CertificatePDFExtractor('test-key', layout='all')
        extractor.convert_pdf_to_images = Mock(return_value=[object(), object()])
        extractor.extract_page = Mock(side_effect=[[
            {'certificate_type': 'WAEC', 'name': 'ONE', 'matric_number': '001234'},
            {'certificate_type': 'NYSC', 'name': 'TWO', 'matric_number': 'AB/001'},
        ], []])
        records = extractor.process_pdf('fake.pdf', client_name='CLIENT')
        self.assertEqual(len(records), 2)
        self.assertEqual((extractor.pages_processed, extractor.pages_with_results), (2, 1))
        self.assertEqual(records[0].matric_number, '001234')
        self.assertTrue(all(r.grade == 'PASS' for r in records))

    def test_exhausted_model_failures_do_not_silently_complete(self):
        extractor = CertificatePDFExtractor('test-key', layout='all')
        extractor.model = Mock()
        extractor.model.generate_content.side_effect = RuntimeError('upstream unavailable')
        with patch('certificate_agent.time.sleep'), self.assertRaises(RuntimeError):
            extractor.extract_page(object(), 1)

    def test_aggregation_matches_template_and_preserves_text_ids_and_page_counts(self):
        with tempfile.TemporaryDirectory() as directory:
            previous = os.getcwd()
            os.chdir(directory)
            try:
                Path('chunks').mkdir()
                pd.DataFrame([{
                    'name': 'TEST NAME', 'institution': 'NATIONAL EXAMINATION COUNCIL',
                    'course': 'NECO', 'qualification': 'NECO', 'grade': 'PASS', 'session': '2016',
                    'matric_number': '00450849', 'date_received': '02/11/2026', 'client_name': 'TEST CLIENT',
                }]).to_csv('chunks/mixed-p1-end.csv', index=False)
                Path('chunks/mixed-p1-end.json').write_text(json.dumps({'pages_processed': 2, 'pages_with_results': 1}))
                env = {'ORIGINAL_FILENAME': 'mixed.pdf', 'EXTRACTION_TYPE': 'certificates',
                       'CERTIFICATE_LAYOUT': 'all', 'AGG_SKIP_DOCX': 'true', 'DOC_ID': '42',
                       'CALLBACK_URL': '', 'RESULT_UPLOAD_URL': ''}
                with patch.dict(os.environ, env), patch.object(aggregate_results, 'upload_results') as upload:
                    aggregate_results.main()
                summary = upload.call_args.args[-1]
                self.assertEqual(summary['doc_id'], '42')
                self.assertEqual(summary['counts'], {'rows': 1, 'pages_processed': 2, 'pages_with_results': 1})
                workbook = load_workbook('outputs/mixed.xlsx')
                sheet = workbook.active
                self.assertEqual([c.value for c in sheet[1]], list(ALL_CERTIFICATE_COLUMNS.values()))
                self.assertEqual(sheet['I2'].value, '00450849')
                self.assertEqual(sheet['I2'].data_type, 's')
                csv = pd.read_csv('outputs/mixed.csv', dtype=str)
                self.assertEqual(csv.iloc[0]['matric_number'], '00450849')
                workbook.close()
            finally:
                os.chdir(previous)


if __name__ == '__main__':
    unittest.main()
