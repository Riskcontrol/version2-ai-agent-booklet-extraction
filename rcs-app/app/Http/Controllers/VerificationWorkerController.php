<?php

namespace App\Http\Controllers;

use App\Models\VerificationBatch;
use App\Models\VerificationRecord;
use App\Services\VerificationBillingService;
use App\Services\VerificationComparisonService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VerificationWorkerController extends Controller
{
    public function callback(Request $request, VerificationBatch $batch, VerificationComparisonService $comparison, VerificationBillingService $billing)
    {
        $secret = (string) config('services.extractor.secret');
        $timestamp = (string) $request->header('X-Verification-Timestamp');
        abort_unless($secret !== '' && ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300, 401);
        $expected = hash_hmac('sha256', $timestamp."\n".$request->getContent(), $secret);
        abort_unless(hash_equals($expected, (string) $request->header('X-Extractor-Signature')), 401);
        abort_if(strlen($request->getContent()) > 8 * 1024 * 1024, 413);
        $data = $request->validate(['kind' => 'required|in:started,inventory,page,file,done,failed',
            'phase' => 'required|in:inventory,extract', 'run' => 'required|string|max:100', 'event_key' => 'required|string|max:100']);
        $terminal = false;
        DB::transaction(function () use ($request, $batch, $data, $comparison, &$terminal) {
            $locked = VerificationBatch::lockForUpdate()->findOrFail($batch->id);
            if (DB::table('verification_events')->where('batch_id', $batch->id)->where('event_key', $data['event_key'])->exists()) {
                abort_if($data['kind'] === 'started', 409, 'This worker has already claimed the batch. Reupload to process again.');
                // A duplicate delivery is acknowledged, never reprocessed or recharged.
                return;
            }
            $queued = $data['phase'] === 'inventory' ? 'inventory_queued' : 'queued';
            $active = $data['phase'] === 'inventory' ? 'inventory' : 'processing';
            if ($data['kind'] === 'started') {
                abort_unless($locked->status === $queued && !$locked->worker_run, 409, 'This batch cannot run again. Reupload to process again.');
                $locked->update(['worker_run' => $data['run'], 'status' => $active]);
            } else {
                // Failure before Python starts (install/checkout failure) can close a queued batch.
                $unclaimedFailure = $data['kind'] === 'failed' && $locked->status === $queued && !$locked->worker_run;
                abort_unless($unclaimedFailure || ($locked->status === $active && $locked->worker_run === $data['run']), 409, 'Worker is not active for this batch.');
                if ($data['kind'] === 'inventory') {
                    abort_unless($data['phase'] === 'inventory', 422);
                    $request->validate(['inventory' => 'required|array|min:1|max:'.config('verification.max_pdf_files'),
                        'inventory.*.id' => 'required|string|size:64|distinct', 'inventory.*.filename' => 'required|string|max:2000|distinct',
                        'inventory.*.pages' => 'required|integer|min:1|max:'.config('verification.max_pages'),
                        'inventory.*.sha256' => 'required|string|size:64', 'inventory.*.bytes' => 'required|integer|min:1',
                        'notices' => 'present|array|max:2000', 'notices.*' => 'string|max:4000']);
                    $inventory = $request->input('inventory');
                    $pages = array_sum(array_column($inventory, 'pages'));
                    abort_if($pages > config('verification.max_pages') || array_sum(array_column($inventory, 'bytes')) > config('verification.max_expanded_bytes'), 422, 'Archive exceeds configured limits.');
                    foreach ($inventory as $item) {
                        $path = str_replace('\\', '/', $item['filename']);
                        abort_if(str_starts_with($path, '/') || preg_match('~(^|/)\.\.(/|$)~', $path)
                            || !str_ends_with(strtolower($path), '.pdf') || hash('sha256', $item['filename']) !== $item['id'], 422, 'Invalid archive path.');
                    }
                    $locked->update(['inventory' => $inventory, 'notices' => $request->input('notices'),
                        'pages_requested' => $pages, 'status' => 'ready', 'worker_run' => null]);
                } elseif (in_array($data['kind'], ['page', 'file'], true)) {
                    abort_unless($data['phase'] === 'extract', 422);
                    if ($data['kind'] === 'file' && $request->boolean('incremental')) {
                        $source = (string) $request->input('source_id');
                        $item = collect($locked->inventory)->firstWhere('id', $source);
                        abort_unless($item && $data['event_key'] === 'file:'.$source, 422);
                        $pageEvents = DB::table('verification_events')->where('batch_id', $batch->id)
                            ->where('event_key', 'like', 'page:'.$source.':%')->count();
                        abort_unless($pageEvents === $item['pages'], 422, 'Not all PDF pages were delivered.');
                        $locked->increment('files_processed');
                    } else {
                        $isPage = $data['kind'] === 'page';
                        if ($isPage) {
                            $request->validate(['page' => 'required|integer|min:1']);
                        }
                        $request->validate(['source_id' => 'required|string|size:64', 'pages_processed' => 'required|integer|min:0',
                            'pages_with_results' => 'required|integer|min:0', 'records' => 'present|array|max:10000',
                            'records.*.role' => 'required|in:certificate,report', 'records.*.page' => 'required|integer|min:1',
                            'records.*.fields' => 'required|array', 'records.*.fields.*' => 'nullable|string|max:4000',
                            'records.*.evidence' => 'sometimes|array', 'records.*.evidence.*' => 'nullable|string|max:4000',
                            'errors' => 'present|array|max:10000', 'errors.*.page' => 'required|integer|min:1', 'errors.*.message' => 'required|string|max:1000']);
                        $item = collect($locked->inventory)->firstWhere('id', $request->input('source_id'));
                        $expectedKey = $isPage ? 'page:'.($item['id'] ?? '').':'.$request->input('page') : 'file:'.($item['id'] ?? '');
                        abort_unless($item && $data['event_key'] === $expectedKey, 422, 'Unknown PDF source.');
                        if ($isPage) {
                            abort_if(DB::table('verification_events')->where('batch_id', $batch->id)->where('event_key', 'file:'.$item['id'])->exists(), 409, 'This PDF is already closed.');
                            abort_if((int) $request->input('page') > $item['pages'], 422);
                        } else {
                            abort_if(DB::table('verification_events')->where('batch_id', $batch->id)
                                ->where('event_key', 'like', 'page:'.$item['id'].':%')->exists(), 409, 'Cannot deliver a PDF twice.');
                        }
                        $processed = (int) $request->input('pages_processed');
                        $withResults = (int) $request->input('pages_with_results');
                        abort_unless($processed + count($request->input('errors')) === ($isPage ? 1 : $item['pages']) && $withResults <= $processed, 422, 'Invalid processed page counts.');
                        $failedPages = array_column($request->input('errors'), 'page');
                        abort_unless(count(array_unique($failedPages)) === count($failedPages), 422);
                        foreach ($failedPages as $page) {
                            abort_if($page < 1 || $page > $item['pages'] || ($isPage && $page !== (int) $request->input('page')), 422);
                        }
                        foreach ($request->input('records') as $index => $record) {
                            abort_if($record['page'] > $item['pages'] || in_array($record['page'], $failedPages, true) || ($isPage && $record['page'] !== (int) $request->input('page')), 422);
                            $fields = array_fill_keys([...VerificationComparisonService::FIELDS, 'matric_number', 'specialization'], '');
                            foreach ($fields as $key => $_) {
                                $fields[$key] = trim((string) ($record['fields'][$key] ?? ''));
                            }
                            VerificationRecord::create(['batch_id' => $batch->id, 'record_key' => $item['id'].':'.($isPage ? $request->input('page').':' : '').$index,
                                'role' => $record['role'], 'source_id' => $item['id'], 'filename' => $item['filename'],
                                'page' => $record['page'], 'fields' => $fields, 'evidence' => $record['evidence'] ?? []]);
                        }
                        abort_unless(count(array_unique(array_column($request->input('records'), 'page'))) === $withResults, 422, 'Result-page count does not match extracted records.');
                        $notices = $locked->notices ?? [];
                        foreach ($request->input('errors') as $error) {
                            if (count($notices) < 2000) {
                                $notices[] = $item['filename'].' page '.$error['page'].': '.$error['message'];
                            }
                        }
                        $locked->update(['pages_processed' => $locked->pages_processed + $processed,
                            'pages_with_results' => $locked->pages_with_results + $withResults,
                            'files_processed' => $locked->files_processed + ($isPage ? 0 : 1), 'notices' => $notices]);
                    }
                } elseif (in_array($data['kind'], ['done', 'failed'], true)) {
                    $complete = $data['kind'] === 'done' && $data['phase'] === 'extract'
                        && $locked->files_processed === count($locked->inventory ?? [])
                        && $locked->pages_processed === $locked->pages_requested;
                    $reason = $complete ? null : substr((string) $request->input('reason', 'Some pages were not processed. Reupload to process them again.'), 0, 2000);
                    $locked->update(['status' => $complete ? 'complete' : 'failed', 'failed_reason' => $reason]);
                    if ($data['phase'] === 'extract') {
                        $comparison->build($locked);
                        $terminal = true;
                    }
                }
            }
            DB::table('verification_events')->insert(['batch_id' => $batch->id, 'event_key' => $data['event_key'],
                'details' => json_encode(['kind' => $data['kind'], 'phase' => $data['phase'], 'run' => $data['run']], JSON_THROW_ON_ERROR), 'created_at' => now()]);
        });
        if ($terminal) {
            $billing->settle($batch->fresh());
        }
        return ['ok' => true];
    }
}
