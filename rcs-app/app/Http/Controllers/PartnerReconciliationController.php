<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PartnerReconciliationController extends Controller
{
    private function assertPartnerToken(Request $request): void
    {
        $expectedToken = (string) config('services.partner.token', '');
        $providedToken = (string) $request->header('X-Partner-Token', '');

        if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            abort(401);
        }
    }

    private function resolveRange(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        $from = isset($validated['date_from'])
            ? Carbon::parse((string) $validated['date_from'])->startOfDay()
            : now()->startOfMonth();
        $to = isset($validated['date_to'])
            ? Carbon::parse((string) $validated['date_to'])->endOfDay()
            : now()->endOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    private function sumResolvedPages($query): int
    {
        return (int) ($query->selectRaw('COALESCE(SUM(COALESCE(NULLIF(pages_processed, 0), pages_requested, 0)), 0) as aggregate')->value('aggregate') ?? 0);
    }

    public function summary(Request $request)
    {
        $this->assertPartnerToken($request);
        [$from, $to] = $this->resolveRange($request);

        $base = Document::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('status', 'complete');

        $booklet = (clone $base)->where(function ($query) {
            $query->whereNull('extraction_type')->orWhere('extraction_type', 'convocation');
        });
        $certificates = (clone $base)->where('extraction_type', 'certificates');

        return response()->json([
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'processed_pages_total' => $this->sumResolvedPages(clone $base),
            'booklet_pages_total' => $this->sumResolvedPages(clone $booklet),
            'certificate_pages_total' => $this->sumResolvedPages(clone $certificates),
            'completed_documents_total' => (int) (clone $base)->count(),
            'completed_booklets_total' => (int) (clone $booklet)->count(),
            'completed_certificates_total' => (int) (clone $certificates)->count(),
        ]);
    }
}