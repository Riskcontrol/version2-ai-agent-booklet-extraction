<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Phase7MigrationStrategyTest extends TestCase
{
    use RefreshDatabase;

    private function fakePdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'booklet.pdf',
            "%PDF-1.4\n1 0 obj<</Type /Catalog /Pages 2 0 R>>endobj\n2 0 obj<</Type /Pages /Kids [3 0 R] /Count 1>>endobj\n3 0 obj<</Type /Page /Parent 2 0 R>>endobj\n%%EOF\n"
        );
    }

    public function test_shadow_mode_logs_decision_without_blocking_upload(): void
    {
        config()->set('services.partner.base_url', 'http://partner.test');
        config()->set('services.partner.token', 'shared-token');
        config()->set('services.partner.integration_mode', 'shadow');
        config()->set('services.partner.shadow_started_at', null);
        config()->set('services.partner.hard_block_after_days', 7);

        Http::fake([
            'http://partner.test/api/partner/authorize-extraction' => Http::response([
                'error' => 'Insufficient credits for this extraction.',
            ], 422),
            'https://api.github.com/*' => Http::response(['ok' => true], 200),
        ]);

        $response = $this->withSession([
            'authenticated' => true,
            'user_email' => 'admin@rcsn.com',
            'user_name' => 'RCS Admin',
        ])->postJson('/api/upload', [
            'file' => $this->fakePdf(),
            'session' => '2025/2026',
            'start_page' => 1,
            'end_page' => 1,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'processing');
        $response->assertJsonPath('shadow_mode', true);
        $response->assertJsonPath('integration_mode', 'shadow');

        $this->assertDatabaseHas('partner_authorization_decisions', [
            'user_email' => 'admin@rcsn.com',
            'extraction_type' => 'convocation',
            'pages_requested' => 1,
            'decision' => 'bypassed',
            'enforcement_mode' => 'shadow',
            'hard_blocked' => 0,
            'response_status' => 422,
        ]);
    }

    public function test_hard_block_mode_enforces_partner_denial_after_validation_window(): void
    {
        config()->set('services.partner.base_url', 'http://partner.test');
        config()->set('services.partner.token', 'shared-token');
        config()->set('services.partner.integration_mode', 'shadow');
        config()->set('services.partner.shadow_started_at', now()->subDays(10)->toIso8601String());
        config()->set('services.partner.hard_block_after_days', 7);

        Http::fake([
            'http://partner.test/api/partner/authorize-extraction' => Http::response([
                'error' => 'Insufficient credits for this extraction.',
            ], 422),
        ]);

        $response = $this->withSession([
            'authenticated' => true,
            'user_email' => 'admin@rcsn.com',
            'user_name' => 'RCS Admin',
        ])->postJson('/api/upload', [
            'file' => $this->fakePdf(),
            'session' => '2025/2026',
            'start_page' => 1,
            'end_page' => 1,
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseHas('partner_authorization_decisions', [
            'user_email' => 'admin@rcsn.com',
            'decision' => 'denied',
            'enforcement_mode' => 'hard_block',
            'hard_blocked' => 1,
            'response_status' => 422,
        ]);
    }

    public function test_top_up_path_is_frozen_after_hard_block_cutover(): void
    {
        config()->set('services.partner.integration_mode', 'hard_block');
        config()->set('services.partner.freeze_direct_topup', true);

        $response = $this->withSession([
            'authenticated' => true,
            'user_email' => 'admin@rcsn.com',
            'user_name' => 'RCS Admin',
        ])->postJson('/api/top-up', [
            'amount' => 100,
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('error', 'Direct top-up in RiskControl is disabled after cutover. Use Peldarg billing portal.');
    }
}
