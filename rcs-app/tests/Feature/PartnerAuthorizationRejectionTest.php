<?php

namespace Tests\Feature;

use App\Models\PartnerAuthorizationRejection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PartnerAuthorizationRejectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unexpected_partner_tier_is_persisted_and_request_fails(): void
    {
        config()->set('services.partner.base_url', 'http://partner.test');
        config()->set('services.partner.token', 'shared-token');

        Http::fake([
            'http://partner.test/api/partner/authorize-extraction' => Http::response([
                'api_tier' => 'paid_3',
                'credits_reserved' => 1,
                'credit_balance' => 99,
            ], 200),
        ]);

        $pdf = UploadedFile::fake()->createWithContent(
            'booklet.pdf',
            "%PDF-1.4\n1 0 obj<</Type /Catalog /Pages 2 0 R>>endobj\n2 0 obj<</Type /Pages /Kids [3 0 R] /Count 1>>endobj\n3 0 obj<</Type /Page /Parent 2 0 R>>endobj\n%%EOF\n"
        );

        $response = $this->withSession([
            'authenticated' => true,
            'user_email' => 'admin@rcsn.com',
            'user_name' => 'RCS Admin',
        ])->postJson('/api/upload', [
            'file' => $pdf,
            'session' => '2025/2026',
            'start_page' => 1,
            'end_page' => 1,
        ]);

        $response->assertStatus(502);
        $response->assertJsonPath('message', 'Partner authorization returned an unsupported workflow tier.');

        $this->assertDatabaseCount('partner_authorization_rejections', 1);
        $this->assertDatabaseHas('partner_authorization_rejections', [
            'user_email' => 'admin@rcsn.com',
            'partner_name' => 'riskcontrol',
            'extraction_type' => 'convocation',
            'returned_api_tier' => 'paid_3',
            'reason' => 'Partner authorization returned an unexpected paid tier.',
        ]);

        $rejection = PartnerAuthorizationRejection::query()->firstOrFail();
        $this->assertSame(1, $rejection->pages_requested);
        $this->assertSame('paid_3', $rejection->payload['api_tier'] ?? null);
    }
}
