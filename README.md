# RCS Convocation Extractor — Laravel + GitHub Actions

End‑to‑end pipeline: upload a Convocation PDF in the Laravel app, process it in GitHub Actions, and push structured results (CSV/XLSX/DOCX) back to the app. Now resilient for long PDFs via chunked extraction.

## Prerequisites
- PHP 8.1+ and Composer
- Node 18+ (optional for frontend tweaks)
- XAMPP MySQL running locally
- Git and GitHub access

## Setup
1. Laravel app lives in `rcs-app` (already scaffolded).
2. Frontend runs as a Blade view with Vite/Tailwind. Entry: `resources/views/convocation.blade.php` and `resources/js/convocation.js`.
3. Configure environment in `rcs-app/.env`:
   - APP_URL=http://127.0.0.1:8000
   - DB_CONNECTION=mysql
   - DB_HOST=127.0.0.1
   - DB_PORT=3306
   - DB_DATABASE=ai_agent_secondlevel_verification
   - DB_USERNAME=root
   - DB_PASSWORD=
   - GITHUB_PAT=your_pat_with_repo_write
   - EXTRACTOR_CALLBACK_SECRET=your_random_long_secret
   - EXTRACTOR_BEARER_TOKEN=your_random_long_secret
4. Link storage and generate app key:
   - php artisan key:generate
   - php artisan storage:link
5. Migrate DB:
   - php artisan migrate

## Run (Local)
- cd rcs-app
- php artisan serve --host=127.0.0.1 --port=8000
- Open http://127.0.0.1:8000/ (convocation UI is served by Blade)

## All Certificate page

`/all-certificates` is an additional authenticated page for academic certificates,
ICAN, Call to Bar, NYSC, WAEC, NECO and NABTEB. The original `/certificates` page
keeps its academic extraction layout and document history. The new page accepts
PDFs and JPG/PNG images; images are converted in the browser into a one-page PDF.
Mixed certificates can be uploaded together in a PDF, with one output row per
certificate rather than per subject.

The Excel download follows the supplied template in this order:
Date Received, Clients, Names, Institution, Courses, Qualification, Grade, Session,
Exam Number/Call-up Number/Matric number. NYSC, WAEC, NECO and NABTEB use their
abbreviation for Courses and Qualification and use PASS for Grade, as requested.
Academic and professional certificates retain their printed programme, award and
overall grade when available. Missing or illegible fields remain blank. The CSV
retains the existing internal field names for database import; the Excel download
uses the template headings. Identifiers remain text to preserve leading zeros.

Billing still uses `extraction_type=certificates`, the existing Peldarg authorization,
paid workflow tier and finalization/refund paths. It reserves one credit per PDF
page (one per image), irrespective of the number of certificate records on a page.
The certificate workflow now carries actual processed-page and result-page counts
through aggregation, and only aggregation uploads/finalizes the output.

To activate this change, deploy the Laravel changes, built frontend assets, Python
scripts and `.github/workflows/process_certificates.yml` together. Run
`php artisan migrate --force` on the application server, then refresh its route,
view and configuration caches with `php artisan optimize:clear` followed by the
deployment's usual cache-building commands. The migration adds a defaulted
`certificate_layout` column; existing documents retain the academic layout.

Checks (without live billing or Gemini requests):

```bash
cd rcs-app
APP_CONFIG_CACHE=/tmp/rcs-test-config.php APP_ROUTES_CACHE=/tmp/rcs-test-routes.php php vendor/bin/phpunit --filter AllCertificatesTest
```

From the repository root: `python3 -m unittest discover -s scripts/tests -v`.

## Endpoints
- POST /api/upload
- GET  /api/documents
- DELETE /api/documents
- GET  /api/search
- POST /api/github/callback
- POST /api/github/upload-results

## GitHub Actions (Extractor)
Workflow: `.github/workflows/process_pdf.yml`.

