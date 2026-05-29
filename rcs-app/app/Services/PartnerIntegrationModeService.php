<?php

namespace App\Services;

use Carbon\Carbon;

class PartnerIntegrationModeService
{
    public static function configuredMode(): string
    {
        return strtolower((string) config('services.partner.integration_mode', 'shadow'));
    }

    public static function isShadowMode(): bool
    {
        return self::effectiveMode() === 'shadow';
    }

    public static function shouldHardBlock(): bool
    {
        return self::effectiveMode() === 'hard_block';
    }

    public static function effectiveMode(): string
    {
        $configured = self::configuredMode();
        if ($configured === 'hard_block') {
            return 'hard_block';
        }

        if ($configured !== 'shadow') {
            return 'shadow';
        }

        $startedAt = (string) config('services.partner.shadow_started_at', '');
        $days = (int) config('services.partner.hard_block_after_days', 7);

        if ($startedAt === '' || $days < 1) {
            return 'shadow';
        }

        try {
            $cutoverDate = Carbon::parse($startedAt)->addDays($days);
            return now()->greaterThanOrEqualTo($cutoverDate) ? 'hard_block' : 'shadow';
        } catch (\Throwable) {
            return 'shadow';
        }
    }
}
