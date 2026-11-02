<?php

namespace App\Http\Controllers;

use App\Models\VerificationBatch;
use App\Models\VerificationRecord;
use App\Models\VerificationResult;
use App\Services\VerificationBillingService;
use App\Services\VerificationComparisonService;
use App\Services\VerificationExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use ZipArchive;

class VerificationController extends Controller
{
    private function owner(Request $request, VerificationBatch $batch): void
    {
        abort_unless($batch->user_email === (string) $request->session()->get('user_email'), 404);
    }

    private function dispatch(VerificationBatch $batch, string $phase): void
    {
        $pat = (string) config('services.github.pat');
        abort_if($pat === '', 503, 'GitHub processing is not configured.');
        Http::withToken($pat)->connectTimeout(5)->timeout(20)->post(
            'https://api.github.com/repos/'.config('verification.github_repository').'/dispatches',
            ['event_type' => 'verify_certificate_report', 'client_payload' => [
                'batch_id' => $batch->id, 'phase' => $phase,
                'source_url' => URL::temporarySignedRoute('verification.archive', now()->addHours(24), ['batch' => $batch->id]),
                'callback_url' => route('verification.callback', ['batch' => $batch->id]), 'api_key_tier' => $batch->api_key_tier,
            ]]
        )->throw();
    }

    public function index(Request $request)
    {
        return VerificationBatch::where('user_email', $request->session()->get('user_email'))
            ->select(['id', 'filename', 'status', 'size', 'pages_requested', 'pages_processed', 'created_at'])
            ->latest()->paginate(15);
    }

    public function create(Request $request)
    {
        abort_unless(class_exists(ZipArchive::class), 503, 'ZIP support is not enabled on this server. Contact your administrator.');
        $data = $request->validate(['filename' => 'required|string|max:255',
            'size' => 'required|integer|min:1|max:'.config('verification.max_archive_bytes')]);
        abort_unless(str_ends_with(strtolower($data['filename']), '.zip'), 422, 'Upload a ZIP file.');
        $id = (string) Str::uuid();
        Storage::disk('local')->makeDirectory('verifications/'.$id);
        $batch = VerificationBatch::create(['id' => $id, 'user_email' => $request->session()->get('user_email'),
            'filename' => basename(str_replace('\\', '/', $data['filename'])), 'size' => $data['size'],
            'archive_path' => 'verifications/'.$id.'/source.zip']);
        return response()->json(['batch' => $batch, 'chunk_bytes' => config('verification.chunk_bytes')], 201);
    }

    public function chunk(Request $request, VerificationBatch $batch)
    {
        $this->owner($request, $batch);
        try {
            DB::transaction(function () use ($request, $batch) {
                $locked = VerificationBatch::lockForUpdate()->findOrFail($batch->id);
                abort_unless($locked->status === 'uploading', 409, 'This upload is closed. Upload the ZIP again as a new batch.');
                abort_if($locked->created_at->lt(now()->subDay()), 409, 'Upload expired. Upload the ZIP again.');
                $body = $request->getContent();
                $offset = $request->header('X-Upload-Offset');
                abort_unless(is_string($offset) && ctype_digit($offset) && (int) $offset === $locked->bytes_received, 409, 'Out-of-order chunk. Upload the ZIP again.');
                abort_unless(strlen($body) > 0 && strlen($body) <= config('verification.chunk_bytes')
                    && $locked->bytes_received + strlen($body) <= $locked->size, 422, 'Invalid upload chunk.');
                $path = Storage::disk('local')->path($locked->archive_path);
                clearstatcache(true, $path);
                abort_unless((is_file($path) ? filesize($path) : 0) === $locked->bytes_received, 409, 'Upload interrupted. Upload the ZIP again.');
                $written = file_put_contents($path, $body, FILE_APPEND | LOCK_EX);
                abort_unless($written === strlen($body), 500, 'Could not store upload chunk.');
                $locked->increment('bytes_received', $written);
            });
        } catch (\Throwable $e) {
            VerificationBatch::whereKey($batch->id)->where('status', 'uploading')
                ->update(['status' => 'upload_failed', 'failed_reason' => 'Upload interrupted or invalid. Start a new upload.']);
            throw $e;
        }
        return ['bytes_received' => $batch->fresh()->bytes_received];
    }

