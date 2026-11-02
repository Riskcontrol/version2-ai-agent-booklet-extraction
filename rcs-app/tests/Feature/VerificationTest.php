<?php

namespace Tests\Feature;

use App\Models\VerificationBatch;
use App\Models\VerificationRecord;
use App\Models\VerificationResult;
use App\Services\VerificationComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class VerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('services.github.pat', 'test-pat');
        config()->set('services.extractor.secret', 'worker-secret');
        config()->set('services.partner.base_url', 'https://billing.test');
        config()->set('services.partner.token', 'token');
        config()->set('services.partner.signature_secret', 'secret');
        Http::preventStrayRequests();
        Http::fake([
            'https://api.github.com/*' => fn () => Http::response([], config('verification.test_dispatch_fail') ? 500 : 204),
            'https://billing.test/api/partner/authorize-extraction' => fn () => config('verification.test_deny')
                ? Http::response(['error' => 'Insufficient credits'], 422) : Http::response([
                'api_tier' => 'paid_1', 'credits_reserved' => 2, 'credit_balance' => 98,
            ]),
            'https://billing.test/api/partner/finalize-extraction' => fn (ClientRequest $r) => Http::response([
                'status' => 'settled', 'credits_consumed' => $r['pages_processed'], 'credits_refunded' => 2 - $r['pages_processed'],
            ]),
        ]);
        $this->withSession(['authenticated' => true, 'user_email' => 'owner@test.local', 'user_name' => 'Owner']);
    }

    private function item(string $name = 'nested/source.pdf', int $pages = 2): array
    {
        return ['id' => hash('sha256', $name), 'filename' => $name, 'pages' => $pages, 'sha256' => str_repeat('a', 64), 'bytes' => 100];
    }

    private function batch(array $attributes = []): VerificationBatch
    {
        $id = (string) Str::uuid();
        return VerificationBatch::create(['id' => $id, 'user_email' => 'owner@test.local', 'filename' => 'sources.zip',
            'archive_path' => "verifications/$id/source.zip", 'size' => 100, 'status' => 'ready',
            'inventory' => [$this->item()], 'pages_requested' => 2, ...$attributes]);
    }

    private function fields(array $overrides = []): array
    {
        return ['name' => 'DADA HAMUDALAT ADETOLA', 'institution' => 'Lagos State Polytechnic',
            'course' => 'Agricultural Engineering Technology', 'qualification' => 'ND', 'grade' => 'Lower Credit',
            'session' => '2009/2010', 'matric_number' => '', ...$overrides];
    }

    private function record(VerificationBatch $batch, string $role, array $fields = []): VerificationRecord
    {
        return VerificationRecord::create(['batch_id' => $batch->id, 'record_key' => (string) Str::uuid(),
            'role' => $role, 'source_id' => $this->item()['id'], 'filename' => 'source.pdf', 'page' => 1,
            'fields' => $this->fields($fields), 'evidence' => []]);
    }

    private function workerCallback(VerificationBatch $batch, array $payload, string $run = 'run-1', string $phase = 'extract')
    {
        $body = json_encode(['run' => $run, 'phase' => $phase, ...$payload]);
        $timestamp = (string) time();
        return $this->call('POST', "/api/verification-worker/$batch->id/callback", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_VERIFICATION_TIMESTAMP' => $timestamp,
            'HTTP_X_EXTRACTOR_SIGNATURE' => hash_hmac('sha256', $timestamp."\n".$body, 'worker-secret'),
        ], $body);
    }

    public function test_page_renders_and_owner_is_required_for_private_records(): void
    {
        $this->get('/verify-certificate-report')->assertOk()->assertSee('Verify Certificate &amp; Report', false);
        $batch = $this->batch(['user_email' => 'someone-else@test.local']);
        $this->getJson("/api/verifications/$batch->id")->assertNotFound();
        $this->getJson("/api/verifications/$batch->id/results")->assertNotFound();
        $this->getJson("/api/verifications/$batch->id/sources/anything")->assertNotFound();
    }

    public function test_chunk_upload_is_sequential_and_interruptions_cannot_resume(): void
    {
        $created = $this->postJson('/api/verifications', ['filename' => 'batch.zip', 'size' => 6])->assertCreated()->json();
        $id = $created['batch']['id'];
        $this->call('POST', "/api/verifications/$id/chunks", [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_UPLOAD_OFFSET' => '0', 'HTTP_ACCEPT' => 'application/json',
        ], 'abc')->assertOk()->assertJsonPath('bytes_received', 3);
        $this->call('POST', "/api/verifications/$id/chunks", [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_UPLOAD_OFFSET' => '0', 'HTTP_ACCEPT' => 'application/json',
        ], 'def')->assertStatus(409);
        $this->assertDatabaseHas('verification_batches', ['id' => $id, 'status' => 'upload_failed', 'bytes_received' => 3]);
        $this->call('POST', "/api/verifications/$id/chunks", [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_UPLOAD_OFFSET' => '3', 'HTTP_ACCEPT' => 'application/json',
        ], 'def')->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_inventory_is_unpaid_and_requires_confirmation_before_extraction(): void
    {
        $batch = $this->batch(['status' => 'uploading', 'bytes_received' => 100]);
        $this->postJson("/api/verifications/$batch->id/finish-upload")->assertOk();
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/dispatches')
            && count($r['client_payload']) <= 10 && $r['client_payload']['phase'] === 'inventory');
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), 'authorize-extraction'));
        $this->workerCallback($batch, ['kind' => 'started', 'event_key' => 'inventory-start'], phase: 'inventory')->assertOk();
        $this->workerCallback($batch, ['kind' => 'inventory', 'event_key' => 'inventory', 'inventory' => [$this->item()], 'notices' => []], phase: 'inventory')->assertOk();
        $this->assertDatabaseHas('verification_batches', ['id' => $batch->id, 'status' => 'ready', 'pages_requested' => 2]);
        $this->postJson("/api/verifications/$batch->id/start", ['confirm_credits' => 1])->assertStatus(422);
        $this->postJson("/api/verifications/$batch->id/start", ['confirm_credits' => 2])->assertOk();
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'authorize-extraction') && $r['pages_requested'] === 2
            && $r['extraction_type'] === 'certificates' && $r['workload_metadata']['operation'] === 'verify_certificate_report');
        $this->postJson("/api/verifications/$batch->id/start", ['confirm_credits' => 2])->assertStatus(409);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), 'authorize-extraction')));
    }

    public function test_reupload_creates_a_new_paid_authorization_and_denials_are_not_retried(): void
    {
        $first = $this->batch();
        $second = $this->batch();
        foreach ([$first, $second] as $batch) {
            $this->postJson("/api/verifications/$batch->id/start", ['confirm_credits' => 2])->assertOk();
        }
        $this->assertNotSame($first->fresh()->partner_request_id, $second->fresh()->partner_request_id);
        $this->assertCount(2, Http::recorded(fn ($r) => str_contains($r->url(), 'authorize-extraction')));
        config()->set('verification.test_deny', true);
        $denied = $this->batch();
        $this->postJson("/api/verifications/$denied->id/start", ['confirm_credits' => 2])->assertStatus(503);
        $this->assertDatabaseHas('verification_batches', ['id' => $denied->id, 'status' => 'failed']);
        $this->postJson("/api/verifications/$denied->id/start", ['confirm_credits' => 2])->assertStatus(409);
        $this->assertCount(3, Http::recorded(fn ($r) => str_contains($r->url(), 'authorize-extraction')));
    }

    public function test_unsigned_callbacks_are_rejected_and_workers_cannot_rerun(): void
    {
        $batch = $this->batch(['status' => 'queued']);
        $this->postJson("/api/verification-worker/$batch->id/callback", ['kind' => 'started'])->assertUnauthorized();
        $this->workerCallback($batch, ['kind' => 'started', 'event_key' => 'start-1'])->assertOk();
        $this->workerCallback($batch, ['kind' => 'started', 'event_key' => 'start-1'])->assertStatus(409);
        $this->workerCallback($batch, ['kind' => 'started', 'event_key' => 'start-2'], 'rerun')->assertStatus(409);
        $this->workerCallback($batch, ['kind' => 'failed', 'event_key' => 'fail-rerun'], 'rerun')->assertStatus(409);
        $this->assertDatabaseHas('verification_batches', ['id' => $batch->id, 'status' => 'processing', 'worker_run' => 'run-1']);
    }

    public function test_partial_failure_retains_results_and_settles_pages_once(): void
    {
        $batch = $this->batch(['status' => 'queued', 'partner_request_id' => (string) Str::uuid(), 'credit_status' => 'authorized', 'credits_reserved' => 2]);
        $this->workerCallback($batch, ['kind' => 'started', 'event_key' => 'start'])->assertOk();
        $file = ['kind' => 'file', 'event_key' => 'file:'.$this->item()['id'], 'source_id' => $this->item()['id'],
            'pages_processed' => 1, 'pages_with_results' => 1, 'records' => [
                ['role' => 'certificate', 'page' => 1, 'fields' => $this->fields()],
                ['role' => 'report', 'page' => 1, 'fields' => $this->fields()],
            ], 'errors' => [['page' => 2, 'message' => 'Unreadable page. No retry attempted.']]];
        $this->workerCallback($batch, $file)->assertOk();
        $this->workerCallback($batch, $file)->assertOk();
        $this->assertDatabaseCount('verification_records', 2);
        $this->workerCallback($batch, ['kind' => 'done', 'event_key' => 'done'])->assertOk();
        $this->workerCallback($batch, ['kind' => 'done', 'event_key' => 'done'])->assertOk();
        $this->assertDatabaseHas('verification_batches', ['id' => $batch->id, 'status' => 'failed', 'pages_processed' => 1, 'files_processed' => 1]);
        $this->assertDatabaseHas('verification_results', ['batch_id' => $batch->id, 'status' => 'Yes']);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), 'finalize-extraction')));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'finalize-extraction') && $r['pages_processed'] === 1 && $r['status'] === 'failed');
        $this->workerCallback($batch, ['kind' => 'started', 'event_key' => 'start-again'], 'another-run')->assertStatus(409);
    }

    public function test_successful_batch_bills_pages_not_records_and_cannot_run_again(): void
    {
        $batch = $this->batch();
        $this->postJson("/api/verifications/$batch->id/start", ['confirm_credits' => 2])->assertOk();
        $this->workerCallback($batch, ['kind' => 'started', 'event_key' => 'start'])->assertOk();
        $this->workerCallback($batch, ['kind' => 'file', 'event_key' => 'file:'.$this->item()['id'],
            'source_id' => $this->item()['id'], 'pages_processed' => 2, 'pages_with_results' => 1,
            'records' => [['role' => 'certificate', 'page' => 1, 'fields' => $this->fields()],
                ['role' => 'report', 'page' => 1, 'fields' => $this->fields()]], 'errors' => [],
        ])->assertOk();
        $this->workerCallback($batch, ['kind' => 'done', 'event_key' => 'done'])->assertOk();
        $this->assertDatabaseHas('verification_batches', ['id' => $batch->id, 'status' => 'complete', 'credits_consumed' => 2, 'credits_refunded' => 0]);
        $this->assertDatabaseHas('verification_results', ['batch_id' => $batch->id, 'status' => 'Yes']);
        $this->postJson("/api/verifications/$batch->id/start", ['confirm_credits' => 2])->assertStatus(409);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'finalize-extraction') && $r['pages_processed'] === 2 && $r['pages_with_results'] === 1);
    }

    public function test_dispatch_failure_settles_once_without_restarting(): void
    {
        config()->set('verification.test_dispatch_fail', true);
        $batch = $this->batch();
        $this->postJson("/api/verifications/$batch->id/start", ['confirm_credits' => 2])->assertStatus(503);
        $this->assertDatabaseHas('verification_batches', ['id' => $batch->id, 'status' => 'failed', 'credits_refunded' => 2]);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), '/dispatches')));
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), 'finalize-extraction')));
    }

    public function test_stalled_workers_close_without_restarting_or_repeating_settlement(): void
    {
        $batch = $this->batch(['status' => 'processing', 'credit_status' => 'authorized', 'partner_request_id' => (string) Str::uuid()]);
        $batch->update(['updated_at' => now()->subHours(9)]);
        $this->getJson("/api/verifications/$batch->id")->assertOk()->assertJsonPath('batch.status', 'failed');
        $this->getJson("/api/verifications/$batch->id")->assertOk();
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), 'finalize-extraction')));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/dispatches'));
    }

    public function test_page_checkpoints_survive_a_worker_failure_mid_pdf(): void
    {
        $batch = $this->batch(['status' => 'queued', 'partner_request_id' => (string) Str::uuid(), 'credit_status' => 'authorized']);
        $this->workerCallback($batch, ['kind' => 'started', 'event_key' => 'start'])->assertOk();
        $page = ['kind' => 'page', 'event_key' => 'page:'.$this->item()['id'].':1', 'source_id' => $this->item()['id'],
            'page' => 1, 'pages_processed' => 1, 'pages_with_results' => 1, 'records' => [
                ['role' => 'certificate', 'page' => 1, 'fields' => $this->fields()],
                ['role' => 'report', 'page' => 1, 'fields' => $this->fields()],
            ], 'errors' => []];
        $this->workerCallback($batch, $page)->assertOk();
        $this->workerCallback($batch, $page)->assertOk();
        $this->assertDatabaseCount('verification_records', 2);
        $this->assertDatabaseHas('verification_batches', ['id' => $batch->id, 'pages_processed' => 1, 'files_processed' => 0]);
        $this->workerCallback($batch, ['kind' => 'failed', 'event_key' => 'failure', 'reason' => 'Worker stopped mid-PDF.'])->assertOk();
        $this->assertDatabaseHas('verification_results', ['batch_id' => $batch->id, 'status' => 'Yes']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'finalize-extraction') && $r['pages_processed'] === 1);
    }

    public function test_incremental_file_completion_does_not_count_pages_twice(): void
    {
        $batch = $this->batch(['status' => 'queued', 'partner_request_id' => (string) Str::uuid(), 'credit_status' => 'authorized']);
        $this->workerCallback($batch, ['kind' => 'started', 'event_key' => 'start'])->assertOk();
        foreach ([1 => 'certificate', 2 => 'report'] as $page => $role) {
            $this->workerCallback($batch, ['kind' => 'page', 'event_key' => 'page:'.$this->item()['id'].':'.$page,
                'source_id' => $this->item()['id'], 'page' => $page, 'pages_processed' => 1, 'pages_with_results' => 1,
                'records' => [['role' => $role, 'page' => $page, 'fields' => $this->fields()]], 'errors' => [],
            ])->assertOk();
        }
        $file = ['kind' => 'file', 'event_key' => 'file:'.$this->item()['id'], 'source_id' => $this->item()['id'], 'incremental' => true];
        $this->workerCallback($batch, $file)->assertOk();
        $this->workerCallback($batch, $file)->assertOk();
        $this->workerCallback($batch, ['kind' => 'done', 'event_key' => 'done'])->assertOk();
        $this->assertDatabaseHas('verification_batches', ['id' => $batch->id, 'status' => 'complete', 'pages_processed' => 2, 'files_processed' => 1, 'credits_consumed' => 2]);
    }

    public function test_matching_handles_multiple_awards_and_does_not_hide_spelling_or_grade_errors(): void
    {
        $batch = $this->batch();
        $nd = $this->record($batch, 'certificate', ['session' => '2010']);
        $hnd = $this->record($batch, 'certificate', ['qualification' => 'Higher National Diploma', 'session' => '2013']);
        $ndReport = $this->record($batch, 'report');
        $hndReport = $this->record($batch, 'report', ['name' => 'DADA HAMUDALAT ADETOLAA', 'qualification' => 'HND', 'session' => '2012/2013', 'grade' => 'Upper Credit']);
        app(VerificationComparisonService::class)->build($batch);
        $this->assertDatabaseHas('verification_results', ['certificate_id' => $nd->id, 'report_id' => $ndReport->id, 'status' => 'Yes']);
        $this->assertDatabaseHas('verification_results', ['certificate_id' => $hnd->id, 'report_id' => $hndReport->id, 'status' => 'Disparity']);
        $result = VerificationResult::where('certificate_id', $hnd->id)->first();
        $this->assertStringContainsString('name:', $result->reason);
        $this->assertStringContainsString('grade:', $result->reason);
    }

    public function test_duplicate_candidates_and_conflicting_ids_are_not_forced(): void
    {
        $batch = $this->batch();
        $certificate = $this->record($batch, 'certificate');
        $this->record($batch, 'report');
        $this->record($batch, 'report');
        app(VerificationComparisonService::class)->build($batch);
        $this->assertDatabaseHas('verification_results', ['certificate_id' => $certificate->id, 'report_id' => null, 'status' => 'Needs review']);
        $comparison = app(VerificationComparisonService::class);
        $this->assertFalse($comparison->candidate($this->fields(['matric_number' => '001']), $this->fields(['matric_number' => '002']))['eligible']);
        $this->assertSame('Needs review', $comparison->compare($this->fields(['grade' => '']), $this->fields())['status']);
        $this->assertSame('Needs review', $comparison->compare($this->fields(['grade' => 'N/A']), $this->fields(['grade' => 'N/A']))['status']);
    }

    public function test_sample_hnd_wording_and_year_equivalence_preserve_option_differences(): void
    {
        $comparison = app(VerificationComparisonService::class);
        $certificate = $this->fields(['name' => 'Hamudalat Adetola DADA', 'course' => 'Agricultural Engineering',
            'qualification' => 'Higher National Diploma', 'specialization' => 'Soil & Water Option', 'session' => 'October, 2013']);
        $report = $this->fields(['institution' => 'Lagos State Polytechnic, Ikorodu',
            'qualification' => 'HND (SOIL & WATER)', 'session' => '2012/2013']);
        $this->assertSame('Yes', $comparison->compare($certificate, $report)['status']);
        $report['qualification'] = 'HND (MECHANICAL)';
        $this->assertSame('Disparity', $comparison->compare($certificate, $report)['status']);
        $this->assertSame('Disparity', $comparison->compare($this->fields(), $this->fields(['session' => '2008/2010']))['status']);
    }

    public function test_manual_pairing_uses_stored_values_and_audits_without_billing_or_model_calls(): void
    {
        $batch = $this->batch(['status' => 'complete']);
        $certificate = $this->record($batch, 'certificate');
        $report = $this->record($batch, 'report');
        $result = VerificationResult::create(['batch_id' => $batch->id, 'certificate_id' => $certificate->id,
            'status' => 'Needs review', 'reason' => 'Unpaired', 'comparisons' => [], 'candidates' => []]);
        $this->postJson("/api/verifications/$batch->id/pairings/$result->id", [
            'report_id' => $report->id, 'revision' => 0, 'note' => 'Confirmed from source PDF.',
        ])->assertOk()->assertJsonPath('status', 'Yes')->assertJsonPath('manual', true);
        $this->assertDatabaseHas('verification_pairing_audits', ['certificate_id' => $certificate->id, 'report_id' => $report->id]);
        $this->postJson("/api/verifications/$batch->id/pairings/$result->id", [
            'report_id' => null, 'revision' => 0, 'note' => 'Stale change.',
        ])->assertStatus(409);
        $otherBatch = $this->batch();
        $otherReport = $this->record($otherBatch, 'report');
        $this->postJson("/api/verifications/$batch->id/pairings/$result->id", [
            'report_id' => $otherReport->id, 'revision' => 1, 'note' => 'Wrong batch.',
        ])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_source_preview_is_private_and_exports_reflect_current_values(): void
    {
        $batch = $this->batch(['status' => 'complete']);
        Storage::disk('local')->makeDirectory(dirname($batch->archive_path));
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($batch->archive_path), ZipArchive::CREATE);
        $zip->addFromString('nested/source.pdf', '%PDF-test');
        $zip->close();
        $certificate = $this->record($batch, 'certificate', ['name' => '=SUM(1,2)']);
        $report = $this->record($batch, 'report');
        $result = VerificationResult::create(['batch_id' => $batch->id, 'certificate_id' => $certificate->id,
            'report_id' => $report->id, 'candidates' => [], ...app(VerificationComparisonService::class)->compare($certificate->fields, $report->fields)]);
        $this->get("/api/verifications/$batch->id/sources/".$this->item()['id'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $csv = $this->get("/api/verifications/$batch->id/export/csv")->assertOk()->streamedContent();
        $this->assertStringContainsString("'=SUM(1,2)", $csv);
        $xlsx = $this->get("/api/verifications/$batch->id/export/xlsx")->assertOk();
        $path = $xlsx->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('=SUM(1,2)', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertNotFalse($zip->getFromName('xl/worksheets/sheet2.xml'));
        $zip->close();
        @unlink($path);
    }

    public function test_partner_reconciliation_includes_completed_and_partial_verification_pages(): void
    {
        $this->batch(['status' => 'complete', 'pages_processed' => 2, 'files_processed' => 2]);
        $this->batch(['status' => 'failed', 'pages_processed' => 1, 'files_processed' => 1]);
        $this->batch(['status' => 'queued', 'pages_requested' => 100]);
        $this->withoutMiddleware([
            \App\Http\Middleware\ValidatePartnerSignature::class,
            \App\Http\Middleware\TrackIncomingIdempotency::class,
        ])->withHeader('X-Partner-Token', 'token')->postJson('/api/partner/reconciliation-summary', [])
            ->assertOk()->assertJsonPath('processed_pages_total', 3)
            ->assertJsonPath('certificate_pages_total', 3)->assertJsonPath('verification_pages_total', 3)
            ->assertJsonPath('completed_documents_total', 2)->assertJsonPath('completed_verification_batches_total', 1);
    }

    public function test_six_hundred_records_can_be_paired_without_model_or_http_requests(): void
    {
        $batch = $this->batch();
        $rows = [];
        for ($i = 0; $i < 300; $i++) {
            foreach (['certificate', 'report'] as $role) {
                $rows[] = ['batch_id' => $batch->id, 'record_key' => "$role-$i", 'role' => $role,
                    'source_id' => str_repeat('a', 64), 'filename' => "$role-$i.pdf", 'page' => 1,
                    'fields' => json_encode($this->fields(['name' => "CANDIDATE NUMBER $i", 'matric_number' => sprintf('%08d', $i + 1)])),
                    'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($rows, 50) as $chunk) DB::table('verification_records')->insert($chunk);
        app(VerificationComparisonService::class)->build($batch);
        $this->assertSame(300, VerificationResult::where('batch_id', $batch->id)->where('status', 'Yes')->count());
        Http::assertNothingSent();
    }
}
