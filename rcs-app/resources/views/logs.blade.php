<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Risk Control Services Nigeria — Extraction Logs</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css','resources/js/logs.js'])
</head>
<body class="bg-green-50 text-gray-900 font-sans">
    <header class="bg-gradient-to-r from-lime-500 to-lime-300 text-[#0a2912] border-b-4 border-lime-600">
        <div class="max-w-5xl mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-[#0a2912] text-white flex items-center justify-center font-bold">RC</div>
                    <div>
                        <h1 class="m-0 text-lg font-semibold">Risk Control Services Nigeria</h1>
                        <p class="m-0 text-xs opacity-90">Extraction Logs</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <nav class="flex gap-2">
                        <a href="{{ route('dashboard') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Convocation</a>
                        <a href="{{ route('certificates') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Certificates</a>
                        <a href="{{ route('topup') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Top up</a>
                        <a href="{{ route('logs') }}" class="text-sm px-3 py-2 rounded-lg bg-[#0a2912] text-white transition font-medium">Logs</a>
                        <a href="{{ route('settings') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Settings</a>
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

    <main class="max-w-5xl mx-auto px-4 py-6">

        {{-- Filter form --}}
        <section class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm mb-6">
            <h2 class="text-xl font-semibold mb-3">Filter Extraction Logs</h2>
            <form id="filterForm" class="grid grid-cols-1 md:grid-cols-5 gap-3 mb-4">
                <input id="filter_q" type="text" placeholder="Search filename, session, request ID" class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500 md:col-span-2" />
                <select id="filter_status" class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500">
                    <option value="">All statuses</option>
                    <option value="processing">Processing</option>
                    <option value="complete">Complete</option>
                </select>
                <select id="filter_credit_status" class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500">
                    <option value="">All credit states</option>
                    <option value="authorized">Authorized</option>
                    <option value="success">Success</option>
                    <option value="failed">Failed</option>
                    <option value="refunded">Refunded</option>
                </select>
                <div class="flex gap-2 md:col-span-1">
                    <button type="submit" class="flex-1 rounded-lg bg-[#0a2912] px-3 py-2 text-sm font-medium text-white">Apply</button>
                    <button id="resetFiltersBtn" type="button" class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700">Reset</button>
                </div>
                <input id="filter_date_from" type="date" class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500" />
                <input id="filter_date_to" type="date" class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500" />
                <input id="filter_user" type="text" placeholder="User email" class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500" />
                <input id="filter_request_id" type="text" placeholder="Partner request ID" class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500" />
                <input id="filter_payment_reference" type="text" placeholder="Payment reference" class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500" />
                <select id="filter_extraction_type" class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500">
                    <option value="">All types</option>
                    <option value="convocation">Convocation</option>
                    <option value="certificate">Certificate</option>
                </select>
            </form>

            {{-- Summary stat cards --}}
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5 mb-4">
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Filtered Logs</div>
                    <div id="summary_filtered_documents" class="mt-1 text-2xl font-semibold text-gray-900">0</div>
                    <div id="summary_filtered_pages" class="text-xs text-gray-500">0 pages</div>
                </div>
                <div class="rounded-lg border border-lime-100 bg-lime-50 p-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Booklet Success</div>
                    <div id="summary_booklet_successful" class="mt-1 text-sm font-semibold text-[#0a2912]">Day 0 | Month 0 | All 0</div>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white p-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Booklet Pages</div>
                    <div id="summary_booklet_pages" class="mt-1 text-sm font-semibold text-gray-900">Day 0 | Month 0 | All 0</div>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white p-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Student Rows</div>
                    <div id="summary_booklet_student_rows" class="mt-1 text-sm font-semibold text-gray-900">Day 0 | Month 0 | All 0</div>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white p-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Certificate Pages</div>
                    <div id="summary_certificate_pages" class="mt-1 text-sm font-semibold text-gray-900">Day 0 | Month 0 | All 0</div>
                </div>
            </div>

            <div id="logsMsg" class="text-sm text-gray-600 mb-3"></div>

            {{-- Documents table --}}
            <div class="overflow-auto">
                <table class="w-full text-sm border-collapse" id="docsTable">
                    <thead>
                        <tr class="bg-gray-50 text-gray-900">
                            <th class="text-left p-2 border-b">ID</th>
                            <th class="text-left p-2 border-b">Filename</th>
                            <th class="text-left p-2 border-b">Session</th>
                            <th class="text-left p-2 border-b">Status</th>
                            <th class="text-left p-2 border-b">Credit</th>
                            <th class="text-left p-2 border-b">Pages</th>
                            <th class="text-left p-2 border-b">Payment Ref</th>
                            <th class="text-left p-2 border-b">Partner Request</th>
                            <th class="text-left p-2 border-b">CSV</th>
                            <th class="text-left p-2 border-b">XLSX</th>
                            <th class="text-left p-2 border-b">Type</th>
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
        window.rcsFeatureFlags = {
            trackingUiEnabled: @json((bool) config('services.partner.tracking_ui_enabled', true)),
        };
        document.getElementById('year').textContent = new Date().getFullYear();
    </script>
</body>
</html>