What it does now:
- Probes the uploaded PDF and determines total pages.
- Splits work into page ranges (default 10 pages per chunk) and runs them in parallel.
- Downloads the source PDF once and reuses it across chunks (prevents signed URL expiration issues).
- Aggregates CSV results from all chunks into a single CSV/XLSX/DOCX.
- Uploads results back to the app and posts a JSON callback on success.

Required repo secrets:
- `CALLBACK_HMAC_SECRET` -> must equal `EXTRACTOR_CALLBACK_SECRET` in Laravel `.env`.
- `RESULT_UPLOAD_TOKEN` -> must equal `EXTRACTOR_BEARER_TOKEN` in Laravel `.env`.

Important app settings:
- Set `APP_URL` to your live domain (e.g., `https://search.riskcontrolnigeria.com`).
- We force HTTPS and use `URL::temporarySignedRoute` for downloads. Expiry is set to 24h for reliability..

Optional knob in client payload:
- `chunk_size` (int): pages per chunk. If omitted, defaults to 10.

## Notes
- The upload endpoint only dispatches to GitHub when `GITHUB_PAT` is set.
- Extractor script: `scripts/extract.py`
  - Supports page ranges via env: `PAGE_START`, `PAGE_END`, or `PAGES` (e.g., `1-10,12,15-18`).
  - OCR tuning: `OCR_DPI` (default 250), `TESSERACT_PSM`, `TESSERACT_LANG`.
  - Keep‑alive logs per page to avoid idle timeouts.
- Aggregation script: `scripts/aggregate_results.py` merges chunk CSVs and performs a single upload+callback.

## Troubleshooting & Recovery Runbook

If a long PDF run was cancelled or took too long:
1. Ensure GitHub repo secrets are set:
   - `CALLBACK_HMAC_SECRET` and `RESULT_UPLOAD_TOKEN` must not be empty.
2. Ensure Laravel `.env` matches:
   - `EXTRACTOR_CALLBACK_SECRET` and `EXTRACTOR_BEARER_TOKEN` match the GitHub secrets.
   - `APP_URL` uses your HTTPS live domain; run `php artisan config:clear` then `config:cache`.
3. Re‑upload the PDF via the UI. The workflow now:
   - Splits into 10‑page chunks (configurable `chunk_size`).
   - Uses the single downloaded source artifact across chunks (no signed URL churn).
   - Aggregates and uploads results to the app.
4. If OCR is slow or unnecessary:
   - Many Convocation PDFs are text‑based; extraction will auto‑detect and skip OCR.
   - For image‑only PDFs, you can lower `OCR_DPI` (e.g., 220) or set `TESSERACT_PSM` depending on layout.
5. For very large documents:
   - Increase concurrency by lowering `chunk_size`.
   - Consider a self‑hosted runner with more CPU/RAM; the workflow works on both hosted and self‑hosted.

## Self‑Hosted Runner (Optional)
If you prefer not to use GitHub’s hosted runners for long extractions:
- Register a self‑hosted runner on a machine with Tesseract and Poppler.
- Ensure Python 3.11 and the packages in `requirements.txt` are installed.
- The same workflow will run on your runner (change `runs-on` to your label if needed).

## Verify Certificate & Report

`/verify-certificate-report` accepts unordered ZIP archives containing certificate
PDFs and RCS verification-report PDFs. Each qualification is a separate record;
consent forms and supporting pages do not become certificate records. Original
PDFs remain in private storage and source previews require the batch owner's
session. The system compares supplied documents; it does not contact institutions.

The flow is: sequential ZIP upload → background inventory → explicit page/credit
confirmation → background extraction → matching and field comparisons → review.
The default limits are a 1 GiB ZIP, 1,200 PDFs, 10,000 PDF pages, 128 MiB per PDF,
4 GiB expanded archive content, and four parallel extraction processes. Upload
requests contain at most 512 KiB each. Non-PDF files and nested archives are listed
as excluded; unsafe paths, symlinks, encrypted files and invalid PDFs fail inventory.
The PHP server keeps the ZIP without unpacking it; workers inspect it independently.