    public function finishUpload(Request $request, VerificationBatch $batch)
    {
        $this->owner($request, $batch);
        DB::transaction(function () use ($batch) {
            $locked = VerificationBatch::lockForUpdate()->findOrFail($batch->id);
            abort_unless($locked->status === 'uploading', 409, 'This upload cannot be restarted.');
            if ($locked->bytes_received !== $locked->size) {
                $locked->update(['status' => 'upload_failed', 'failed_reason' => 'Incomplete ZIP. Upload again.']);
                return;
            }
            $locked->update(['status' => 'inventory_queued']);
        });
        abort_if($batch->fresh()->status === 'upload_failed', 422, 'Incomplete ZIP. Upload again.');
        try {
            $this->dispatch($batch, 'inventory');
        } catch (\Throwable $e) {
            $batch->update(['status' => 'failed', 'failed_reason' => 'Could not start archive inventory. Upload again.']);
            throw $e;
        }
        return $batch->fresh();
    }

    public function show(Request $request, VerificationBatch $batch, VerificationComparisonService $comparison, VerificationBillingService $billing)
    {
        $this->owner($request, $batch);
        // A killed runner may be unable to deliver its failure notice. Closing
        // a stale batch never restarts extraction or resends a billing request.
        $expired = false;
        DB::transaction(function () use ($batch, $comparison, &$expired) {
            $locked = VerificationBatch::lockForUpdate()->findOrFail($batch->id);
            if (in_array($locked->status, ['inventory_queued', 'inventory', 'authorizing', 'queued', 'processing'], true)
                && $locked->updated_at->lt(now()->subHours(8))) {
                $wasExtraction = in_array($locked->status, ['queued', 'processing'], true);
                if ($locked->credit_status === 'authorizing') {
                    $locked->credit_status = 'authorization_failed';
                }
                $locked->update(['status' => 'failed', 'failed_reason' => 'Processing stopped responding. Completed records are retained. Upload again to process again.']);
                if ($wasExtraction) {
                    $comparison->build($locked);
                }
                $expired = true;
            }
        });
        if ($expired) {
            $billing->settle($batch->fresh());
        }
        $batch->refresh();
        $summary = VerificationResult::where('batch_id', $batch->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $used = VerificationResult::where('batch_id', $batch->id)->whereNotNull('report_id')->select('report_id');
        return ['batch' => $batch, 'summary' => $summary,
            'records_extracted' => VerificationRecord::where('batch_id', $batch->id)->count(),
            'unmatched_reports' => VerificationRecord::where('batch_id', $batch->id)->where('role', 'report')->whereNotIn('id', $used)->count()];
    }

    public function start(Request $request, VerificationBatch $batch, VerificationBillingService $billing)
    {
        $this->owner($request, $batch);
        $request->validate(['confirm_credits' => 'required|integer|min:1']);
        abort_unless((int) $request->input('confirm_credits') === $batch->pages_requested, 422, 'Confirm the current page count.');
        abort_unless(VerificationBatch::whereKey($batch->id)->where('status', 'ready')->update(['status' => 'authorizing']), 409, 'This batch has already started or failed. Reupload to process again.');
        $batch->refresh();
        try {
            $billing->authorize($batch, (string) $request->session()->get('user_name', $batch->user_email));
            $batch->update(['status' => 'queued', 'worker_run' => null]);
            $this->dispatch($batch, 'extract');
        } catch (\Throwable $e) {
            $batch->refresh();
            $batch->update(['status' => 'failed', 'failed_reason' => 'Authorization or workflow dispatch failed. Reupload to process again.']);
            $billing->settle($batch);
            if ($batch->credit_status === 'authorizing') {
                $batch->update(['credit_status' => 'authorization_failed']);
            }
            abort(503, $e->getMessage());
        }
        return $batch->fresh();
    }

    public function archive(Request $request, VerificationBatch $batch)
    {
        abort_unless(in_array($batch->status, ['inventory_queued', 'inventory', 'ready', 'queued', 'processing'], true), 409, 'This batch is closed.');
        return Storage::disk('local')->download($batch->archive_path, 'source.zip');
    }

    public function source(Request $request, VerificationBatch $batch, string $source)
    {
        $this->owner($request, $batch);
        $item = collect($batch->inventory ?? [])->firstWhere('id', $source);
        abort_unless($item, 404);
        $zip = new ZipArchive;
        abort_unless($zip->open(Storage::disk('local')->path($batch->archive_path)) === true, 404);
        $stream = $zip->getStream($item['filename']);
        abort_unless(is_resource($stream), 404);
        return response()->stream(function () use ($stream, $zip) {
            fpassthru($stream);
            fclose($stream);
            $zip->close();
        }, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="source.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN']);
    }

    public function results(Request $request, VerificationBatch $batch)
    {
        $this->owner($request, $batch);
        $request->validate(['q' => 'sometimes|nullable|string|max:255', 'status' => 'sometimes|nullable|in:Yes,Disparity,Needs review']);
        return VerificationResult::where('batch_id', $batch->id)->with(['certificate', 'report'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('q'), fn ($q) => $q->whereHas('certificate', fn ($r) => $r->where(fn ($search) => $search
                ->where('fields->name', 'like', '%'.$request->input('q').'%')->orWhere('filename', 'like', '%'.$request->input('q').'%'))))
            ->orderBy('id')->paginate(25);
    }

    public function records(Request $request, VerificationBatch $batch)
    {
        $this->owner($request, $batch);
        $request->validate(['q' => 'sometimes|nullable|string|max:255', 'ids' => 'sometimes|string|max:100']);
        $used = VerificationResult::where('batch_id', $batch->id)->whereNotNull('report_id')->select('report_id');
        return VerificationRecord::where('batch_id', $batch->id)->where('role', 'report')
            ->when($request->filled('ids'), fn ($q) => $q->whereIn('id', array_slice(array_filter(explode(',', (string) $request->input('ids')), 'ctype_digit'), 0, 5)))
            ->when($request->boolean('unmatched'), fn ($q) => $q->whereNotIn('id', $used))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($r) => $r->where('fields->name', 'like', '%'.$request->input('q').'%')
                ->orWhere('filename', 'like', '%'.$request->input('q').'%')))->orderBy('id')->paginate(25);
    }

    public function pairing(Request $request, VerificationBatch $batch, VerificationResult $result, VerificationComparisonService $comparison)
    {
        $this->owner($request, $batch);
        abort_unless($result->batch_id === $batch->id, 404);
        $data = $request->validate(['report_id' => 'present|nullable|integer', 'revision' => 'required|integer', 'note' => 'required|string|max:2000']);
        DB::transaction(function () use ($batch, $result, $data, $comparison, $request) {
            $locked = VerificationBatch::lockForUpdate()->findOrFail($batch->id);
            abort_unless(in_array($locked->status, ['complete', 'failed'], true), 409, 'Wait for processing to finish.');
            abort_unless($locked->revision === $data['revision'], 409, 'Results changed. Refresh before correcting a pairing.');
            $report = $data['report_id'] === null ? null : VerificationRecord::where('batch_id', $batch->id)->where('role', 'report')->findOrFail($data['report_id']);
            abort_if($report && VerificationResult::where('report_id', $report->id)->where('id', '!=', $result->id)->exists(), 409, 'That report is already paired. Unpair it from the other certificate first.');
            DB::table('verification_pairing_audits')->insert(['batch_id' => $batch->id, 'certificate_id' => $result->certificate_id,
                'previous_report_id' => $result->report_id, 'report_id' => $report?->id,
                'user_email' => $request->session()->get('user_email'), 'note' => $data['note'], 'created_at' => now()]);
            $result->update(['report_id' => $report?->id, 'manual' => true,
                ...$comparison->compare($result->certificate->fields, $report?->fields)]);
            $locked->increment('revision');
        });
        return $result->fresh()->load(['certificate', 'report']);
    }

    public function audits(Request $request, VerificationBatch $batch)
    {
        $this->owner($request, $batch);
        return DB::table('verification_pairing_audits')->where('batch_id', $batch->id)->orderByDesc('id')->paginate(25);
    }

    public function export(Request $request, VerificationBatch $batch, string $format, VerificationExportService $export)
    {
        $this->owner($request, $batch);
        abort_unless(in_array($batch->status, ['complete', 'failed'], true), 409, 'Processing is not finished.');
        return $export->download($batch, $format);
    }
}
