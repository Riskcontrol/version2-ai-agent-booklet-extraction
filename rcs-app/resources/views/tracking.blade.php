<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Risk Control Services Nigeria — Extraction Tracking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css','resources/js/tracking.js'])
</head>
<body class="bg-green-50 text-gray-900 font-sans">
    <header class="bg-gradient-to-r from-lime-500 to-lime-300 text-[#0a2912] border-b-4 border-lime-600">
        <div class="max-w-5xl mx-auto px-4 py-4">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-[#0a2912] text-white flex items-center justify-center font-bold">RC</div>
                    <div>
                        <h1 class="m-0 text-lg font-semibold">Risk Control Services Nigeria</h1>
                        <p class="m-0 text-xs opacity-90">Extraction Tracking</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('dashboard') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Convocation</a>
                    <a href="{{ route('logs') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Logs</a>
                    <a href="{{ route('topup') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Top up</a>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 py-6">
        <section class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm mb-6">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">Tracking</p>
                    <h2 id="trackingFilename" class="text-xl font-semibold text-gray-900 mt-1">{{ $document->filename }}</h2>
                    <p id="trackingMeta" class="text-sm text-gray-600 mt-1">Document ID {{ $document->id }} | Partner Request {{ $document->partner_request_id ?: 'N/A' }}</p>
                </div>
                <a href="{{ route('logs') }}?request_id={{ urlencode((string) $document->partner_request_id) }}" class="text-sm px-3 py-2 rounded-lg bg-[#0a2912] text-white">Open in logs</a>
            </div>

            <div class="grid gap-3 md:grid-cols-4 mt-4">
                <div class="rounded-lg bg-lime-50 border border-lime-100 p-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Phase</div>
                    <div id="trackingPhase" class="mt-1 text-lg font-semibold text-[#0a2912]">Loading</div>
                </div>
                <div class="rounded-lg bg-gray-50 border border-gray-200 p-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Status</div>
                    <div id="trackingStatus" class="mt-1 text-lg font-semibold text-gray-900">—</div>
                </div>
                <div class="rounded-lg bg-gray-50 border border-gray-200 p-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Pages</div>
                    <div id="trackingPages" class="mt-1 text-lg font-semibold text-gray-900">0 / 0</div>
                </div>
                <div class="rounded-lg bg-gray-50 border border-gray-200 p-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Credit</div>
                    <div id="trackingCredit" class="mt-1 text-lg font-semibold text-gray-900">—</div>
                </div>
            </div>

            <div class="mt-5">
                <div class="w-full bg-gray-200 rounded-full h-3">
                    <div id="trackingProgressBar" class="bg-lime-500 h-3 rounded-full transition-all duration-300" style="width: 0%"></div>
                </div>
                <p id="trackingProgressText" class="text-sm text-gray-600 mt-2">Waiting for tracking update...</p>
            </div>

            <div id="trackingError" class="mt-3 text-sm text-red-600 hidden"></div>
        </section>

        <section class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm mb-6">
            <h2 class="text-xl font-semibold">Timeline</h2>
            <div id="trackingTimeline" class="mt-4 space-y-3 text-sm text-gray-700"></div>
        </section>

        <section class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm">
            <h2 class="text-xl font-semibold">Downloads</h2>
            <div id="trackingDownloads" class="mt-4 flex flex-wrap gap-3"></div>
        </section>
    </main>

    <script>
        window.trackingConfig = {
            documentId: {{ $document->id }},
            endpoint: @json(url("/api/documents/{$document->id}/tracking")),
        }
    </script>
</body>
</html>
