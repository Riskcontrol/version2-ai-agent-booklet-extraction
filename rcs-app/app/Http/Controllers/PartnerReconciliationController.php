<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\VerificationBatch;
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
        $verification = VerificationBatch::whereBetween('created_at', [$from, $to])
            ->whereIn('status', ['complete', 'failed']);
        // Comparison work is authorized as certificates. Include successful
        // pages retained from partial failures, without counting reserved pages.
        $verificationPages = (int) (clone $verification)->sum('pages_processed');
        $completedVerification = (clone $verification)->where('status', 'complete');
        $verificationFiles = (int) (clone $completedVerification)->sum('files_processed');

        return response()->json([
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'processed_pages_total' => $this->sumResolvedPages(clone $base) + $verificationPages,
            'booklet_pages_total' => $this->sumResolvedPages(clone $booklet),
            'certificate_pages_total' => $this->sumResolvedPages(clone $certificates) + $verificationPages,
            'completed_documents_total' => (int) (clone $base)->count() + $verificationFiles,
            'completed_booklets_total' => (int) (clone $booklet)->count(),
            'completed_certificates_total' => (int) (clone $certificates)->count() + $verificationFiles,
            'verification_pages_total' => $verificationPages,
            'completed_verification_batches_total' => (int) (clone $completedVerification)->count(),
        ]);
    }
}
