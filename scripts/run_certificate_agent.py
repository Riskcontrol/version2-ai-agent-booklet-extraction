#!/usr/bin/env python3
"""
run_certificate_agent.py
Runner for the certificate extraction pipeline.
Mirrors run_agent.py but uses CertificatePDFExtractor.
"""

import os
import json
import hashlib
import hmac
import tempfile
import shutil
from typing import Dict
from pathlib import Path
import importlib.util

import requests
import pandas as pd

# Robustly load certificate_agent.py from same directory
_scripts_dir = Path(__file__).resolve().parent
_agent_path = _scripts_dir / 'certificate_agent.py'
if not _agent_path.exists():
    raise FileNotFoundError(f'certificate_agent.py not found at {_agent_path}')
_spec = importlib.util.spec_from_file_location('certificate_agent_local', str(_agent_path))
if _spec is None or _spec.loader is None:
    raise ImportError('Could not load certificate_agent.py module spec')
_agent_mod = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_agent_mod)
CertificatePDFExtractor = getattr(_agent_mod, 'CertificatePDFExtractor')


def compute_signature(secret: str, payload: bytes) -> str:
    return hmac.new(secret.encode('utf-8'), payload, hashlib.sha256).hexdigest()


def upload_results(upload_url: str, token: str, doc_id: str, paths: Dict[str, str], summary: Dict) -> None:
    if not upload_url:
        print('[cert_runner] RESULT_UPLOAD_URL empty; skipping upload')
        return
    try:
        files = {}
        for key in ['csv', 'xlsx']:
            p = paths.get(key)
            if p and os.path.exists(p):
                files[key] = (os.path.basename(p), open(p, 'rb'), 'application/octet-stream')
        data = {'doc_id': doc_id or '', 'summary': json.dumps(summary)}
        headers = {}
        if token:
            headers['Authorization'] = f'Bearer {token}'
            headers['X-Extractor-Token'] = token
        resp = requests.post(upload_url, files=files, data=data, headers=headers, timeout=120)
        print('[cert_runner] Upload results status:', resp.status_code)
    except Exception as e:
        print('[cert_runner] Upload results error:', repr(e))


def send_callback(callback_url: str, callback_secret: str, doc_id: str, original_filename: str,
                  paths: Dict[str, str], total_records: int) -> None:
    if not callback_url:
        print('[cert_runner] CALLBACK_URL empty; skipping callback')
        return
    payload_dict = {
        'filename': original_filename,
        'doc_id': doc_id,
        'status': 'complete',
        'total_records': total_records,
        'files': {k: '' for k in paths},
    }
    payload_bytes = json.dumps(payload_dict, separators=(',', ':')).encode('utf-8')
    sig = compute_signature(callback_secret, payload_bytes) if callback_secret else ''
    headers = {'Content-Type': 'application/json'}
    if sig:
        headers['X-Extractor-Signature'] = sig
    try:
        resp = requests.post(callback_url, data=payload_bytes, headers=headers, timeout=60)
        print('[cert_runner] Callback status:', resp.status_code)
    except Exception as e:
        print('[cert_runner] Callback error:', repr(e))


def main() -> int:
    api_key = os.getenv('GEMINI_API_KEY') or os.getenv('GEMINI-API-KEY')
    if not api_key:
        print('[cert_runner] GEMINI_API_KEY missing')
        return 2

    source_url = os.getenv('SOURCE_URL', '').strip()
    source_file = os.getenv('SOURCE_FILE', '').strip()
    original_filename = os.getenv('ORIGINAL_FILENAME', 'document.pdf')
    dpi = int(os.getenv('DPI', '300') or '300')

    # Manual fields
    date_received = os.getenv('DATE_RECEIVED', '').strip()
    completed_date = os.getenv('COMPLETED_DATE', '').strip()
    client_name = os.getenv('CLIENT_NAME', '').strip()

    # App integration
    callback_url = os.getenv('CALLBACK_URL', '').strip()
    callback_secret = os.getenv('CALLBACK_HMAC_SECRET', '').strip()
    result_upload_url = os.getenv('RESULT_UPLOAD_URL', '').strip()
    result_upload_token = os.getenv('RESULT_UPLOAD_TOKEN', '').strip()
    doc_id = os.getenv('DOC_ID', '')

    tmpdir = tempfile.mkdtemp(prefix='cert_agent_')
    try:
        # Resolve PDF path
        if source_file and os.path.exists(source_file):
            pdf_path = source_file
        elif source_url:
            pdf_path = os.path.join(tmpdir, 'source.pdf')
            print(f'[cert_runner] Downloading PDF from signed URL...')
            resp = requests.get(source_url, timeout=300, stream=True)
            resp.raise_for_status()
            with open(pdf_path, 'wb') as fh:
                for chunk in resp.iter_content(65536):
                    fh.write(chunk)
            print(f'[cert_runner] PDF downloaded to {pdf_path}')
        else:
            print('[cert_runner] Neither SOURCE_FILE nor SOURCE_URL provided')
            return 2

        extractor = CertificatePDFExtractor(api_key=api_key)
        records = extractor.process_pdf(
            pdf_path,
            dpi=dpi,
            date_received=date_received,
            completed_date=completed_date,
            client_name=client_name,
        )

        # Build output
        base = Path(original_filename).stem
        out_dir = 'outputs'
        os.makedirs(out_dir, exist_ok=True)

        if records:
            df = pd.DataFrame([r.to_dict() for r in records])
        else:
            df = pd.DataFrame(columns=[
                'name', 'institution', 'course', 'qualification', 'grade',
                'session', 'matric_number', 'date_received', 'completed_date', 'client_name',
            ])

        csv_path = os.path.join(out_dir, base + '.csv')
        xlsx_path = os.path.join(out_dir, base + '.xlsx')
        df.to_csv(csv_path, index=False)
        print(f'[cert_runner] CSV written: {csv_path}')
        try:
            df.to_excel(xlsx_path, index=False)
            print(f'[cert_runner] XLSX written: {xlsx_path}')
        except Exception as e:
            print(f'[cert_runner] XLSX write failed: {e}')
            xlsx_path = None

        paths = {'csv': csv_path}
        if xlsx_path and os.path.exists(xlsx_path):
            paths['xlsx'] = xlsx_path

        summary = {'total_records': len(records), 'doc_id': doc_id}

        upload_results(result_upload_url, result_upload_token, doc_id, paths, summary)
        send_callback(callback_url, callback_secret, doc_id, original_filename, paths, len(records))

        return 0
    finally:
        shutil.rmtree(tmpdir, ignore_errors=True)


if __name__ == '__main__':
    raise SystemExit(main())
