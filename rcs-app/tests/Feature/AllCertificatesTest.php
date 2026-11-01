<?php

namespace Tests\Feature;

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AllCertificatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config()->set('services.partner.base_url', 'http://partner.test');
        config()->set('services.partner.token', 'test-token');
        config()->set('services.partner.integration_mode', 'hard_block');
        config()->set('services.github.pat', 'test-pat');
        $this->withSession(['authenticated' => true, 'user_email' => 'client@test.local', 'user_name' => 'Client']);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('mixed.pdf',
            "%PDF-1.4\n1 0 obj<</Type /Catalog /Pages 2 0 R>>endobj\n2 0 obj<</Type /Pages /Kids [3 0 R 4 0 R] /Count 2>>endobj\n3 0 obj<</Type /Page /Parent 2 0 R>>endobj\n4 0 obj<</Type /Page /Parent 2 0 R>>endobj\n%%EOF\n");
    }

    public function test_all_page_renders_template_and_preserves_existing_page(): void
    {
        $this->get('/all-certificates')->assertOk()
            ->assertSee('data-certificate-layout="all"', false)
            ->assertSee('Exam Number/Call-up Number/Matric number')
            ->assertSee('Call to Bar');
        $this->get('/certificates')->assertOk()->assertSee('data-certificate-layout="academic"', false);
    }

    public function test_upload_uses_normal_certificate_billing_and_dispatches_layout(): void
    {
        Http::fake([
            'http://partner.test/api/partner/authorize-extraction' => Http::response([
                'api_tier' => 'paid_1', 'credits_reserved' => 2, 'credit_balance' => 8,
            ]),
            'https://api.github.com/*' => fn (Request $r) => count($r['client_payload']) > 10
                ? Http::response(['message' => 'No more than 10 properties are allowed.'], 422)
                : Http::response([], 204),
        ]);
        $response = $this->postJson('/api/all-certificates/upload', [
            'file' => $this->pdf(), 'client_name' => 'Test Client', 'date_received' => '02/11/2026',
        ])->assertOk();
        $this->assertDatabaseHas('documents', [
            'id' => $response->json('id'), 'certificate_layout' => 'all',
            'extraction_type' => 'certificates', 'pages_requested' => 2, 'credits_reserved' => 2,
        ]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/authorize-extraction')
            && $r['extraction_type'] === 'certificates' && $r['pages_requested'] === 2
            && $r['workload_metadata']['certificate_layout'] === 'all');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/dispatches')
            && $r['event_type'] === 'process_certificates'
            && count($r['client_payload']) <= 10
            && $r['client_payload']['certificate_metadata']['layout'] === 'all'
            && $r['client_payload']['certificate_metadata']['date_received'] === '02/11/2026'
            && $r['client_payload']['certificate_metadata']['client_name'] === 'Test Client'
            && $r['client_payload']['api_key_tier'] === 'GEMINI_API_KEY_PAID');
    }

    public function test_insufficient_credit_prevents_workflow_dispatch(): void
    {
        Http::fake(['http://partner.test/*' => Http::response(['error' => 'Insufficient credits.'], 422)]);
        $this->postJson('/api/all-certificates/upload', ['file' => $this->pdf()])->assertStatus(503);
        $this->assertDatabaseCount('documents', 0);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.github.com'));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_dispatch_failure_uses_existing_credit_refund_path(): void
    {
        Http::fake([
            'http://partner.test/api/partner/authorize-extraction' => Http::response([
                'api_tier' => 'paid_1', 'credits_reserved' => 2, 'credit_balance' => 8,
            ]),
            'https://api.github.com/*' => Http::response([], 500),
            'http://partner.test/api/partner/finalize-extraction' => Http::response([
                'status' => 'failed', 'credits_refunded' => 2,
            ]),
        ]);
        $this->postJson('/api/all-certificates/upload', ['file' => $this->pdf()])->assertStatus(500);
        $this->assertDatabaseHas('documents', [
            'certificate_layout' => 'all', 'status' => 'failed', 'credits_refunded' => 2,
        ]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/finalize-extraction')
            && $r['status'] === 'failed' && $r['pages_processed'] === 0);
    }

    public function test_certificate_lists_are_separate(): void
    {
        foreach (['academic', 'all'] as $layout) {
            Document::create(['filename' => "$layout.pdf", 'path' => "$layout.pdf",
                'status' => 'complete', 'extraction_type' => 'certificates', 'certificate_layout' => $layout]);
        }
        $this->getJson('/api/all-certificates')->assertOk()->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.filename', 'all.pdf');
        $this->getJson('/api/certificates')->assertOk()->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.filename', 'academic.pdf');
    }

    public function test_result_ingestion_preserves_identifier_and_settles_by_pages(): void
    {
        config()->set('services.extractor.token', 'extractor-test');
        Http::fake(['http://partner.test/*' => Http::response(['credits_consumed' => 2, 'status' => 'success'])]);
        $doc = Document::create(['filename' => 'mixed.pdf', 'path' => 'mixed.pdf', 'status' => 'processing',
            'extraction_type' => 'certificates', 'certificate_layout' => 'all',
            'partner_request_id' => 'test-request', 'user_email' => 'client@test.local', 'pages_requested' => 2]);
        $csv = "name,institution,course,qualification,grade,session,matric_number\n";
        for ($i = 0; $i < 3; $i++) {
            $csv .= "CANDIDATE $i,NATIONAL EXAMINATION COUNCIL,NECO,NECO,PASS,2016,00450849BB\n";
        }
        $this->withHeader('X-Extractor-Token', 'extractor-test')->post('/api/github/upload-results', [
            'doc_id' => $doc->id, 'csv' => UploadedFile::fake()->createWithContent('mixed.csv', $csv),
            'summary' => json_encode(['counts' => ['rows' => 3, 'pages_processed' => 2, 'pages_with_results' => 1]]),
        ])->assertOk();
        $this->assertDatabaseHas('certificates', ['document_id' => $doc->id, 'matric_number' => '00450849BB']);
        $this->assertDatabaseHas('documents', ['id' => $doc->id, 'result_rows' => 3, 'pages_processed' => 2, 'pages_with_results' => 1]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'finalize')
            && $r['pages_processed'] === 2 && $r['pages_with_results'] === 1);
    }
}
