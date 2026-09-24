<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign in · MockDeck</title>
    <meta name="description" content="Sign in to the MockDeck administration dashboard.">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="">
    <script src="{{ asset('js/theme.js') }}?v={{ filemtime(public_path('js/theme.js')) }}" data-navigate-once></script>
    <link rel="preload" href="{{ asset('fonts/jetbrains-mono/jetbrains-mono-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}?v={{ filemtime(public_path('css/tokens.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="auth-page">
    <div class="auth-theme-control"><x-theme-control name="theme-auth" /></div>
    <main class="auth-shell">
        <section class="card auth-card">
            <div class="auth-brand">
                <span class="brand-mark" aria-hidden="true">M</span>
                <div><strong>MockDeck</strong><small>Administration dashboard</small></div>
            </div>

            <x-page-header title="Sign in" description="Use the dashboard credentials configured for this deployment." />

            <form method="POST" action="{{ route('dashboard.login.store') }}">
                @csrf
                <div class="field">
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" value="{{ old('username') }}" autocomplete="username" required autofocus>
                    @error('username') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                    @error('password') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <button class="button button-primary button-full" type="submit">Sign in</button>
            </form>
        </section>
    </main>
</body>
</html>
