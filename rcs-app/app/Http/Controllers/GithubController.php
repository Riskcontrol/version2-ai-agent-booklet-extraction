<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Document;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GithubController extends Controller
{
    private function finalizePartner(Document $doc, string $status, ?int $pagesProcessed = null, ?int $pagesWithResults = null, ?string $failedReason = null): void
    {
        if (!$doc->partner_request_id) {
            return;
        }

        $baseUrl = rtrim((string) config('services.partner.base_url', ''), '/');
        $token = (string) config('services.partner.token', '');
        if ($baseUrl === '' || $token === '') {
            return;
        }

        try {
            $response = Http::withHeaders([
                    'X-Partner-Token' => $token,
                    'Accept' => 'application/json',
                ])
                ->connectTimeout(5)
                ->timeout((int) config('services.partner.timeout', 15))
                ->post($baseUrl . '/api/partner/finalize-extraction', [
                    'partner_request_id' => (string) $doc->partner_request_id,
                    'status' => $status,
                    'pages_processed' => (int) ($pagesProcessed ?? $doc->pages_requested ?? 0),
                    'pages_with_results' => (int) ($pagesWithResults ?? $doc->pages_with_results ?? 0),
                    'failed_reason' => $failedReason,
                ]);

            if ($response->successful()) {
                $payload = (array) $response->json();
                $doc->credits_consumed = (int) ($payload['credits_consumed'] ?? $doc->credits_consumed ?? 0);
                $doc->credits_refunded = (int) ($payload['credits_refunded'] ?? $doc->credits_refunded ?? 0);
                $doc->credit_status = (string) ($payload['status'] ?? $doc->credit_status ?? 'none');
                $doc->save();
            }
        } catch (\Throwable $e) {
            Log::warning('partner finalize exception', ['doc_id' => $doc->id, 'message' => $e->getMessage()]);
        }
    }

    public function callback(Request $req)
    {
        $sig = $req->header('X-Extractor-Signature');
        $secret = (string) config('services.extractor.secret');
        $body = $req->getContent();
        $expected = hash_hmac('sha256', $body, $secret);
        if (!hash_equals($expected, (string)$sig)) {
            Log::warning('extractor callback unauthorized', [
                'has_sig' => !empty($sig),
                'sig_len' => is_string($sig) ? strlen($sig) : 0,
                'secret_set' => $secret !== '',
                'body_len' => is_string($body) ? strlen($body) : 0,
                'ip' => $req->ip(),
                'ua' => substr((string) $req->userAgent(), 0, 120),
            ]);
            abort(401);
        }

        $payload = $req->json()->all();
        $docId = $payload['doc_id'] ?? null;
        $doc = $docId ? Document::find($docId) : Document::where('filename', $payload['filename'] ?? '')->latest()->first();
        if (!$doc) return response()->noContent();

        // Do not overwrite URLs from the upload-results step with runner-local paths like "outputs/*.csv".
        // Only mark status complete here and (optionally) set URLs if they are absolute http(s) links and current fields are empty.
        $doc->status = 'complete';
        $files = $payload['files'] ?? [];
        $csv = $files['csv'] ?? null;
        $xlsx = $files['xlsx'] ?? null;
        if (!$doc->csv_url && is_string($csv) && preg_match('/^https?:\/\//i', $csv)) {
            $doc->csv_url = $csv;
        }
        if (!$doc->xlsx_url && is_string($xlsx) && preg_match('/^https?:\/\//i', $xlsx)) {
            $doc->xlsx_url = $xlsx;
        }
        $doc->save();

        $counts = is_array($payload['counts'] ?? null) ? $payload['counts'] : [];
        $rowsCount = is_array($payload['rows'] ?? null) ? count($payload['rows']) : (int) ($counts['rows'] ?? 0);
        $doc->pages_processed = (int) ($payload['pages_processed'] ?? $counts['pages_processed'] ?? $doc->pages_requested ?? 0);
        $doc->pages_with_results = (int) ($payload['pages_with_results'] ?? $counts['rows'] ?? $rowsCount);
        $doc->result_rows = $rowsCount;
        $doc->save();

        $this->finalizePartner(
            $doc,
            (($payload['status'] ?? 'success') === 'success') ? 'success' : 'failed',
            $doc->pages_processed,
            $doc->pages_with_results,
            (($payload['status'] ?? 'success') === 'success') ? null : 'Callback marked as failed'
        );

        if (!empty($payload['rows']) && is_array($payload['rows'])) {
            if ($doc->extraction_type === 'certificates') {
                foreach ($payload['rows'] as $r) {
                    Certificate::create([
                        'document_id'    => $doc->id,
                        'date_received'  => $doc->date_received,
                        'completed_date' => $doc->completed_date,
                        'client_name'    => $doc->client_name,
                        'name'           => $r['name'] ?? '',
                        'institution'    => $r['institution'] ?? '',
                        'course'         => $r['course'] ?? '',
                        'qualification'  => $r['qualification'] ?? '',
                        'grade'          => $r['grade'] ?? '',
                        'session'        => $r['session'] ?? '',
                        'matric_number'  => $r['matric_number'] ?? '',
                    ]);
                }
            } else {
                foreach ($payload['rows'] as $r) {
                    Student::create([
                        'document_id' => $doc->id,
                        'surname' => $r['surname'] ?? '',
                        'first_name' => $r['first_name'] ?? '',
                        'other_name' => $r['other_name'] ?? '',
                        'course_studied' => $r['course_studied'] ?? null,
                        'faculty' => $r['faculty'] ?? null,
                        'grade' => $r['grade'] ?? null,
                        'qualification_obtained' => $r['qualification_obtained'] ?? null,
                        'session' => $r['session'] ?? null,
                    ]);
                }
            }
        }
        return response()->json(['ok' => true]);
    }

    public function uploadResults(Request $req)
    {
        // Some server/proxy setups do not forward the Authorization header to PHP.
        // Accept either a Bearer token OR an explicit header.
        $auth = $req->bearerToken() ?: $req->header('X-Extractor-Token');
        $expectedToken = (string) config('services.extractor.token');
        if ((string)$auth !== $expectedToken) {
            Log::warning('extractor upload-results unauthorized', [
                'has_bearer' => !empty($req->bearerToken()),
                'has_x_token' => !empty($req->header('X-Extractor-Token')),
                'expected_set' => $expectedToken !== '',
                'ip' => $req->ip(),
                'ua' => substr((string) $req->userAgent(), 0, 120),
            ]);
            abort(401);
        }

        $docId = $req->input('doc_id');
        $doc = Document::find($docId);
        if (!$doc) abort(404);

        $csvFile = $req->file('csv');
        $xlsxFile = $req->file('xlsx');
        $docxFile = $req->file('docx');

        if ($csvFile) {
            $csvPath = $csvFile->store('processed', 'public');
            $doc->csv_url = Storage::disk('public')->url($csvPath);

            $csvRowCount = 0;
            if (($h = fopen(Storage::disk('public')->path($csvPath), 'r')) !== false) {
                $header = fgetcsv($h);
                while (($row = fgetcsv($h)) !== false) {
                    $csvRowCount++;
                    $data = array_combine($header, $row);
                    if ($doc->extraction_type === 'certificates') {
                        Certificate::create([
                            'document_id'    => $doc->id,
                            'date_received'  => $doc->date_received,
                            'completed_date' => $doc->completed_date,
                            'client_name'    => $doc->client_name,
                            'name'           => $data['name'] ?? '',
                            'institution'    => $data['institution'] ?? '',
                            'course'         => $data['course'] ?? '',
                            'qualification'  => $data['qualification'] ?? '',
                            'grade'          => $data['grade'] ?? '',
                            'session'        => $data['session'] ?? '',
                            'matric_number'  => $data['matric_number'] ?? '',
                        ]);
                    } else {
                        Student::create([
                            'document_id' => $doc->id,
                            'surname' => $data['surname'] ?? '',
                            'first_name' => $data['first_name'] ?? '',
                            'other_name' => $data['other_name'] ?? '',
                            'course_studied' => $data['course_studied'] ?? null,
                            'faculty' => $data['faculty'] ?? null,
                            'grade' => $data['grade'] ?? null,
                            'qualification_obtained' => $data['qualification_obtained'] ?? null,
                            'session' => $data['session'] ?? $doc->session,
                        ]);
                    }
                }
                fclose($h);
            }
            $doc->result_rows = $csvRowCount;
        }
        if ($xlsxFile) { $xlsxPath = $xlsxFile->store('processed', 'public'); $doc->xlsx_url = Storage::disk('public')->url($xlsxPath); }
        if ($docxFile) { $docxPath = $docxFile->store('processed', 'public'); $doc->docx_url = Storage::disk('public')->url($docxPath); }

        $summary = json_decode((string) $req->input('summary', '{}'), true);
        $counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];

        $doc->status = 'complete';
        $doc->pages_processed = (int) ($req->input('pages_processed') ?? $counts['pages_processed'] ?? $doc->pages_requested ?? 0);
        $doc->pages_with_results = (int) ($req->input('pages_with_results') ?? $counts['rows'] ?? 0);
        $doc->save();

        $this->finalizePartner($doc, 'success', $doc->pages_processed, $doc->pages_with_results);
        return response()->json(['ok' => true, 'doc' => $doc]);
    }
}
