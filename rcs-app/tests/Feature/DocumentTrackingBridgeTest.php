<?php

namespace Tests\Feature;

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentTrackingBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_tracking_returns_partner_progress_for_owner(): void
    {
        config()->set('services.partner.base_url', 'http://partner.test');
        config()->set('services.partner.token', 'shared-token');
        config()->set('services.partner.partner_name', 'riskcontrol');
        config()->set('services.partner.signature_secret', 'bridge-signature-secret');

        $requestId = (string) Str::uuid();

        $doc = Document::create([
            'filename' => 'booklet.pdf',
            'path' => 'uploads/booklet.pdf',
            'status' => 'processing',
            'extraction_type' => 'convocation',
            'user_email' => 'client@riskcontrol.test',
            'partner_request_id' => $requestId,
            'pages_requested' => 12,
            'pages_processed' => 5,
            'credit_status' => 'authorized',
        ]);

        Http::fake([
            'http://partner.test/api/partner/extraction-progress' => Http::response([
                'partner_request_id' => $requestId,
                'status' => 'authorized',
                'phase' => 'processing',
                'pages_requested' => 12,
                'pages_processed' => 5,
                'progress_percent' => 42,
            ], 200),
        ]);

        $response = $this->withSession([
            'authenticated' => true,
            'user_email' => 'client@riskcontrol.test',
            'user_name' => 'Client User',
        ])->getJson("/api/documents/{$doc->id}/tracking");

        $response->assertOk();
        $response->assertJsonPath('document_id', $doc->id);
        $response->assertJsonPath('partner_request_id', $requestId);
        $response->assertJsonPath('phase', 'processing');
        $response->assertJsonPath('progress_percent', 42);
        $response->assertJsonPath('partner_tracking.status', 'authorized');

        Http::assertSent(function (HttpRequest $request) use ($requestId) {
            return $request->url() === 'http://partner.test/api/partner/extraction-progress'
                && $request->method() === 'POST'
                && $request['partner_request_id'] === $requestId
                && $request['user_email'] === 'client@riskcontrol.test'
                && $request->hasHeader('X-Partner-Token', 'shared-token')
                && $request->hasHeader('X-Partner-Name', 'riskcontrol')
                && $request->hasHeader('X-Partner-Signature');
        });
    }

    public function test_document_tracking_returns_fallback_when_partner_call_fails(): void
    {
        config()->set('services.partner.base_url', 'http://partner.test');
        config()->set('services.partner.token', 'shared-token');
        config()->set('services.partner.partner_name', 'riskcontrol');
        config()->set('services.partner.signature_secret', 'bridge-signature-secret');

        $doc = Document::create([
            'filename' => 'done.pdf',
            'path' => 'uploads/done.pdf',
            'status' => 'complete',
            'extraction_type' => 'convocation',
            'user_email' => 'client@riskcontrol.test',
            'partner_request_id' => (string) Str::uuid(),
            'pages_requested' => 8,
            'pages_processed' => 8,
            'credit_status' => 'success',
        ]);

        Http::fake([
            'http://partner.test/api/partner/extraction-progress' => Http::response([
                'error' => 'upstream unavailable',
            ], 500),
        ]);

        $response = $this->withSession([
            'authenticated' => true,
            'user_email' => 'client@riskcontrol.test',
            'user_name' => 'Client User',
        ])->getJson("/api/documents/{$doc->id}/tracking");

        $response->assertStatus(502);
        $response->assertJsonPath('phase', 'completed');
        $response->assertJsonPath('progress_percent', 100);
        $response->assertJsonPath('partner_tracking', null);
        $response->assertJsonPath('tracking_error', 'Unable to load partner tracking progress.');
    }

    public function test_document_tracking_returns_404_for_non_owner(): void
    {
        $doc = Document::create([
            'filename' => 'private.pdf',
            'path' => 'uploads/private.pdf',
            'status' => 'processing',
            'extraction_type' => 'convocation',
            'user_email' => 'owner@riskcontrol.test',
            'partner_request_id' => (string) Str::uuid(),
            'pages_requested' => 3,
            'pages_processed' => 1,
            'credit_status' => 'authorized',
        ]);

        $response = $this->withSession([
            'authenticated' => true,
            'user_email' => 'intruder@riskcontrol.test',
            'user_name' => 'Intruder User',
        ])->getJson("/api/documents/{$doc->id}/tracking");

        $response->assertStatus(404);
    }

    public function test_document_tracking_returns_404_when_tracking_feature_disabled(): void
    {
        config()->set('services.partner.tracking_ui_enabled', false);

        $doc = Document::create([
            'filename' => 'feature-off.pdf',
            'path' => 'uploads/feature-off.pdf',
            'status' => 'processing',
            'extraction_type' => 'convocation',
            'user_email' => 'owner@riskcontrol.test',
            'partner_request_id' => (string) Str::uuid(),
            'pages_requested' => 2,
            'pages_processed' => 1,
            'credit_status' => 'authorized',
        ]);

        $response = $this->withSession([
            'authenticated' => true,
            'user_email' => 'owner@riskcontrol.test',
            'user_name' => 'Owner User',
        ])->getJson("/api/documents/{$doc->id}/tracking");

        $response->assertStatus(404);
    }
}