**No automatic retries or resume:** upload chunks, model calls, HTTP requests and
worker execution each receive one attempt. Reuploading creates a separate batch
and a new credit authorization for all its pages, regardless of previous uploads.
GitHub workflow reruns cannot reacquire a claimed/closed batch. Staff can correct
pairings using stored values without re-extraction or another credit charge.

Inventory is unbilled. Extraction reserves one credit per PDF page across both
certificates and reports, including supporting pages. Completion or failure uses
the existing partner finalize/refund contract with actual successfully processed
page counts, never record counts. Failed pages are not automatically processed
again. Completed page results are saved immediately and survive other file/page failures. Billing calls are
not retried: uncertain authorization/settlement is displayed for administrator
review. Batches with no progress for eight hours are closed when their status is
next read, keeping available results and making at most one settlement attempt.

The six required comparison fields are name, institution, course, qualification,
grade and graduation session/year. Student identifiers are compared only when
both sources show them. Results are Yes, Disparity or Needs review. Names tolerate
order/capitalization/punctuation for comparison, but spelling differences remain
visible. Matching uses a shared student ID or sufficiently strong name, institution,
award and year evidence; grade and course do not gate candidate matching. Conflicting
IDs, weak evidence and competing candidates require review. Explicit equivalence
rules cover standard degree abbreviations, end-of-session graduation years and the
Lagos State Polytechnic / Agricultural Engineering wording in the supplied sample;
other material wording differences are retained for review rather than guessed away.

Downloads include a result CSV and an Excel workbook with Results and Field
comparisons sheets. Unmatched reports are included. Corrections update downloads
and are recorded with the staff member, previous/new report, time and explanation.

### Deployment

Deploy the Laravel changes, built `public/build` assets, `scripts/verification_worker.py`
and `.github/workflows/verify_certificate_report.yml` together. On Hostinger, PHP
must have the `zip` extension enabled and `storage/app/private` must be writable
with enough disk capacity for retained ZIP files. No PHP queue worker or Python
installation is required on Hostinger; extraction runs on GitHub Actions.

From the deployed `rcs-app` directory:

```bash
php artisan migrate --path=database/migrations/2026_11_02_000002_create_verification_tables.php --force
php artisan optimize:clear
php artisan config:cache
```

The existing `GITHUB_PAT`, signed partner billing credentials, and extractor HMAC
secret are required. The application's `CALLBACK_HMAC_SECRET` (or
`EXTRACTOR_CALLBACK_SECRET`) must match the GitHub `CALLBACK_HMAC_SECRET` secret;
GitHub also needs `GEMINI_API_KEY_PAID`. Set `APP_URL` to the actual HTTPS portal
address so signed archive URLs and callbacks reach the deployed application.
`VERIFICATION_GITHUB_REPOSITORY` optionally overrides the existing extraction repo.
A new partner billing operation is identified in `workload_metadata` while keeping
`extraction_type=certificates` and the existing paid-tier authorization contract.

Deploy without changing existing document routes. Validate inventory, a small
paid comparison batch, source access and credit settlement before a 600-document
production batch. Never rerun a failed workflow to reprocess: upload a new batch.

### Verification checks

```bash
cd rcs-app
APP_CONFIG_CACHE=/tmp/rcs-test-config.php APP_ROUTES_CACHE=/tmp/rcs-test-routes.php php vendor/bin/phpunit --filter VerificationTest
```

From the repository root:

```bash
python3 -m unittest discover -s scripts/tests -p test_verification_worker.py -v
```

Tests use synthetic documents and mocked billing/model requests. The supplied ZIP
was additionally checked locally for inventory and native report parsing. A live
Gemini run and production partner billing still require a deployment smoke test.
