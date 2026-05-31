<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Risk Control Services Nigeria - Top Up</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css','resources/js/topup.js'])
</head>
<body class="bg-green-50 text-gray-900 font-sans">
    <header class="bg-gradient-to-r from-lime-500 to-lime-300 text-[#0a2912] border-b-4 border-lime-600">
        <div class="max-w-5xl mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-[#0a2912] text-white flex items-center justify-center font-bold">RC</div>
                    <div>
                        <h1 class="m-0 text-lg font-semibold">Risk Control Services Nigeria</h1>
                        <p class="m-0 text-xs opacity-90">Top-up & Payment Console</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <nav class="flex gap-2">
                        <a href="{{ route('dashboard') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Convocation</a>
                        <a href="{{ route('certificates') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Certificates</a>
                        <a href="{{ route('topup') }}" class="text-sm px-3 py-2 rounded-lg bg-[#0a2912] text-white transition font-medium">Top up</a>
                        <a href="{{ route('logs') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Logs</a>
                        <a href="{{ route('settings') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Settings</a>
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
        <section class="grid gap-4 md:grid-cols-3 mb-6">
            <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm md:col-span-2">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">Billing Mirror</p>
                        <h2 class="text-xl font-semibold text-gray-900 mt-1">Read-only credit balance</h2>
                        <p class="text-sm text-gray-600 mt-1">Paystack is configured in Google Cloud. Checkout runs here as inline popup while verification remains in Google Cloud.</p>
                        <div id="creditSummaryMsg" class="mt-2 text-xs text-gray-500">Loading credit summary...</div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-gray-500">Billing authority</div>
                        <div id="billingAuthority" class="text-xs font-medium text-[#0a2912]">Google Cloud</div>
                        <div id="creditBalance" class="text-3xl font-bold text-[#0a2912] mt-2">0</div>
                        <div class="text-xs uppercase tracking-wide text-gray-500">Available credits</div>
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="rounded-lg bg-lime-50 border border-lime-100 p-3">
                        <div class="text-xs uppercase tracking-wide text-gray-500">Unit price (USD)</div>
                        <div id="unitPriceUsd" class="mt-1 text-lg font-semibold text-[#0a2912]">0.00</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 border border-gray-200 p-3">
                        <div class="text-xs uppercase tracking-wide text-gray-500">FX (NGN / USD)</div>
                        <div id="fxRateNgn" class="mt-1 text-lg font-semibold text-gray-900">0</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 border border-gray-200 p-3">
                        <div class="text-xs uppercase tracking-wide text-gray-500">1 credit in NGN</div>
                        <div id="unitPriceNgn" class="mt-1 text-lg font-semibold text-gray-900">₦0</div>
                    </div>
                </div>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
                <h2 class="text-xl font-semibold">Pricing note</h2>
                <p class="text-sm text-gray-600 mt-1">Current expected rate:</p>
                <ul class="mt-2 text-sm text-gray-700 space-y-1">
                    <li>1 USD = <span id="pricingNoteFx">0</span> NGN</li>
                    <li>1 credit = <span id="pricingNoteUnitUsd">0.00</span> USD</li>
                    <li>Expected = <span id="pricingNoteUnitNgn">0</span> NGN per credit</li>
                </ul>
                <p class="text-xs text-gray-500 mt-3">Live values below use mirrored pricing from Peldarg billing settings.</p>
            </div>
        </section>

        <section class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm mb-6">
            <h2 class="text-xl font-semibold">Top up credits</h2>
            <p class="text-sm text-gray-600 mt-1">Enter credits and amount will auto-calculate in USD and NGN before checkout.</p>

            <form id="topUpForm" class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4" method="POST" action="javascript:void(0);" onsubmit="return false;">
                @csrf
                <div>
                    <label for="requested_credits" class="block text-sm font-medium text-gray-700 mb-1">Requested credits</label>
                    <input id="requested_credits" name="requested_credits" type="number" min="1" step="1" required class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500" placeholder="e.g. 500" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Estimated amount (USD)</label>
                    <div id="topUpAmountUsd" class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-gray-900 font-semibold">$0.00</div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Estimated amount (NGN)</label>
                    <div id="topUpAmountNgn" class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-gray-900 font-semibold">₦0</div>
                </div>

                <div class="md:col-span-3">
                    <button id="paystackBtn" type="button" class="w-full py-3 bg-lime-500 text-[#0a2912] font-semibold rounded-lg hover:bg-lime-600 transition disabled:opacity-50 disabled:cursor-not-allowed">
                        Pay with Paystack
                    </button>
                </div>
            </form>

            <div id="topUpMsg" class="mt-3 text-sm"></div>
        </section>

        <section class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm mb-6">
            <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                <div>
                    <h2 class="text-xl font-semibold">Payment History</h2>
                    <p class="text-sm text-gray-600 mt-1">This is a read-only mirror of payment history from Google Cloud billing.</p>
                </div>
                <form id="paymentHistoryFilters" class="grid grid-cols-1 sm:grid-cols-3 gap-3 w-full md:w-auto">
                    <div>
                        <label for="paymentHistoryYear" class="block text-sm font-medium text-gray-700 mb-1">Year</label>
                        <input id="paymentHistoryYear" type="number" min="2000" max="2100" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500" />
                    </div>
                    <div>
                        <label for="paymentHistoryMonth" class="block text-sm font-medium text-gray-700 mb-1">Month</label>
                        <select id="paymentHistoryMonth" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500">
                            <option value="">All months</option>
                            <option value="1">January</option>
                            <option value="2">February</option>
                            <option value="3">March</option>
                            <option value="4">April</option>
                            <option value="5">May</option>
                            <option value="6">June</option>
                            <option value="7">July</option>
                            <option value="8">August</option>
                            <option value="9">September</option>
                            <option value="10">October</option>
                            <option value="11">November</option>
                            <option value="12">December</option>
                        </select>
                    </div>
                    <div>
                        <button id="paymentHistoryRefreshBtn" type="submit" class="w-full rounded-lg bg-[#0a2912] px-4 py-2 text-sm font-medium text-white hover:bg-opacity-90 transition">Refresh history</button>
                    </div>
                </form>
            </div>

            <div class="grid gap-3 md:grid-cols-3 mt-4">
                <div class="rounded-lg bg-lime-50 border border-lime-100 p-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Selected period</div>
                    <div id="paymentHistorySelectedPeriod" class="mt-1 text-sm font-semibold text-[#0a2912]">0 payments | 0 credits | $0.00</div>
                </div>
                <div class="rounded-lg bg-gray-50 border border-gray-200 p-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Current month</div>
                    <div id="paymentHistoryCurrentMonth" class="mt-1 text-sm font-semibold text-gray-900">0 payments | 0 credits | $0.00</div>
                </div>
                <div class="rounded-lg bg-gray-50 border border-gray-200 p-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Current year</div>
                    <div id="paymentHistoryCurrentYear" class="mt-1 text-sm font-semibold text-gray-900">0 payments | 0 credits | $0.00</div>
                </div>
            </div>

            <div id="paymentHistoryMsg" class="mt-3 text-sm text-gray-600">Loading payment history...</div>

            <div class="overflow-auto mt-4">
                <table class="w-full text-sm border-collapse" id="paymentHistoryTable">
                    <thead>
                        <tr class="bg-gray-50 text-gray-900">
                            <th class="text-left p-2 border-b">Payment Date</th>
                            <th class="text-left p-2 border-b">Invoice</th>
                            <th class="text-left p-2 border-b">Reference</th>
                            <th class="text-left p-2 border-b">Credits</th>
                            <th class="text-left p-2 border-b">Amount</th>
                            <th class="text-left p-2 border-b">Status</th>
                            <th class="text-left p-2 border-b">Drill-down</th>
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

</body>
</html>
