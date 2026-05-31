<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Risk Control Services Nigeria — Account Settings</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body class="bg-green-50 text-gray-900 font-sans">
    <header class="bg-gradient-to-r from-lime-500 to-lime-300 text-[#0a2912] border-b-4 border-lime-600">
        <div class="max-w-5xl mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-[#0a2912] text-white flex items-center justify-center font-bold">RC</div>
                    <div>
                        <h1 class="m-0 text-lg font-semibold">Risk Control Services Nigeria</h1>
                        <p class="m-0 text-xs opacity-90">Account Settings</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <nav class="flex gap-2">
                        <a href="{{ route('dashboard') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Convocation</a>
                        <a href="{{ route('certificates') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Certificates</a>
                        <a href="{{ route('topup') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Top up</a>
                        <a href="{{ route('logs') }}" class="text-sm px-3 py-2 rounded-lg bg-white/30 hover:bg-white/50 transition font-medium">Logs</a>
                        <a href="{{ route('settings') }}" class="text-sm px-3 py-2 rounded-lg bg-[#0a2912] text-white transition font-medium">Settings</a>
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

    <main class="max-w-3xl mx-auto px-4 py-8">
        <h2 class="text-2xl font-semibold text-gray-900 mb-1">Account Settings</h2>
        <p class="text-sm text-gray-500 mb-6">Manage your login email and password.</p>

        {{-- Flash messages --}}
        <div id="flashMsg" class="hidden mb-4 px-4 py-3 rounded-lg text-sm font-medium"></div>

        {{-- Update Profile --}}
        <section class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 mb-6">
            <h3 class="text-base font-semibold text-gray-800 mb-4">Profile</h3>
            <form id="profileForm" class="space-y-4">
                @csrf
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Full name</label>
                    <input type="text" id="name" name="name" value="{{ $userName }}"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-lime-400"
                        required>
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email address</label>
                    <input type="email" id="email" name="email" value="{{ $userEmail }}"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-lime-400"
                        required>
                </div>
                <div>
                    <button type="submit"
                        class="px-5 py-2 bg-[#0a2912] text-white text-sm font-semibold rounded-lg hover:bg-opacity-90 transition">
                        Save changes
                    </button>
                    <span id="profileSpinner" class="ml-2 text-xs text-gray-500 hidden">Saving…</span>
                </div>
            </form>
        </section>

        {{-- Change Password --}}
        <section class="bg-white border border-gray-200 rounded-xl shadow-sm p-6">
            <h3 class="text-base font-semibold text-gray-800 mb-4">Change password</h3>
            <form id="passwordForm" class="space-y-4">
                @csrf
                <div>
                    <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Current password</label>
                    <input type="password" id="current_password" name="current_password"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-lime-400"
                        required autocomplete="current-password">
                </div>
                <div>
                    <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">New password</label>
                    <input type="password" id="new_password" name="new_password"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-lime-400"
                        required autocomplete="new-password">
                    <p class="text-xs text-gray-400 mt-1">Minimum 8 characters, must contain uppercase, lowercase, and a number.</p>
                </div>
                <div>
                    <label for="new_password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">Confirm new password</label>
                    <input type="password" id="new_password_confirmation" name="new_password_confirmation"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-lime-400"
                        required autocomplete="new-password">
                </div>
                <div>
                    <button type="submit"
                        class="px-5 py-2 bg-[#0a2912] text-white text-sm font-semibold rounded-lg hover:bg-opacity-90 transition">
                        Update password
                    </button>
                    <span id="passwordSpinner" class="ml-2 text-xs text-gray-500 hidden">Saving…</span>
                </div>
            </form>
        </section>
    </main>

    <script>
    (function () {
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        function showFlash(msg, isError) {
            const el = document.getElementById('flashMsg');
            el.textContent = msg;
            el.className = 'mb-4 px-4 py-3 rounded-lg text-sm font-medium ' + (isError
                ? 'bg-red-50 border border-red-200 text-red-700'
                : 'bg-lime-50 border border-lime-200 text-lime-800');
            el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            setTimeout(() => { el.className = 'hidden mb-4 px-4 py-3 rounded-lg text-sm font-medium'; }, 5000);
        }

        async function post(url, data, spinner) {
            spinner.classList.remove('hidden');
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify(data),
                });
                const json = await res.json();
                return { ok: res.ok, json };
            } finally {
                spinner.classList.add('hidden');
            }
        }

        document.getElementById('profileForm').addEventListener('submit', async function (e) {
            e.preventDefault();
            const { ok, json } = await post(
                '{{ route('settings.profile') }}',
                {
                    name: document.getElementById('name').value.trim(),
                    email: document.getElementById('email').value.trim(),
                },
                document.getElementById('profileSpinner')
            );
            if (ok && json.success) {
                document.getElementById('name').value = json.name;
                document.getElementById('email').value = json.email;
                showFlash(json.message, false);
            } else {
                showFlash(json.error || JSON.stringify(json.errors || json), true);
            }
        });

        document.getElementById('passwordForm').addEventListener('submit', async function (e) {
            e.preventDefault();
            const newPw   = document.getElementById('new_password').value;
            const confirm = document.getElementById('new_password_confirmation').value;
            if (newPw !== confirm) {
                showFlash('New passwords do not match.', true);
                return;
            }
            const { ok, json } = await post(
                '{{ route('settings.password') }}',
                {
                    current_password:       document.getElementById('current_password').value,
                    new_password:           newPw,
                    new_password_confirmation: confirm,
                },
                document.getElementById('passwordSpinner')
            );
            if (ok && json.success) {
                document.getElementById('passwordForm').reset();
                showFlash(json.message, false);
            } else {
                showFlash(json.error || JSON.stringify(json.errors || json), true);
            }
        });
    })();
    </script>
</body>
</html>
