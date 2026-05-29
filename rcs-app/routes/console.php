<?php

use App\Models\PartnerUserMigration;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('partner:migrate-users {--dry-run : Show what would be migrated without calling Peldarg}', function () {
    $baseUrl = rtrim((string) config('services.partner.base_url', ''), '/');
    $token = (string) config('services.partner.token', '');
    $path = (string) config('services.partner.migration_path', '/api/partner/migrate-user');

    if ($baseUrl === '' || $token === '') {
        $this->error('Partner integration is not configured. Set PARTNER_BILLING_BASE_URL and PARTNER_SHARED_TOKEN.');
        return 1;
    }

    $dryRun = (bool) $this->option('dry-run');
    $defaultOpeningBalance = (int) config('services.partner.default_opening_balance', 0);
    $defaultOpeningCap = (int) config('services.partner.default_opening_cap', 0);

    $users = User::query()->orderBy('id')->get(['id', 'name', 'email']);
    if ($users->isEmpty()) {
        $this->info('No RiskControl users found to migrate.');
        return 0;
    }

    $this->info('Phase 7 migration run started for ' . $users->count() . ' users.');

    $migrated = 0;
    $failed = 0;
    $skipped = 0;

    foreach ($users as $user) {
        $mapping = PartnerUserMigration::query()->firstOrNew([
            'riskcontrol_user_email' => (string) $user->email,
        ]);

        if ($mapping->exists && $mapping->status === 'migrated') {
            $skipped++;
            $this->line('SKIP (already migrated): ' . $user->email);
            continue;
        }

        $payload = [
            'riskcontrol_user_email' => (string) $user->email,
            'user_email' => (string) $user->email,
            'name' => (string) $user->name,
            'company_name' => (string) $user->name,
            'opening_balance' => $mapping->exists ? (int) $mapping->opening_balance : $defaultOpeningBalance,
            'opening_cap' => $mapping->exists ? (int) $mapping->opening_cap : $defaultOpeningCap,
            'partner_name' => 'riskcontrol',
            'partner_domain' => (string) config('app.url'),
            'partner_user_reference' => (string) $user->name,
        ];

        if (!$mapping->exists) {
            $mapping->partner_user_email = (string) $user->email;
            $mapping->opening_balance = (int) $payload['opening_balance'];
            $mapping->opening_cap = (int) $payload['opening_cap'];
            $mapping->status = 'pending';
            $mapping->save();
        }

        if ($dryRun) {
            $this->line('DRY-RUN migrate: ' . $user->email . ' (opening_balance=' . $payload['opening_balance'] . ', opening_cap=' . $payload['opening_cap'] . ')');
            continue;
        }

        try {
            // Phase 7: Simple token-based auth for migration endpoint
            $response = Http::withHeaders([
                    'X-Partner-Token' => $token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->connectTimeout(5)
                ->timeout((int) config('services.partner.timeout', 15))
                ->post($baseUrl . $path, $payload);

            if ($response->successful()) {
                $resp = (array) $response->json();
                $mapping->partner_user_id = (int) ($resp['user_id'] ?? 0) ?: null;
                $mapping->partner_user_email = (string) ($resp['user_email'] ?? $user->email);
                $mapping->status = 'migrated';
                $mapping->migrated_at = now();
                $mapping->last_error = null;
                $mapping->metadata = $resp;
                $mapping->save();

                $migrated++;
                $this->info('MIGRATED: ' . $user->email . ' -> partner_user_id=' . (($resp['user_id'] ?? 'n/a')));
                continue;
            }

            $failed++;
            $mapping->status = 'failed';
            $mapping->last_error = 'HTTP ' . $response->status() . ': ' . $response->body();
            $mapping->metadata = ['http_status' => $response->status()];
            $mapping->save();
            $this->error('FAILED: ' . $user->email . ' (HTTP ' . $response->status() . ')');
        } catch (\Throwable $e) {
            $failed++;
            $mapping->status = 'failed';
            $mapping->last_error = $e->getMessage();
            $mapping->metadata = ['exception' => get_class($e)];
            $mapping->save();
            $this->error('FAILED: ' . $user->email . ' (' . $e->getMessage() . ')');
        }
    }

    $this->newLine();
    $this->info('Migration summary: migrated=' . $migrated . ', failed=' . $failed . ', skipped=' . $skipped . ', dry_run=' . ($dryRun ? 'yes' : 'no'));

    return $failed > 0 ? 1 : 0;
})->purpose('Phase 7: migrate RiskControl users to mapped Peldarg identities with opening balances');
