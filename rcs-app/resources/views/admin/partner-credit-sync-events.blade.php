<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Risk Control Services Nigeria - Partner Credit Sync Audit</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="bg-green-50 text-gray-900 font-sans">
    <header class="bg-gradient-to-r from-lime-500 to-lime-300 text-[#0a2912] border-b-4 border-lime-600">
        <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
            <div>
                <h1 class="text-lg font-semibold m-0">Partner Credit Sync Audit</h1>
                <p class="text-xs opacity-90 m-0">Durable webhook event history from billing authority</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('dashboard') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Convocation</a>
                <a href="{{ route('certificates') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Certificates</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-sm px-4 py-2 bg-[#0a2912] text-white rounded-lg hover:bg-opacity-90 transition">Logout</button>
                </form>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-6">
        <section class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm mb-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-gray-900">Quick Ranges</h2>
                    <p class="text-xs text-gray-500">Set date filters instantly</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('admin.partnerCreditSyncEvents', array_merge(request()->except(['page', 'date_from', 'date_to']), ['preset' => 'today'])) }}" class="rounded-lg border px-3 py-2 text-sm font-medium {{ $filters['preset'] === 'today' ? 'border-lime-700 text-lime-800 bg-lime-50' : 'border-gray-300 text-gray-700' }}">Today</a>
                    <a href="{{ route('admin.partnerCreditSyncEvents', array_merge(request()->except(['page', 'date_from', 'date_to']), ['preset' => '7d'])) }}" class="rounded-lg border px-3 py-2 text-sm font-medium {{ $filters['preset'] === '7d' ? 'border-lime-700 text-lime-800 bg-lime-50' : 'border-gray-300 text-gray-700' }}">7 days</a>
                    <a href="{{ route('admin.partnerCreditSyncEvents', array_merge(request()->except(['page', 'date_from', 'date_to']), ['preset' => '30d'])) }}" class="rounded-lg border px-3 py-2 text-sm font-medium {{ $filters['preset'] === '30d' ? 'border-lime-700 text-lime-800 bg-lime-50' : 'border-gray-300 text-gray-700' }}">30 days</a>
                </div>
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6 mb-6">
            <div class="bg-white border border-gray-200 rounded-xl p-3 shadow-sm">
                <div class="text-xs uppercase tracking-wide text-gray-500">Total</div>
                <div class="text-2xl font-semibold text-gray-900 mt-1">{{ $stats['total'] }}</div>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl p-3 shadow-sm">
                <div class="text-xs uppercase tracking-wide text-gray-500">Accepted</div>
                <div class="text-2xl font-semibold text-green-700 mt-1">{{ $stats['accepted'] }}</div>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl p-3 shadow-sm">
                <div class="text-xs uppercase tracking-wide text-gray-500">Validation Failed</div>
                <div class="text-2xl font-semibold text-amber-700 mt-1">{{ $stats['validation_failed'] }}</div>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl p-3 shadow-sm">
                <div class="text-xs uppercase tracking-wide text-gray-500">Rejected Auth</div>
                <div class="text-2xl font-semibold text-red-700 mt-1">{{ $stats['rejected_auth'] }}</div>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl p-3 shadow-sm">
                <div class="text-xs uppercase tracking-wide text-gray-500">Auth Valid</div>
                <div class="text-2xl font-semibold text-gray-900 mt-1">{{ $stats['auth_valid'] }}</div>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl p-3 shadow-sm">
                <div class="text-xs uppercase tracking-wide text-gray-500">Auth Invalid</div>
                <div class="text-2xl font-semibold text-gray-900 mt-1">{{ $stats['auth_invalid'] }}</div>
            </div>
        </section>

        <section class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm mb-6">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h2 class="text-sm font-semibold text-gray-900">Accepted vs Failed Trend</h2>
                    <p class="text-xs text-gray-500">{{ $trend['granularity'] === 'weekly' ? 'Weekly' : 'Daily' }} counts using current event and user filters</p>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs text-gray-600">
                    <a href="{{ route('admin.partnerCreditSyncEvents', array_merge(request()->except(['page']), ['granularity' => 'daily'])) }}" class="rounded-lg border px-2 py-1 font-medium {{ $filters['granularity'] === 'daily' ? 'border-lime-700 text-lime-800 bg-lime-50' : 'border-gray-300 text-gray-700' }}">Daily</a>
                    <a href="{{ route('admin.partnerCreditSyncEvents', array_merge(request()->except(['page']), ['granularity' => 'weekly'])) }}" class="rounded-lg border px-2 py-1 font-medium {{ $filters['granularity'] === 'weekly' ? 'border-lime-700 text-lime-800 bg-lime-50' : 'border-gray-300 text-gray-700' }}">Weekly</a>
                    <div class="flex items-center gap-1 ml-1"><span class="inline-block w-3 h-3 rounded bg-green-500"></span>Accepted</div>
                    <div class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded bg-red-500"></span>Failed</div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <div class="min-w-[560px] flex items-end gap-1 h-24">
                    @foreach($trend['points'] as $point)
                        @php
                            $acceptedHeight = max(2, (int) round(($point['accepted'] / $trend['max']) * 64));
                            $failedHeight = max(2, (int) round(($point['failed'] / $trend['max']) * 64));
                        @endphp
                        <div class="flex-1 min-w-[14px] flex flex-col items-center" title="{{ $point['label'] }} - Accepted: {{ $point['accepted'] }} ({{ $point['accepted_pct'] }}%), Failed: {{ $point['failed'] }} ({{ $point['failed_pct'] }}%)">
                            <div class="w-full flex items-end gap-[1px] h-16">
                                <div class="w-1/2 bg-green-500 rounded-sm" style="height: {{ $acceptedHeight }}px"></div>
                                <div class="w-1/2 bg-red-500 rounded-sm" style="height: {{ $failedHeight }}px"></div>
                            </div>
                            <div class="mt-1 text-[10px] text-gray-500 whitespace-nowrap">{{ $point['label'] }}</div>
                            <div class="text-[9px] text-gray-500 whitespace-nowrap">A {{ $point['accepted_pct'] }}% / F {{ $point['failed_pct'] }}%</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm mb-6">
            <form method="GET" action="{{ route('admin.partnerCreditSyncEvents') }}" class="grid grid-cols-1 md:grid-cols-6 gap-3">
                <input name="event_type" value="{{ $filters['event_type'] }}" type="text" placeholder="Event type" class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500">
                <input name="user_email" value="{{ $filters['user_email'] }}" type="text" placeholder="User email" class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500">
                <select name="processing_status" class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500">
                    <option value="">All processing states</option>
                    <option value="accepted" @selected($filters['processing_status'] === 'accepted')>Accepted</option>
                    <option value="validation_failed" @selected($filters['processing_status'] === 'validation_failed')>Validation Failed</option>
                    <option value="rejected_auth" @selected($filters['processing_status'] === 'rejected_auth')>Rejected Auth</option>
                </select>
                <select name="auth_valid" class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500">
                    <option value="">Any auth</option>
                    <option value="1" @selected($filters['auth_valid'] === '1')>Auth Valid</option>
                    <option value="0" @selected($filters['auth_valid'] === '0')>Auth Invalid</option>
                </select>
                <input name="date_from" value="{{ $filters['date_from'] }}" type="date" class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500">
                <input name="date_to" value="{{ $filters['date_to'] }}" type="date" class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-lime-500">
                <div class="md:col-span-6 flex gap-2 justify-end">
                    <a href="{{ route('admin.partnerCreditSyncEvents.export', request()->query()) }}" class="rounded-lg border border-lime-700 px-3 py-2 text-sm font-medium text-lime-800">Export CSV</a>
                    <a href="{{ route('admin.partnerCreditSyncEvents') }}" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700">Reset</a>
                    <button type="submit" class="rounded-lg bg-[#0a2912] px-3 py-2 text-sm font-medium text-white">Apply Filters</button>
                </div>
            </form>
        </section>

        <section class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
            <div class="overflow-auto">
                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="bg-gray-50 text-gray-900">
                            <th class="text-left p-2 border-b">ID</th>
                            <th class="text-left p-2 border-b">Received</th>
                            <th class="text-left p-2 border-b">Event</th>
                            <th class="text-left p-2 border-b">User</th>
                            <th class="text-left p-2 border-b">Balance</th>
                            <th class="text-left p-2 border-b">Auth</th>
                            <th class="text-left p-2 border-b">Processing</th>
                            <th class="text-left p-2 border-b">Occurred</th>
                            <th class="text-left p-2 border-b">Source IP</th>
                            <th class="text-left p-2 border-b">Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($events as $event)
                            <tr>
                                <td class="p-2 border-b">{{ $event->id }}</td>
                                <td class="p-2 border-b">{{ optional($event->received_at)->format('Y-m-d H:i:s') }}</td>
                                <td class="p-2 border-b">{{ $event->event_type ?: '-' }}</td>
                                <td class="p-2 border-b">{{ $event->user_email ?: '-' }}</td>
                                <td class="p-2 border-b">{{ $event->credit_balance ?? '-' }}</td>
                                <td class="p-2 border-b">{{ $event->auth_valid ? 'valid' : 'invalid' }}</td>
                                <td class="p-2 border-b">{{ $event->processing_status }}</td>
                                <td class="p-2 border-b">{{ optional($event->occurred_at)->format('Y-m-d H:i:s') }}</td>
                                <td class="p-2 border-b">{{ $event->source_ip ?: '-' }}</td>
                                <td class="p-2 border-b">{{ $event->error_message ?: '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="p-4 text-center text-gray-500">No sync events found for the selected filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $events->links() }}</div>
        </section>
    </main>
</body>
</html>
