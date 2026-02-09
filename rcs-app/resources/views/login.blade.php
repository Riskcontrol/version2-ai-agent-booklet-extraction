<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Risk Control Services Nigeria</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gradient-to-br from-lime-50 via-green-50 to-lime-100 min-h-screen flex items-center justify-center font-sans">
    <div class="w-full max-w-md px-4">
        <div class="bg-white rounded-2xl shadow-2xl overflow-hidden border border-gray-100">
            <!-- Header -->
            <div class="bg-gradient-to-r from-lime-500 to-lime-400 text-[#0a2912] px-8 py-6">
                <div class="flex items-center justify-center gap-3 mb-2">
                    <div class="w-12 h-12 rounded-full bg-[#0a2912] text-white flex items-center justify-center font-bold text-xl">RC</div>
                </div>
                <h1 class="text-2xl font-bold text-center">Risk Control Services</h1>
                <p class="text-center text-sm opacity-90 mt-1">Convocation Extractor Console</p>
            </div>

            <!-- Login Form -->
            <div class="px-8 py-8">
                <h2 class="text-xl font-semibold text-gray-800 mb-6 text-center">Sign In</h2>

                @if ($errors->any())
                    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
                        <ul class="text-sm">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (session('success'))
                    <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm">
                        {{ session('success') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login.submit') }}" class="space-y-5">
                    @csrf
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            value="{{ old('email') }}"
                            required 
                            autofocus
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-lime-500 focus:border-lime-500 outline-none transition"
                            placeholder="admin@rcsn.com"
                        />
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-2">Password</label>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-lime-500 focus:border-lime-500 outline-none transition"
                            placeholder="••••••••"
                        />
                    </div>

                    <button 
                        type="submit"
                        class="w-full bg-lime-500 hover:bg-lime-600 text-[#0a2912] font-semibold px-4 py-3 rounded-lg transition duration-200 shadow-md hover:shadow-lg"
                    >
                        Sign In
                    </button>
                </form>

                <div class="mt-6 text-center">
                    <p class="text-xs text-gray-500">
                        Default credentials: admin@rcsn.com / admin@123
                    </p>
                </div>
            </div>
        </div>

        <div class="text-center mt-6">
            <p class="text-sm text-gray-600">© {{ date('Y') }} Risk Control Services Nigeria</p>
        </div>
    </div>
</body>
</html>
