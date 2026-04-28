"""
CERTIFICATE PDF EXTRACTOR
=========================
Extracts certificate data from academic certificates using Gemini Vision API.

Extracted columns per certificate:
  name, institution, course, qualification, grade, session, matric_number

Plus manual fields passed in as environment variables (replicated to each row):
  date_received, completed_date, client_name
"""

import os
import io
import json
import time
import base64
import re
from typing import List, Dict, Any, Optional
from dataclasses import dataclass, asdict
from pathlib import Path

import pandas as pd
from PIL import Image
from PIL.Image import DecompressionBombError
from pdf2image import convert_from_path
import google.generativeai as genai


@dataclass
class CertificateRecord:
    """One extracted certificate record"""
    name: str
    institution: str
    course: str
    qualification: str
    grade: str
    session: str
    matric_number: str
    # Manual fields replicated from upload
    date_received: str = ''
    completed_date: str = ''
    client_name: str = ''

    def to_dict(self):
        return asdict(self)


class CertificatePDFExtractor:
    """Extracts certificate records from a PDF using Gemini Vision."""

    DPI_FALLBACKS = (300, 240, 200, 150)

    EXTRACTION_PROMPT = """You are an expert data extractor for Nigerian university/polytechnic certificates.

Analyze this certificate image and extract ALL certificate records visible on the page.
A single page may contain one or multiple certificates.

For EACH certificate, extract:
- name: Full name of the certificate holder
- institution: Name of the university or institution
- course: Course / programme of study
- qualification: Degree/diploma awarded (e.g. B.Sc., HND, B.Eng., M.Sc.)
- grade: Class of degree or result (e.g. Second Class Upper, Upper Credit, Distinction)
- session: Academic session/year (e.g. 2021/2022 or 2022)
- matric_number: Matriculation/registration number if visible (else empty string)

Return ONLY a JSON array of objects with exactly these keys:
[
  {
    "name": "...",
    "institution": "...",
    "course": "...",
    "qualification": "...",
    "grade": "...",
    "session": "...",
    "matric_number": "..."
  }
]

Rules:
- If a field is not visible, use an empty string "".
- Do NOT add any explanations outside the JSON.
- If no certificate data is found on this page, return an empty array: []
"""

    def __init__(self, api_key: str):
        genai.configure(api_key=api_key)
        self.model = genai.GenerativeModel('gemini-2.5-flash')

    def convert_pdf_to_images(self, pdf_path: str, dpi: int = 300) -> List[Image.Image]:
        attempted_dpis = []
        fallback_dpis = [dpi] + [candidate for candidate in self.DPI_FALLBACKS if candidate < dpi]

        for current_dpi in fallback_dpis:
            attempted_dpis.append(current_dpi)
            print(f'[cert_agent] Converting PDF to images (DPI={current_dpi})...')
            try:
                images = convert_from_path(pdf_path, dpi=current_dpi)
                print(f'[cert_agent] {len(images)} pages converted at DPI={current_dpi}')
                return images
            except DecompressionBombError:
                if current_dpi == fallback_dpis[-1]:
                    raise
                print(
                    '[cert_agent] Rendered page exceeded Pillow pixel safety limit at '
                    f'DPI={current_dpi}; retrying with lower DPI.'
                )

        raise RuntimeError(
            'Failed to convert PDF to images after trying DPI values: '
            + ', '.join(str(value) for value in attempted_dpis)
        )

    def encode_image(self, image: Image.Image) -> str:
        buf = io.BytesIO()
        image.save(buf, format='PNG')
        return base64.b64encode(buf.getvalue()).decode('utf-8')

    def extract_page(self, image: Image.Image, page_num: int) -> List[Dict[str, Any]]:
        """Run Gemini vision on one page; returns list of record dicts."""
        for attempt in range(3):
            try:
                response = self.model.generate_content([self.EXTRACTION_PROMPT, image])
                text = response.text.strip()
                # Strip markdown fences if present
                text = re.sub(r'^```(?:json)?\s*', '', text)
                text = re.sub(r'\s*```$', '', text)
                arr_match = re.search(r'\[.*\]', text, re.DOTALL)
                if arr_match:
                    records = json.loads(arr_match.group(0))
                    if isinstance(records, list):
                        print(f'[cert_agent] Page {page_num}: {len(records)} record(s) extracted')
                        return records
                print(f'[cert_agent] Page {page_num}: no JSON array found in response')
                return []
            except Exception as e:
                wait = 2 ** attempt * 5
                print(f'[cert_agent] Page {page_num} attempt {attempt + 1} error: {e!r}. Retrying in {wait}s...')
                time.sleep(wait)
        print(f'[cert_agent] Page {page_num}: all attempts failed, skipping')
        return []

    def process_pdf(self, pdf_path: str, dpi: int = 300,
                    date_received: str = '', completed_date: str = '',
                    client_name: str = '') -> List[CertificateRecord]:
        images = self.convert_pdf_to_images(pdf_path, dpi=dpi)
        all_records: List[CertificateRecord] = []
        for i, img in enumerate(images, start=1):
            raw = self.extract_page(img, i)
            for r in raw:
                rec = CertificateRecord(
                    name=str(r.get('name', '')).strip(),
                    institution=str(r.get('institution', '')).strip(),
                    course=str(r.get('course', '')).strip(),
                    qualification=str(r.get('qualification', '')).strip(),
                    grade=str(r.get('grade', '')).strip(),
                    session=str(r.get('session', '')).strip(),
                    matric_number=str(r.get('matric_number', '')).strip(),
                    date_received=date_received,
                    completed_date=completed_date,
                    client_name=client_name,
                )
                all_records.append(rec)
        print(f'[cert_agent] Total records extracted: {len(all_records)}')
        return all_records
