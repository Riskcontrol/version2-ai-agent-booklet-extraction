<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Verify Certificate &amp; Report — Risk Control Services Nigeria</title>
    @vite(['resources/css/app.css', 'resources/js/verification.js'])
</head>
<body class="bg-green-50 text-gray-900 font-sans">
<header class="bg-gradient-to-r from-lime-500 to-lime-300 border-b-4 border-lime-600">
    <div class="max-w-7xl mx-auto px-4 py-4 flex flex-wrap justify-between items-center gap-4">
        <div><h1 class="text-lg font-semibold">Risk Control Services Nigeria</h1><p class="text-sm">Verify Certificate &amp; Report</p></div>
        <nav class="flex flex-wrap gap-2" aria-label="Main navigation">
            @foreach(['dashboard' => 'Convocation', 'certificates' => 'Certificates', 'all-certificates' => 'All Certificate', 'verification' => 'Verify Certificate & Report', 'topup' => 'Top up', 'logs' => 'Logs', 'settings' => 'Settings'] as $route => $label)
                <a href="{{ route($route) }}" class="text-sm px-3 py-2 rounded-lg {{ $route === 'verification' ? 'bg-[#0a2912] text-white' : 'bg-white/30 hover:bg-white/50' }}">{{ $label }}</a>
            @endforeach
        </nav>
    </div>
</header>
<main class="max-w-7xl mx-auto px-4 py-6 space-y-6">
    <section class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm">
        <h2 class="text-xl font-semibold">Upload certificates and reports</h2>
        <p class="text-sm text-gray-600 mt-2">Upload one ZIP containing your certificate PDFs and report PDFs. Files can be in any order or in subfolders. We match each qualification separately.</p>
        <p class="text-sm text-gray-600 mt-2">One credit per processed PDF page, including supporting pages. Check the inventory and credit total before starting. Failed uploads or processing cannot resume; upload again as a new paid batch.</p>
        <p id="balance" class="text-sm mt-3" aria-live="polite">Loading available credits…</p>
        <form id="zipForm" class="mt-4 flex flex-col sm:flex-row sm:items-end gap-3">
            <div class="flex-1 min-w-0"><label for="zipFile" class="block text-sm font-medium mb-2">ZIP file</label><input id="zipFile" name="file" type="file" accept=".zip,application/zip" required class="w-full rounded-lg border border-gray-300 p-2"></div>
            <button id="uploadZip" class="px-5 py-3 rounded-lg bg-lime-500 font-semibold disabled:opacity-50">Upload and inspect ZIP</button>
        </form>
        <progress id="uploadProgress" max="100" value="0" class="hidden w-full mt-3" aria-label="ZIP upload progress"></progress>
        <p id="uploadMessage" class="text-sm mt-3" role="status"></p>
    </section>
    <section class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-xl font-semibold">Your batches</h2><button id="refreshBatches" class="px-3 py-2 border rounded-lg text-sm">Refresh list</button></div>
        <div id="batches" class="mt-3 space-y-2"></div>
        <div class="flex gap-3 mt-3"><button id="previousBatches" class="text-sm underline disabled:opacity-40">Previous</button><span id="batchPage" class="text-sm"></span><button id="nextBatches" class="text-sm underline disabled:opacity-40">Next</button></div>
    </section>
    <section id="batchPanel" class="hidden bg-white border border-gray-200 rounded-xl p-5 shadow-sm space-y-4">
        <div class="flex flex-wrap justify-between items-center gap-3"><h2 id="batchTitle" class="text-xl font-semibold"></h2><button id="refreshBatch" class="px-3 py-2 border rounded-lg text-sm">Refresh status</button></div>
        <p id="batchStatus" class="text-sm" role="status"></p>
        <p id="batchFailure" class="text-sm text-red-700"></p>
        <div id="inventorySummary" class="rounded-lg bg-lime-50 border border-lime-100 p-4 text-sm"></div>
        <button id="startBatch" class="hidden px-5 py-3 rounded-lg bg-lime-500 font-semibold disabled:opacity-50">Confirm credits and start comparison</button>
        <details><summary class="cursor-pointer text-sm font-medium">Inventory and processing notices</summary><ul id="notices" class="list-disc pl-5 mt-2 text-sm space-y-1"></ul><ul id="inventory" class="mt-3 text-sm space-y-1 max-h-64 overflow-auto"></ul></details>
        <div id="resultCounts" class="flex flex-wrap gap-4 text-sm"></div>
        <div id="downloads" class="hidden flex flex-wrap gap-3"><a id="downloadCsv" class="px-3 py-2 border rounded-lg text-sm">Download CSV</a><a id="downloadXlsx" class="px-3 py-2 border rounded-lg text-sm">Download Excel</a></div>
        <form id="resultFilters" class="flex flex-wrap gap-3 items-end">
            <div><label for="searchResults" class="block text-sm mb-1">Name or certificate filename</label><input id="searchResults" class="border rounded-lg px-3 py-2" type="search"></div>
            <div><label for="statusFilter" class="block text-sm mb-1">Result</label><select id="statusFilter" class="border rounded-lg px-3 py-2"><option value="">All results</option>Yes</option><option>Disparity</option><option>Needs review</option></select></div>
            <button class="border rounded-lg px-4 py-2">Search</button>
        </form>
        <div class="overflow-x-auto"><table class="w-full text-sm text-left"><thead><tr class="bg-lime-50"><th class="p-3">Certificate holder</th><th class="p-3">Qualification</th><th class="p-3">Report holder</th><th class="p-3">Result</th><th class="p-3">Details</th><th class="p-3">Review</th></tr></thead><tbody id="results"></tbody></table></div>
        <div class="flex gap-3"><button id="previousResults" class="text-sm underline disabled:opacity-40">Previous</button><span id="resultPage" class="text-sm"></span><button id="nextResults" class="text-sm underline disabled:opacity-40">Next</button></div>
        <details id="unmatchedPanel"><summary class="cursor-pointer font-medium">Reports without a paired certificate</summary><div id="unmatchedReports" class="text-sm mt-3 space-y-2"></div><div class="flex gap-3 mt-3"><button id="previousUnmatched" class="underline text-sm disabled:opacity-40">Previous</button><span id="unmatchedPage" class="text-sm"></span><button id="nextUnmatched" class="underline text-sm disabled:opacity-40">Next</button></div></details>
        <details id="auditPanel"><summary class="cursor-pointer font-medium">Pairing correction history</summary><div id="auditHistory" class="text-sm mt-3 space-y-2"></div><div class="flex gap-3 mt-3"><button id="previousAudits" class="underline text-sm disabled:opacity-40">Previous</button><span id="auditPage" class="text-sm"></span><button id="nextAudits" class="underline text-sm disabled:opacity-40">Next</button></div></details>
        <p class="text-xs text-gray-500">Yes means the certificate details agree with the supplied report.</p>
    </section>
    <p id="pageMessage" class="text-sm text-red-700" role="alert"></p>
