<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\GithubController;
use App\Http\Controllers\PartnerBillingWebhookController;
use App\Http\Controllers\PartnerCreditController;
use App\Http\Controllers\PartnerReconciliationController;
use App\Http\Controllers\PartnerTrackingController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\VerificationWorkerController;

// These routes rely on session-based auth (CheckAuth uses Session::get('authenticated')).
// API routes do not include session middleware by default, so we explicitly enable the `web`
// middleware group for the authenticated endpoints.
Route::middleware(['web', 'App\Http\Middleware\CheckAuth'])->group(function () {
    Route::post('/upload', [DocumentController::class, 'upload']);
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::delete('/documents/{doc}', [DocumentController::class, 'delete']);
    Route::get('/documents/{doc}/tracking', [PartnerTrackingController::class, 'progress']);
    Route::get('/credit-summary', [PartnerCreditController::class, 'summary']);
    Route::get('/payment-history', [PartnerCreditController::class, 'paymentHistory']);
    Route::post('/top-up/paystack/initialize', [PartnerCreditController::class, 'paystackInitialize']);
    Route::post('/top-up/paystack/verify', [PartnerCreditController::class, 'paystackVerify']);

    // Phase 7 cutover: freeze any direct top-up paths in RiskControl once hard-block is active.
    Route::post('/top-up', function () {
        $freeze = (bool) config('services.partner.freeze_direct_topup', true);
        $mode = \App\Services\PartnerIntegrationModeService::effectiveMode();
        if ($freeze && $mode === 'hard_block') {
            return response()->json([
                'error' => 'Direct top-up in RiskControl is disabled after cutover. Use Peldarg billing portal.',
            ], 410);
        }

        return response()->json(['error' => 'Top-up endpoint not available in RiskControl.'], 404);
    });

    // Certificates
    Route::post('/certificates/upload', [DocumentController::class, 'uploadCertificates']);
    Route::get('/certificates', [DocumentController::class, 'indexCertificates']);
    Route::delete('/certificates/{doc}', [DocumentController::class, 'deleteCertificate']);
    Route::post('/all-certificates/upload', [DocumentController::class, 'uploadAllCertificates']);
    Route::get('/all-certificates', [DocumentController::class, 'indexAllCertificates']);

    Route::get('/verifications', [VerificationController::class, 'index']);
    Route::post('/verifications', [VerificationController::class, 'create']);
    Route::post('/verifications/{batch}/chunks', [VerificationController::class, 'chunk']);
    Route::post('/verifications/{batch}/finish-upload', [VerificationController::class, 'finishUpload']);
    Route::get('/verifications/{batch}', [VerificationController::class, 'show']);
    Route::post('/verifications/{batch}/start', [VerificationController::class, 'start']);
    Route::get('/verifications/{batch}/results', [VerificationController::class, 'results']);
    Route::get('/verifications/{batch}/records', [VerificationController::class, 'records']);
    Route::post('/verifications/{batch}/pairings/{result}', [VerificationController::class, 'pairing']);
    Route::get('/verifications/{batch}/audits', [VerificationController::class, 'audits']);
    Route::get('/verifications/{batch}/sources/{source}', [VerificationController::class, 'source']);
    Route::get('/verifications/{batch}/export/{format}', [VerificationController::class, 'export'])->where('format', 'csv|xlsx');
});

Route::get('/verification-worker/{batch}/archive', [VerificationController::class, 'archive'])
    ->name('verification.archive')->middleware('signed');
Route::post('/verification-worker/{batch}/callback', [VerificationWorkerController::class, 'callback'])->name('verification.callback');

Route::get('/download/{doc}', [DocumentController::class, 'download'])
    ->name('documents.download')
    ->middleware('signed');
Route::get('/download-output/{doc}/{type}', [DocumentController::class, 'downloadOutput'])
    ->name('documents.downloadOutput')
    ->where('type', 'csv|xlsx')
    ->middleware('signed');

Route::post('/github/callback', [GithubController::class, 'callback'])->name('github.callback');
Route::post('/github/upload-results', [GithubController::class, 'uploadResults'])->name('github.uploadResults');

// Phase 6: Security hardening for partner webhooks (machine-to-machine)
// Requires: X-Partner-Name, X-Partner-Signature, X-Partner-Timestamp, X-Partner-Nonce headers
// Validates: HMAC signature, timestamp freshness, nonce uniqueness, and idempotency
Route::middleware([
    'App\Http\Middleware\ValidatePartnerSignature',
    'App\Http\Middleware\TrackIncomingIdempotency',
])->group(function () {
    Route::post('/partner/credit-updated', [PartnerBillingWebhookController::class, 'creditUpdated']);
    Route::post('/partner/reconciliation-summary', [PartnerReconciliationController::class, 'summary']);
});
