<?php

namespace App\Http\Controllers;

use App\Models\PartnerCreditSyncEvent;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminPartnerCreditSyncController extends Controller
{
    public function index(Request $request)
    {
        $this->assertAdmin($request);
        $this->applyDatePreset($request);

        $query = $this->buildFilteredQuery($request);

        $statsQuery = $this->buildFilteredQuery($request);
        $stats = [
            'total' => (int) (clone $statsQuery)->count(),
            'accepted' => (int) (clone $statsQuery)->where('processing_status', 'accepted')->count(),
            'validation_failed' => (int) (clone $statsQuery)->where('processing_status', 'validation_failed')->count(),
            'rejected_auth' => (int) (clone $statsQuery)->where('processing_status', 'rejected_auth')->count(),
            'auth_valid' => (int) (clone $statsQuery)->where('auth_valid', true)->count(),
            'auth_invalid' => (int) (clone $statsQuery)->where('auth_valid', false)->count(),
        ];

        $events = $query->latest('received_at')->latest('id')->paginate(25)->withQueryString();

        $trend = $this->buildTrendData($request);

        $filters = [
            'event_type' => (string) $request->input('event_type', ''),
            'user_email' => (string) $request->input('user_email', ''),
            'processing_status' => (string) $request->input('processing_status', ''),
            'auth_valid' => (string) $request->input('auth_valid', ''),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
            'preset' => (string) $request->input('preset', ''),
            'granularity' => strtolower((string) $request->input('granularity', 'daily')),
        ];

        return view('admin.partner-credit-sync-events', compact('events', 'filters', 'stats', 'trend'));
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $this->assertAdmin($request);

        $query = $this->buildFilteredQuery($request)
            ->latest('received_at')
            ->latest('id');

        $filename = 'partner-credit-sync-events-' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'id',
                'received_at',
                'event_type',
                'user_email',
                'credit_balance',
                'credit_cap',
                'reported_status',
                'auth_valid',
                'processing_status',
                'occurred_at',
                'source_ip',
                'error_message',
                'meta_json',
            ]);

            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $event) {
                    fputcsv($out, [
                        (int) $event->id,
                        optional($event->received_at)->format('Y-m-d H:i:s'),
                        (string) ($event->event_type ?? ''),
                        (string) ($event->user_email ?? ''),
                        $event->credit_balance,
                        $event->credit_cap,
                        (string) ($event->reported_status ?? ''),
                        $event->auth_valid ? '1' : '0',
                        (string) ($event->processing_status ?? ''),
                        optional($event->occurred_at)->format('Y-m-d H:i:s'),
                        (string) ($event->source_ip ?? ''),
                        (string) ($event->error_message ?? ''),
                        json_encode($event->meta ?? [], JSON_UNESCAPED_SLASHES),
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    private function buildFilteredQuery(Request $request)
    {
        $query = PartnerCreditSyncEvent::query();

        if ($request->filled('event_type')) {
            $query->where('event_type', (string) $request->input('event_type'));
        }

        if ($request->filled('user_email')) {
            $query->where('user_email', 'like', '%' . trim((string) $request->input('user_email')) . '%');
        }

        if ($request->filled('processing_status')) {
            $query->where('processing_status', (string) $request->input('processing_status'));
        }

        if ($request->filled('auth_valid')) {
            $value = (string) $request->input('auth_valid');
            if ($value === '1' || $value === '0') {
                $query->where('auth_valid', $value === '1');
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('received_at', '>=', (string) $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('received_at', '<=', (string) $request->input('date_to'));
        }

        return $query;
    }

    private function applyDatePreset(Request $request): void
    {
        $preset = strtolower(trim((string) $request->input('preset', '')));
        if ($preset === '') {
            return;
        }

        $today = now()->toDateString();

        if ($preset === 'today') {
            $request->merge([
                'date_from' => $today,
                'date_to' => $today,
            ]);
            return;
        }

        if ($preset === '7d' || $preset === '30d') {
            $days = $preset === '7d' ? 7 : 30;
            $request->merge([
                'date_from' => now()->subDays($days - 1)->toDateString(),
                'date_to' => $today,
            ]);
        }
    }

    private function buildTrendData(Request $request): array
    {
        $dateFrom = trim((string) $request->input('date_from', ''));
        $dateTo = trim((string) $request->input('date_to', ''));
        $granularity = strtolower(trim((string) $request->input('granularity', 'daily')));
        if (!in_array($granularity, ['daily', 'weekly'], true)) {
            $granularity = 'daily';
        }

        $from = $dateFrom !== '' ? Carbon::parse($dateFrom)->startOfDay() : now()->subDays(6)->startOfDay();
        $to = $dateTo !== '' ? Carbon::parse($dateTo)->endOfDay() : now()->endOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        if ($from->diffInDays($to) > 60) {
            $from = $to->copy()->subDays(60)->startOfDay();
        }

        $trendQuery = PartnerCreditSyncEvent::query();

        if ($request->filled('event_type')) {
            $trendQuery->where('event_type', (string) $request->input('event_type'));
        }

        if ($request->filled('user_email')) {
            $trendQuery->where('user_email', 'like', '%' . trim((string) $request->input('user_email')) . '%');
        }

        $trendQuery->whereBetween('received_at', [$from, $to]);

        $rows = $trendQuery
            ->selectRaw('DATE(received_at) as day')
            ->selectRaw("SUM(CASE WHEN processing_status = 'accepted' THEN 1 ELSE 0 END) as accepted_count")
            ->selectRaw("SUM(CASE WHEN processing_status IN ('validation_failed', 'rejected_auth') THEN 1 ELSE 0 END) as failed_count")
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $day = (string) $row->day;
            $byDay[$day] = [
                'accepted' => (int) ($row->accepted_count ?? 0),
                'failed' => (int) ($row->failed_count ?? 0),
            ];
        }

        $points = [];
        $max = 1;

        if ($granularity === 'weekly') {
            $weekly = [];
            $cursor = $from->copy()->startOfDay();
            $end = $to->copy()->startOfDay();

            while ($cursor->lte($end)) {
                $day = $cursor->toDateString();
                $weekStart = $cursor->copy()->startOfWeek()->toDateString();
                if (!isset($weekly[$weekStart])) {
                    $weekly[$weekStart] = [
                        'accepted' => 0,
                        'failed' => 0,
                    ];
                }
                $weekly[$weekStart]['accepted'] += (int) (($byDay[$day]['accepted'] ?? 0));
                $weekly[$weekStart]['failed'] += (int) (($byDay[$day]['failed'] ?? 0));
                $cursor->addDay();
            }

            foreach ($weekly as $weekStart => $data) {
                $start = Carbon::parse($weekStart);
                $endOfWeek = $start->copy()->endOfWeek();
                $accepted = (int) $data['accepted'];
                $failed = (int) $data['failed'];
                $total = max(1, $accepted + $failed);
                $max = max($max, $accepted, $failed);
                $points[] = [
                    'day' => $weekStart,
                    'label' => $start->format('M j') . ' - ' . $endOfWeek->format('M j'),
                    'accepted' => $accepted,
                    'failed' => $failed,
                    'accepted_pct' => (int) round(($accepted / $total) * 100),
                    'failed_pct' => (int) round(($failed / $total) * 100),
                ];
            }
        } else {
            $cursor = $from->copy()->startOfDay();
            $end = $to->copy()->startOfDay();
            while ($cursor->lte($end)) {
                $day = $cursor->toDateString();
                $accepted = (int) (($byDay[$day]['accepted'] ?? 0));
                $failed = (int) (($byDay[$day]['failed'] ?? 0));
                $total = max(1, $accepted + $failed);
                $max = max($max, $accepted, $failed);
                $points[] = [
                    'day' => $day,
                    'label' => $cursor->format('M j'),
                    'accepted' => $accepted,
                    'failed' => $failed,
                    'accepted_pct' => (int) round(($accepted / $total) * 100),
                    'failed_pct' => (int) round(($failed / $total) * 100),
                ];
                $cursor->addDay();
            }
        }

        return [
            'points' => $points,
            'max' => $max,
            'granularity' => $granularity,
        ];
    }

    private function assertAdmin(Request $request): void
    {
        $email = strtolower((string) $request->session()->get('user_email', ''));
        $allowed = array_values(array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            explode(',', (string) config('services.partner.audit_admin_emails', 'admin@rcsn.com')),
        )));

        abort_unless($email !== '' && in_array($email, $allowed, true), 403);
    }
}
