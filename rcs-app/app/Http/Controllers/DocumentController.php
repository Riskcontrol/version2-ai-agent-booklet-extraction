<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Document;
use App\Models\PartnerAuthorizationDecision;
use App\Models\PartnerAuthorizationRejection;
use App\Models\Student;
use App\Services\PartnerIntegrationModeService;
use App\Services\SignatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DocumentController extends Controller
{
    private function workflowApiTier(array $partner, Request $request, string $extractionType, int $pagesRequested, string $partnerRequestId): string
    {
        $partnerTier = strtolower(trim((string) ($partner['api_tier'] ?? '')));

        if ($partnerTier === 'paid_1') {
            return 'GEMINI_API_KEY_PAID';
        }

        $reason = 'Partner authorization returned an unexpected paid tier.';

        PartnerAuthorizationRejection::create([
            'user_email' => (string) $request->session()->get('user_email', ''),
            'partner_request_id' => $partnerRequestId,
            'partner_name' => 'riskcontrol',
            'partner_domain' => (string) config('app.url'),
            'extraction_type' => $extractionType,
            'pages_requested' => $pagesRequested,
            'returned_api_tier' => $partnerTier !== '' ? $partnerTier : null,
            'reason' => $reason,
            'payload' => $partner,
        ]);

        Log::warning('partner authorization tier rejected', [
            'partner_request_id' => $partnerRequestId,
            'user_email' => (string) $request->session()->get('user_email', ''),
            'extraction_type' => $extractionType,
            'pages_requested' => $pagesRequested,
            'returned_api_tier' => $partnerTier,
        ]);

        throw new HttpException(502, 'Partner authorization returned an unsupported workflow tier.');
    }

    private function detectPdfPageCount(?string $path, ?string &$error = null): int
    {
        $error = null;

        if (!$path || !is_readable($path)) {
            $error = 'upload temp file is not readable';
            return 0;
        }

        // Check PDF magic bytes (%PDF-) — rejects HTML files, images, etc. masquerading as PDFs
        $fh = @fopen($path, 'rb');
        if ($fh !== false) {
            $magic = fread($fh, 5);
            fclose($fh);
            if ($magic !== '%PDF-') {
                $error = 'not_a_pdf';
                return 0;
            }
        }

        if (is_callable('exec')) {
            try {
                $cmd = 'pdfinfo ' . escapeshellarg($path) . ' 2>/dev/null';
                $output = [];
                $exitCode = 1;
                @exec($cmd, $output, $exitCode);

                if ($exitCode === 0) {
                    foreach ($output as $line) {
                        if (preg_match('/^Pages:\s*(\d+)/i', trim((string) $line), $matches) === 1) {
                            $count = (int) ($matches[1] ?? 0);
                            if ($count > 0) return $count;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $error = 'pdfinfo: ' . $e->getMessage();
            }
        }

        try {
            $bytes = @file_get_contents($path);
            if ($bytes !== false) {
                preg_match_all('/\/Type\s*\/Page\b/i', $bytes, $matches);
                $count = count($matches[0] ?? []);
                if ($count > 0) return $count;
            }
        } catch (\Throwable $e) {
            $error = $error ?: ('marker_scan: ' . $e->getMessage());
        }

        return 0;
    }

    private function logPartnerAuthorizationDecision(
        string $partnerRequestId,
        string $userEmail,
        string $extractionType,
        int $pagesRequested,
        string $decision,
        ?int $responseStatus = null,
        ?array $responsePayload = null,
        ?string $errorMessage = null,
    ): void {
        PartnerAuthorizationDecision::create([
            'partner_request_id' => $partnerRequestId,
            'user_email' => $userEmail,
            'extraction_type' => $extractionType,
            'pages_requested' => $pagesRequested,
            'decision' => $decision,
            'enforcement_mode' => PartnerIntegrationModeService::effectiveMode(),
            'hard_blocked' => PartnerIntegrationModeService::shouldHardBlock(),
            'response_status' => $responseStatus,
            'response_payload' => $responsePayload,
            'error_message' => $errorMessage,
        ]);
    }

    private function signedPartnerHeaders(string $method, string $path, array $payload): array
    {
        $token = (string) config('services.partner.token', '');
        $partnerName = (string) config('services.partner.partner_name', 'riskcontrol');
        $secret = (string) config('services.partner.signature_secret', '');

        $body = (string) json_encode($payload);
        $sig = SignatureService::generateSignature($secret, strtoupper($method), $path, $body);

        return [
            'X-Partner-Token' => $token,
            'X-Partner-Name' => $partnerName,
            'X-Partner-Signature' => $sig['signature'],
            'X-Partner-Timestamp' => $sig['timestamp'],
            'X-Partner-Nonce' => $sig['nonce'],
            'X-Signature-Algorithm' => $sig['algorithm'],
            'Idempotency-Key' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ];
    }

    private function authorizePartner(Request $req, int $pagesRequested, string $extractionType, array $workloadMetadata, string $partnerRequestId): array
    {
        $baseUrl = rtrim((string) config('services.partner.base_url', ''), '/');
        $token = (string) config('services.partner.token', '');
        $userEmail = (string) $req->session()->get('user_email', '');

        if ($baseUrl === '' || $token === '' || $userEmail === '') {
            abort(503, 'Partner billing integration is not configured.');
        }

        $requestPayload = [
            'partner_request_id' => $partnerRequestId,
            'user_email' => $userEmail,
            'pages_requested' => $pagesRequested,
            'extraction_type' => $extractionType,
            'partner_name' => 'riskcontrol',
            'partner_domain' => (string) config('app.url'),
            'partner_user_reference' => (string) $req->session()->get('user_name', $userEmail),
            'workload_metadata' => $workloadMetadata,
        ];

        $authPath = '/api/partner/authorize-extraction';

        try {
            $response = Http::withHeaders($this->signedPartnerHeaders('POST', $authPath, $requestPayload))
                ->asJson()
                ->connectTimeout(5)
                ->timeout((int) config('services.partner.timeout', 15))
                ->post($baseUrl . $authPath, $requestPayload);

            $payload = (array) ($response->json() ?? []);
            if ($response->successful()) {
                $this->logPartnerAuthorizationDecision(
                    $partnerRequestId,
                    $userEmail,
                    $extractionType,
                    $pagesRequested,
                    'authorized',
                    $response->status(),
                    $payload,
                    null,
                );

                return $payload;
            }

            $message = $payload['error'] ?? $payload['message'] ?? 'Partner authorization failed.';
            $decision = PartnerIntegrationModeService::shouldHardBlock() ? 'denied' : 'bypassed';
            $this->logPartnerAuthorizationDecision(
                $partnerRequestId,
                $userEmail,
                $extractionType,
                $pagesRequested,
                $decision,
                $response->status(),
                $payload,
                (string) $message,
            );

            if (PartnerIntegrationModeService::shouldHardBlock()) {
                // Use 503 (not the raw Peldarg status) so the frontend never mistakes
                // a billing-system 401/422 for an RCS session expiry.
                abort(503, (string) $message);
            }

            // Shadow mode: keep extraction flow active while preserving decision logs.
            return [
                'api_tier' => 'paid_1',
                'credits_reserved' => 0,
                'credit_balance' => null,
                'shadow_mode' => true,
                'shadow_reason' => (string) $message,
            ];
        } catch (\Throwable $e) {
            $decision = PartnerIntegrationModeService::shouldHardBlock() ? 'error' : 'bypassed';
            $this->logPartnerAuthorizationDecision(
                $partnerRequestId,
                $userEmail,
                $extractionType,
                $pagesRequested,
                $decision,
                null,
                null,
                $e->getMessage(),
            );

            if (PartnerIntegrationModeService::shouldHardBlock()) {
                throw $e;
            }

            Log::warning('partner authorization shadow bypass', [
                'partner_request_id' => $partnerRequestId,
                'user_email' => $userEmail,
                'extraction_type' => $extractionType,
                'pages_requested' => $pagesRequested,
                'error' => $e->getMessage(),
            ]);

            return [
                'api_tier' => 'paid_1',
                'credits_reserved' => 0,
                'credit_balance' => null,
                'shadow_mode' => true,
                'shadow_reason' => $e->getMessage(),
            ];
        }
    }

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

        $finalizePath = '/api/partner/finalize-extraction';
        $finalizePayload = [
            'partner_request_id' => (string) $doc->partner_request_id,
            'status' => $status,
            'pages_processed' => (int) ($pagesProcessed ?? $doc->pages_requested ?? 0),
            'pages_with_results' => (int) ($pagesWithResults ?? $doc->pages_with_results ?? 0),
            'failed_reason' => $failedReason,
        ];

        try {
            $response = Http::withHeaders($this->signedPartnerHeaders('POST', $finalizePath, $finalizePayload))
                ->asJson()
                ->connectTimeout(5)
                ->timeout((int) config('services.partner.timeout', 15))
                ->post($baseUrl . $finalizePath, $finalizePayload);

            if ($response->successful()) {
                $payload = (array) $response->json();
                $doc->credits_consumed = (int) ($payload['credits_consumed'] ?? $doc->credits_consumed ?? 0);
                $doc->credits_refunded = (int) ($payload['credits_refunded'] ?? $doc->credits_refunded ?? 0);
                $doc->credit_status = (string) ($payload['status'] ?? $doc->credit_status ?? 'none');
                $doc->save();
            } else {
                Log::warning('partner finalize failed', ['doc_id' => $doc->id, 'status' => $response->status()]);
            }
        } catch (\Throwable $e) {
            Log::warning('partner finalize exception', ['doc_id' => $doc->id, 'message' => $e->getMessage()]);
        }
    }

    private function applyFilters($query, Request $req, bool $certificates = false)
    {
        $query->when($req->filled('status'), fn ($q) => $q->where('status', $req->input('status')))
            ->when($req->filled('credit_status'), fn ($q) => $q->where('credit_status', $req->input('credit_status')))
            ->when($req->filled('extraction_type'), fn ($q) => $q->where('extraction_type', $req->input('extraction_type')))
            ->when($req->filled('user'), fn ($q) => $q->where('user_email', 'like', '%' . trim((string) $req->input('user')) . '%'))
            ->when($req->filled('request_id'), fn ($q) => $q->where('partner_request_id', 'like', '%' . trim((string) $req->input('request_id')) . '%'))
            ->when($req->filled('payment_reference'), fn ($q) => $q->where('payment_reference', 'like', '%' . trim((string) $req->input('payment_reference')) . '%'))
            ->when($req->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $req->input('date_from')))
            ->when($req->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $req->input('date_to')))
            ->when($req->filled('q'), function ($q) use ($req, $certificates) {
                $term = '%' . trim((string) $req->input('q')) . '%';
                $q->where(function ($sub) use ($term, $certificates) {
                    $sub->where('filename', 'like', $term)
                        ->orWhere('user_email', 'like', $term)
                        ->orWhere('partner_request_id', 'like', $term)
                        ->orWhere('payment_reference', 'like', $term)
                        ->orWhere('failed_reason', 'like', $term);
                    if ($certificates) {
                        $sub->orWhere('client_name', 'like', $term);
                    } else {
                        $sub->orWhere('session', 'like', $term);
                    }
                });
            });

        return $query;
    }

    private function sumResolvedPages($query): int
    {
        return (int) ($query->selectRaw('COALESCE(SUM(COALESCE(NULLIF(pages_processed, 0), pages_requested, 0)), 0) as aggregate')->value('aggregate') ?? 0);
    }

    private function buildSummary(): array
    {
        $today = Carbon::now()->startOfDay();
        $monthStart = Carbon::now()->startOfMonth();

        $convocationBase = Document::query()
            ->where(function ($query) {
                $query->whereNull('extraction_type')->orWhere('extraction_type', 'convocation');
            })
            ->where('status', 'complete');

        $certificateBase = Document::query()
            ->where('extraction_type', 'certificates')
            ->where('status', 'complete');

        return [
            'booklet_successful' => [
                'day' => (int) (clone $convocationBase)->where('created_at', '>=', $today)->count(),
                'month' => (int) (clone $convocationBase)->where('created_at', '>=', $monthStart)->count(),
                'all' => (int) (clone $convocationBase)->count(),
            ],
            'booklet_pages' => [
                'day' => $this->sumResolvedPages((clone $convocationBase)->where('created_at', '>=', $today)),
                'month' => $this->sumResolvedPages((clone $convocationBase)->where('created_at', '>=', $monthStart)),
                'all' => $this->sumResolvedPages(clone $convocationBase),
            ],
            'booklet_student_rows' => [
                'day' => (int) (clone $convocationBase)->where('created_at', '>=', $today)->sum('result_rows'),
                'month' => (int) (clone $convocationBase)->where('created_at', '>=', $monthStart)->sum('result_rows'),
                'all' => (int) (clone $convocationBase)->sum('result_rows'),
            ],
            'certificate_pages' => [
                'day' => $this->sumResolvedPages((clone $certificateBase)->where('created_at', '>=', $today)),
                'month' => $this->sumResolvedPages((clone $certificateBase)->where('created_at', '>=', $monthStart)),
                'all' => $this->sumResolvedPages(clone $certificateBase),
            ],
        ];
    }

    public function upload(Request $req)
    {
        $req->validate([
            'file' => 'required|mimes:pdf|max:102400',
            'session' => 'nullable|string',
            'start_page' => 'nullable|integer|min:1',
            'end_page' => 'nullable|integer|min:1|gte:start_page',
        ]);
        $file = $req->file('file');
        $pageCountError = null;
        $totalPages = $this->detectPdfPageCount($file->getRealPath(), $pageCountError);
        if ($totalPages < 1) {
            $msg = $pageCountError === 'not_a_pdf'
                ? 'The uploaded file is not a valid PDF. Please ensure you are uploading an actual PDF document (not an HTML page, image, or renamed file).'
                : 'Unable to read PDF page count. Please re-export the PDF and try again.';
            abort(422, $msg);
        }

        $startPage = $req->filled('start_page') ? (int) $req->input('start_page') : 1;
        $endPage = $req->filled('end_page') ? (int) $req->input('end_page') : $totalPages;
        $pagesRequested = max(1, ($endPage - $startPage) + 1);
        $partnerRequestId = (string) \Illuminate\Support\Str::uuid();

        $path = $file->store('convocation', 'public');

        try {
            $partner = $this->authorizePartner(
                $req,
                $pagesRequested,
                'convocation',
                [
                    'page_start' => $startPage,
                    'page_end' => $endPage,
                    'session' => (string) ($req->input('session') ?? ''),
                ],
                $partnerRequestId,
            );
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);
            throw $e;
        }

        try {
            $workflowApiTier = $this->workflowApiTier($partner, $req, 'convocation', $pagesRequested, $partnerRequestId);
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);
            throw $e;
        }

        $shadowMode = (bool) ($partner['shadow_mode'] ?? false);

        $doc = Document::create([
            'filename' => $file->getClientOriginalName(),
            'user_email' => (string) $req->session()->get('user_email', ''),
            'path' => $path,
            'session' => $req->input('session'),
            'status' => 'processing',
            'partner_request_id' => $shadowMode ? null : $partnerRequestId,
            'payment_reference' => (($partner['payment_reference'] ?? null) !== null && trim((string) $partner['payment_reference']) !== '') ? trim((string) $partner['payment_reference']) : null,
            'api_key_tier' => $workflowApiTier,
            'page_start' => $startPage,
            'page_end' => $endPage,
            'pages_requested' => $pagesRequested,
            'credits_reserved' => (int) ($partner['credits_reserved'] ?? 0),
            'credit_status' => $shadowMode ? 'shadow_authorized' : 'authorized',
        ]);

    // Extend expiry to 24h to accommodate long/parallel processing in CI
    $sourceUrl = URL::temporarySignedRoute('documents.download', now()->addHours(24), ['doc' => $doc->id]);

        $pat = config('services.github.pat');
        if (!empty($pat)) {
            try {
                $payload = [
                    'source_url' => $sourceUrl,
                    'original_filename' => $file->getClientOriginalName(),
                    'session' => $doc->session,
                    'callback_url' => url(route('github.callback', [], false)),
                    'result_upload_url' => url(route('github.uploadResults', [], false)),
                    'doc_id' => (string)$doc->id,
                    'api_key_tier' => $workflowApiTier,
                    'partner_request_id' => $shadowMode ? null : $partnerRequestId,
                ];
                if ($req->filled('start_page')) {
                    $payload['page_start'] = (int)$req->input('start_page');
                }
                if ($req->filled('end_page')) {
                    $payload['page_end'] = (int)$req->input('end_page');
                }
                Http::withToken($pat)
                    ->post('https://api.github.com/repos/Riskcontrol/version2-ai-agent-booklet-extraction/dispatches', [
                        'event_type' => 'process_pdf',
                        'client_payload' => $payload
                    ])
                    ->throw();
            } catch (\Throwable $e) {
                $doc->status = 'failed';
                $doc->failed_reason = 'Workflow dispatch failed';
                $doc->save();
                $this->finalizePartner($doc, 'failed', 0, 0, 'Workflow dispatch failed');
                throw $e;
            }
        }

        return response()->json([
            'id' => $doc->id,
            'status' => 'processing',
            'credit_balance' => (int) ($partner['credit_balance'] ?? 0),
            'integration_mode' => PartnerIntegrationModeService::effectiveMode(),
            'shadow_mode' => (bool) ($partner['shadow_mode'] ?? false),
        ]);
    }

    public function download(Request $req, Document $doc)
    {
        if (!$req->hasValidSignature()) abort(401);
        $full = Storage::disk('public')->path($doc->path);
        if (!file_exists($full)) abort(404);
        return response()->file($full, ['Content-Type' => 'application/pdf']);
    }

    public function downloadOutput(Request $req, Document $doc, string $type)
    {
        if (!$req->hasValidSignature()) abort(401);
        $url = $type === 'csv' ? $doc->csv_url : $doc->xlsx_url;
        if (!$url) abort(404);
        // Convert public URL back to storage path
        $publicPrefix = Storage::disk('public')->url('');
        if (!str_starts_with($url, $publicPrefix)) abort(404);
        $rel = ltrim(substr($url, strlen($publicPrefix)), '/');
        $full = Storage::disk('public')->path($rel);
        if (!file_exists($full)) abort(404);
        $filename = $doc->filename;
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $downloadName = $base . '.' . $type;
        $mime = $type === 'csv' ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        return response()->download($full, $downloadName, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function index()
    {
        $docs = $this->applyFilters(Document::query()->where(function ($q) {
            $q->whereNull('extraction_type')->orWhere('extraction_type', 'convocation');
        }), request())->latest()->get();
        // Attach signed download links for CSV/XLSX if present
        $docs->transform(function($d){
            $d->csv_download = $d->csv_url ? URL::temporarySignedRoute('documents.downloadOutput', now()->addHours(12), ['doc' => $d->id, 'type' => 'csv']) : null;
            $d->xlsx_download = $d->xlsx_url ? URL::temporarySignedRoute('documents.downloadOutput', now()->addHours(12), ['doc' => $d->id, 'type' => 'xlsx']) : null;
            return $d;
        });
        return [
            'documents' => $docs,
            'summary' => array_merge($this->buildSummary(), [
                'filtered_documents' => (int) $docs->count(),
                'filtered_pages' => (int) $docs->sum(fn ($doc) => (int) ($doc->pages_processed ?: $doc->pages_requested ?: 0)),
            ]),
        ];
    }

    public function delete(Request $req, Document $doc)
    {
        // Delete associated students
        Student::where('document_id', $doc->id)->delete();
        
        // Delete PDF file from storage
        if ($doc->path) {
            Storage::disk('public')->delete($doc->path);
        }
        
        // Delete CSV/XLSX files if they exist
        if ($doc->csv_url) {
            $publicPrefix = Storage::disk('public')->url('');
            if (str_starts_with($doc->csv_url, $publicPrefix)) {
                $rel = ltrim(substr($doc->csv_url, strlen($publicPrefix)), '/');
                Storage::disk('public')->delete($rel);
            }
        }
        if ($doc->xlsx_url) {
            $publicPrefix = Storage::disk('public')->url('');
            if (str_starts_with($doc->xlsx_url, $publicPrefix)) {
                $rel = ltrim(substr($doc->xlsx_url, strlen($publicPrefix)), '/');
                Storage::disk('public')->delete($rel);
            }
        }
        
        // Delete document record
        $doc->delete();
        
        return response()->json(['deleted' => true]);
    }

    // -----------------------------------------------------------------------
    // Certificates
    // -----------------------------------------------------------------------

    public function uploadCertificates(Request $req)
    {
        $req->validate([
            'file'           => 'required|mimes:pdf|max:102400',
            'date_received'  => 'nullable|string|max:100',
            'completed_date' => 'nullable|string|max:100',
            'client_name'    => 'nullable|string|max:255',
        ]);

        $file = $req->file('file');
        $pageCountError = null;
        $totalPages = $this->detectPdfPageCount($file->getRealPath(), $pageCountError);
        if ($totalPages < 1) {
            $msg = $pageCountError === 'not_a_pdf'
                ? 'The uploaded file is not a valid PDF. Please ensure you are uploading an actual PDF document (not an HTML page, image, or renamed file).'
                : 'Unable to read PDF page count. Please re-export the PDF and try again.';
            abort(422, $msg);
        }

        $partnerRequestId = (string) \Illuminate\Support\Str::uuid();
        $path = $file->store('certificates', 'public');

        try {
            $partner = $this->authorizePartner(
                $req,
                $totalPages,
                'certificates',
                [
                    'date_received' => (string) ($req->input('date_received') ?? ''),
                    'completed_date' => (string) ($req->input('completed_date') ?? ''),
                    'client_name' => (string) ($req->input('client_name') ?? ''),
                    'page_start' => 1,
                    'page_end' => $totalPages,
                ],
                $partnerRequestId,
            );
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);
            throw $e;
        }

        try {
            $workflowApiTier = $this->workflowApiTier($partner, $req, 'certificates', $totalPages, $partnerRequestId);
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);
            throw $e;
        }

        $shadowMode = (bool) ($partner['shadow_mode'] ?? false);

        $doc = Document::create([
            'filename'       => $file->getClientOriginalName(),
            'user_email'     => (string) $req->session()->get('user_email', ''),
            'path'           => $path,
            'status'         => 'processing',
            'extraction_type'=> 'certificates',
            'date_received'  => $req->input('date_received'),
            'completed_date' => $req->input('completed_date'),
            'client_name'    => $req->input('client_name'),
            'partner_request_id' => $shadowMode ? null : $partnerRequestId,
            'payment_reference' => (($partner['payment_reference'] ?? null) !== null && trim((string) $partner['payment_reference']) !== '') ? trim((string) $partner['payment_reference']) : null,
            'api_key_tier' => $workflowApiTier,
            'page_start' => 1,
            'page_end' => $totalPages,
            'pages_requested' => $totalPages,
            'credits_reserved' => (int) ($partner['credits_reserved'] ?? 0),
            'credit_status' => $shadowMode ? 'shadow_authorized' : 'authorized',
        ]);

        $sourceUrl = URL::temporarySignedRoute('documents.download', now()->addHours(24), ['doc' => $doc->id]);

        $pat = config('services.github.pat');
        if (!empty($pat)) {
            try {
                $payload = [
                    'source_url'          => $sourceUrl,
                    'original_filename'   => $file->getClientOriginalName(),
                    'callback_url'        => url(route('github.callback', [], false)),
                    'result_upload_url'   => url(route('github.uploadResults', [], false)),
                    'doc_id'              => (string)$doc->id,
                    'api_key_tier'        => $workflowApiTier,
                    'date_received'       => $doc->date_received ?? '',
                    'completed_date'      => $doc->completed_date ?? '',
                    'client_name'         => $doc->client_name ?? '',
                    'partner_request_id'  => $shadowMode ? null : $partnerRequestId,
                ];
                Http::withToken($pat)
                    ->post('https://api.github.com/repos/Riskcontrol/version2-ai-agent-booklet-extraction/dispatches', [
                        'event_type'     => 'process_certificates',
                        'client_payload' => $payload,
                    ])
                    ->throw();
            } catch (\Throwable $e) {
                $doc->status = 'failed';
                $doc->failed_reason = 'Workflow dispatch failed';
                $doc->save();
                $this->finalizePartner($doc, 'failed', 0, 0, 'Workflow dispatch failed');
                throw $e;
            }
        }

        return response()->json([
            'id' => $doc->id,
            'status' => 'processing',
            'credit_balance' => (int) ($partner['credit_balance'] ?? 0),
            'integration_mode' => PartnerIntegrationModeService::effectiveMode(),
            'shadow_mode' => (bool) ($partner['shadow_mode'] ?? false),
        ]);
    }

    public function indexCertificates()
    {
        $docs = $this->applyFilters(Document::query()->where('extraction_type', 'certificates'), request(), true)->latest()->get();
        $docs->transform(function ($d) {
            $d->csv_download  = $d->csv_url  ? URL::temporarySignedRoute('documents.downloadOutput', now()->addHours(12), ['doc' => $d->id, 'type' => 'csv'])  : null;
            $d->xlsx_download = $d->xlsx_url ? URL::temporarySignedRoute('documents.downloadOutput', now()->addHours(12), ['doc' => $d->id, 'type' => 'xlsx']) : null;
            return $d;
        });
        return [
            'documents' => $docs,
            'summary' => array_merge($this->buildSummary(), [
                'filtered_documents' => (int) $docs->count(),
                'filtered_pages' => (int) $docs->sum(fn ($doc) => (int) ($doc->pages_processed ?: $doc->pages_requested ?: 0)),
            ]),
        ];
    }

    public function deleteCertificate(Request $req, Document $doc)
    {
        // Delete extracted certificate rows
        Certificate::where('document_id', $doc->id)->delete();

        // Delete PDF
        if ($doc->path) {
            Storage::disk('public')->delete($doc->path);
        }

        // Delete output files
        $publicPrefix = Storage::disk('public')->url('');
        foreach (['csv_url', 'xlsx_url'] as $field) {
            if ($doc->$field && str_starts_with($doc->$field, $publicPrefix)) {
                $rel = ltrim(substr($doc->$field, strlen($publicPrefix)), '/');
                Storage::disk('public')->delete($rel);
            }
        }

        $doc->delete();
        return response()->json(['deleted' => true]);
    }
}
