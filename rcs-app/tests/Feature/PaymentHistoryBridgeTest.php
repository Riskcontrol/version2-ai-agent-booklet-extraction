<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentHistoryBridgeTest extends TestCase
{
    public function test_payment_history_bridge_forwards_filters_and_returns_partner_payload(): void
    {
        config()->set('services.partner.base_url', 'http://partner.test');
        config()->set('services.partner.token', 'shared-token');
        config()->set('services.partner.partner_name', 'riskcontrol');
        config()->set('services.partner.signature_secret', 'bridge-signature-secret');

        Http::fake([
            'http://partner.test/api/partner/payment-history' => Http::response([
                'user_email' => 'client@riskcontrol.test',
                'filters' => ['year' => 2026, 'month' => 6],
                'summary' => [
                    'selected_period' => [
                        'invoice_count' => 2,
                        'requested_credits' => 30,
                        'requested_amount_usd' => '1.8',
                        'amount_ngn_kobo' => 300000,
                    ],
                ],
                'items' => [],
            ], 200),
        ]);

        $response = $this->withSession([
            'authenticated' => true,
            'user_email' => 'client@riskcontrol.test',
            'user_name' => 'RiskControl Client',
        ])->getJson('/api/payment-history?year=2026&month=6');

        $response->assertOk();
        $response->assertJsonPath('filters.year', 2026);
        $response->assertJsonPath('filters.month', 6);
        $response->assertJsonPath('summary.selected_period.requested_credits', 30);

        Http::assertSent(function (HttpRequest $request) {
            return $request->url() === 'http://partner.test/api/partner/payment-history'
                && $request->method() === 'POST'
                && $request['user_email'] === 'client@riskcontrol.test'
                && (int) $request['year'] === 2026
                && (int) $request['month'] === 6
                && $request->hasHeader('X-Partner-Token', 'shared-token')
                && $request->hasHeader('X-Partner-Name', 'riskcontrol')
                && $request->hasHeader('X-Partner-Signature');
        });
    }

    public function test_payment_history_bridge_returns_502_when_partner_fails(): void
    {
        config()->set('services.partner.base_url', 'http://partner.test');
        config()->set('services.partner.token', 'shared-token');
        config()->set('services.partner.partner_name', 'riskcontrol');
        config()->set('services.partner.signature_secret', 'bridge-signature-secret');

        Http::fake([
            'http://partner.test/api/partner/payment-history' => Http::response([
                'error' => 'upstream unavailable',
            ], 500),
        ]);

        $response = $this->withSession([
            'authenticated' => true,
            'user_email' => 'client@riskcontrol.test',
            'user_name' => 'RiskControl Client',
        ])->getJson('/api/payment-history?year=2026&month=6');

        $response->assertStatus(502);
        $response->assertJsonPath('error', 'Unable to load payment history from Google Cloud billing authority.');
        $response->assertJsonPath('status', 500);
    }
}