</main>
<dialog id="reviewDialog" class="m-auto rounded-xl border border-gray-200 p-5 w-[96vw] max-w-7xl max-h-[95vh] overflow-y-auto">
    <div class="sticky top-0 bg-white z-10 flex justify-between items-center gap-3 pb-3 border-b"><h2 id="reviewTitle" class="text-xl font-semibold">Review pairing</h2><button id="closeReview" class="border rounded-lg px-4 py-2">Close</button></div>
    <p id="reviewReason" class="text-sm mt-2"></p>
    <div class="grid md:grid-cols-2 gap-4 mt-4">
        <section><h3 class="font-semibold">Certificate</h3><p id="certificateSource" class="text-xs mt-1"></p><a id="openCertificate" target="_blank" rel="noopener" class="text-sm underline">Open PDF in new tab</a><iframe id="certificatePreview" title="Original certificate PDF" class="w-full border rounded-lg mt-2 h-80"></iframe></section>
        <section><h3 class="font-semibold">Report</h3><p id="reportSource" class="text-xs mt-1"></p><a id="openReport" target="_blank" rel="noopener" class="text-sm underline">Open PDF in new tab</a><iframe id="reportPreview" title="Original report PDF" class="w-full border rounded-lg mt-2 h-80"></iframe></section>
    </div>
    <h3 class="font-semibold mt-4">Saved comparison</h3>
    <p class="text-xs text-gray-500">Updated after you save a pairing correction.</p>
    <div class="overflow-x-auto mt-2"><table class="w-full text-sm text-left"><thead><tr class="bg-lime-50"><th class="p-2">Field</th><th class="p-2">Certificate</th><th class="p-2">Report</th><th class="p-2">Comparison</th></tr></thead><tbody id="fieldComparisons"></tbody></table></div>
    <h3 class="font-semibold mt-4">Choose a report or correct this pairing</h3>
    <p class="text-xs text-gray-500 mt-1">Uses already extracted values. No new model request or extraction charge.</p>
    <div id="suggestedReports" class="mt-2 text-sm space-y-2"></div>
    <form id="reportSearchForm" class="flex flex-wrap gap-2 mt-3"><label for="reportSearch" class="sr-only">Search report holders or filenames</label><input id="reportSearch" type="search" placeholder="Report holder or filename" class="border rounded-lg px-3 py-2 flex-1 min-w-0"><button class="border rounded-lg px-4 py-2">Find reports</button></form>
    <div id="reportOptions" class="mt-3 text-sm space-y-2"></div><div class="flex gap-3 mt-2"><button id="previousReportOptions" class="text-sm underline disabled:opacity-40">Previous</button><span id="reportOptionsPage" class="text-sm"></span><button id="nextReportOptions" class="text-sm underline disabled:opacity-40">Next</button></div>
    <form id="pairingForm" class="mt-4 space-y-3">
        <p id="selectedReport" class="text-sm font-medium"></p><button id="unpairReport" type="button" class="text-sm underline">Leave certificate unpaired</button>
        <label for="pairingNote" class="block text-sm font-medium">Reason for pairing correction</label><textarea id="pairingNote" required maxlength="2000" class="border rounded-lg p-3 w-full" rows="2"></textarea>
        <button id="savePairing" class="px-5 py-3 bg-lime-500 rounded-lg font-semibold disabled:opacity-50">Save pairing and compare stored values</button>
    </form>
    <p id="reviewMessage" class="text-sm text-red-700 mt-3" role="alert"></p>
</dialog>
</body>
</html>
