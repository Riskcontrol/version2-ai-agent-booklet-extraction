<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Risk Control Services Nigeria — Certificates Extractor</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css','resources/js/certificates.js'])
</head>
<body class="bg-green-50 text-gray-900 font-sans">
    <header class="bg-gradient-to-r from-lime-500 to-lime-300 text-[#0a2912] border-b-4 border-lime-600">
        <div class="max-w-5xl mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-[#0a2912] text-white flex items-center justify-center font-bold">RC</div>
                    <div>
                        <h1 class="m-0 text-lg font-semibold">Risk Control Services Nigeria</h1>
                        <p class="m-0 text-xs opacity-90">Certificates Extraction Console</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <nav class="flex gap-2">
                        <a href="{{ route('dashboard') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Convocation</a>
                        <a href="{{ route('certificates') }}" class="text-sm px-3 py-2 rounded-lg bg-[#0a2912] text-white transition font-medium">Certificates</a>
                        <a href="{{ route('topup') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Top up</a>
                        <a href="{{ route('logs') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Logs</a>
                        @if(in_array(strtolower((string) session('user_email')), array_map('trim', explode(',', strtolower((string) config('services.partner.audit_admin_emails', 'admin@rcsn.com')))), true))
                            <a href="{{ route('admin.partnerCreditSyncEvents') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Sync Audit</a>
                        @endif
                    </nav>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-sm px-4 py-2 bg-[#0a2912] text-white rounded-lg hover:bg-opacity-90 transition">
                            Logout
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    @php
        $uploadLimitRaw = ini_get('upload_max_filesize') ?: '40M';
        $postLimitRaw = ini_get('post_max_size') ?: '40M';
        preg_match('/^(\d+)/', $uploadLimitRaw, $uploadMatch);
        preg_match('/^(\d+)/', $postLimitRaw, $postMatch);
        $uploadLimitMb = isset($uploadMatch[1]) ? (int) $uploadMatch[1] : 40;
        $postLimitMb = isset($postMatch[1]) ? (int) $postMatch[1] : 40;
        $effectiveUploadLimitMb = max(1, min($uploadLimitMb, $postLimitMb));
    @endphp

    <main class="max-w-5xl mx-auto px-4 py-6">
        <section class="grid gap-4 md:grid-cols-4 mb-6">
            <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm md:col-span-3">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">Billing Mirror</p>
                        <h2 class="text-xl font-semibold text-gray-900 mt-1">Read-only credit balance</h2>
                        <p class="text-sm text-gray-600 mt-1">Certificate uploads reserve credits on Google Cloud before the GitHub workflow starts and settle them after result ingestion.</p>
                        <div id="creditSummaryMsg" class="mt-2 text-xs text-gray-500">Loading credit summary...</div>
                    </div>
                    <div class="text-right">
                        <div id="billingAuthority" class="text-xs text-gray-500">Google Cloud</div>
                        <div id="creditBalance" class="text-3xl font-bold text-[#0a2912] mt-2">0</div>
                        <div class="text-xs uppercase tracking-wide text-gray-500">Available credits</div>
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="rounded-lg bg-lime-50 border border-lime-100 p-3">
                        <div class="text-xs uppercase tracking-wide text-gray-500">Balance value</div>
                        <div id="creditValue" class="mt-1 text-lg font-semibold text-[#0a2912]">NGN 0</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 border border-gray-200 p-3">
                        <div class="text-xs uppercase tracking-wide text-gray-500">Current billing rate</div>
                        <div id="creditUnitPrice" class="mt-1 text-lg font-semibold text-gray-900">NGN 0 per page</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm mb-6">
            <h2 class="text-xl font-semibold mb-4">Upload Certificates PDF</h2>
            <form id="uploadForm" class="space-y-3" method="POST" action="javascript:void(0);" onsubmit="return false;" data-max-upload-mb="{{ $effectiveUploadLimitMb }}">
                @csrf
                <div class="flex flex-col gap-2">
                    <label for="file" class="font-medium">PDF File</label>
                    <input id="file" name="file" type="file" accept="application/pdf" required class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500" />
                    <small class="text-gray-500 text-xs">Server upload limit: {{ $effectiveUploadLimitMb }}MB (PDF only)</small>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="flex flex-col gap-2">
                        <label for="date_received" class="font-medium">Date Received <span class="text-gray-400 font-normal text-xs">(optional)</span></label>
                        <input id="date_received" name="date_received" type="text" placeholder="e.g. 01/01/2024" class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <label for="completed_date" class="font-medium">Completed Date <span class="text-gray-400 font-normal text-xs">(optional)</span></label>
                        <input id="completed_date" name="completed_date" type="text" placeholder="e.g. 15/03/2024" class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <label for="client_name" class="font-medium">Client Name <span class="text-gray-400 font-normal text-xs">(optional)</span></label>
                        <input id="client_name" name="client_name" type="text" placeholder="e.g. First Bank PLC" class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500" />
                    </div>
                </div>

                <div class="rounded-lg bg-lime-50 border border-lime-100 p-3 text-sm text-gray-700">
                    Workflow key control is enforced by Google Cloud. This portal submits workload metadata only, and certificate dispatch runs only after partner approval for the paid tier.
                </div>

                <div class="rounded-lg border border-lime-100 bg-lime-50 p-3 text-sm text-gray-700">
                    <div>Total pages in file: <span id="totalPages" class="font-semibold">-</span></div>
                    <div>Pages selected: <span id="pagesSelected" class="font-semibold">-</span></div>
                    <div>Needed credits (1 credit = 1 page): <span id="neededCredits" class="font-semibold">-</span></div>
                </div>
                <div id="creditGateMsg" class="text-sm text-gray-700 hidden"></div>

                <!-- Progress bar -->
                <div id="uploadProgress" class="hidden">
                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                        <div id="progressBar" class="bg-lime-500 h-2.5 rounded-full transition-all duration-300" style="width: 0%"></div>
                    </div>
                    <p id="progressText" class="text-sm text-gray-600 mt-2 text-center">Uploading...</p>
                </div>

                <button id="uploadBtn" type="submit" disabled class="w-full py-3 bg-lime-500 text-[#0a2912] font-semibold rounded-lg hover:bg-lime-600 transition disabled:opacity-50 disabled:cursor-not-allowed">
                    Upload and Extract
                </button>
            </form>
            <div id="uploadMsg" class="mt-3 text-sm"></div>
        </section>

        <input id="filter_extraction_type" type="hidden" value="certificates" />

        <section class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm mb-6">
            <h2 class="text-xl font-semibold mb-3">Uploaded Documents</h2>
            <div class="overflow-auto">
                <table class="w-full text-sm border-collapse" id="docsTable">
                    <thead>
                        <tr class="bg-gray-50 text-gray-900">
                            <th class="text-left p-2 border-b">ID</th>
                            <th class="text-left p-2 border-b">Filename</th>
                            <th class="text-left p-2 border-b">Client Name</th>
                            <th class="text-left p-2 border-b">Date Received</th>
                            <th class="text-left p-2 border-b">Completed Date</th>
                            <th class="text-left p-2 border-b">Status</th>
                            <th class="text-left p-2 border-b">Credit</th>
                            <th class="text-left p-2 border-b">Pages</th>
                            <th class="text-left p-2 border-b">Payment Ref</th>
                            <th class="text-left p-2 border-b">Partner Request</th>
                            <th class="text-left p-2 border-b">CSV</th>
                            <th class="text-left p-2 border-b">XLSX</th>
                            <th class="text-left p-2 border-b">Created</th>
                            <th class="text-left p-2 border-b">Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </section>

    </main>

    <footer class="text-center text-green-900 py-6">
        <div class="max-w-5xl mx-auto px-4">
            <small>© <span id="year"></span> Risk Control Services Nigeria</small>
        </div>
    </footer>

    <script>
        document.getElementById('year').textContent = new Date().getFullYear();
    </script>
</body>
</html>
