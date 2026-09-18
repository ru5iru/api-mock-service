<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign in · MockDeck</title>
    <meta name="description" content="Sign in to the MockDeck administration dashboard.">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="card auth-card">
            <div class="auth-brand">
                <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
                <div><strong>MockDeck</strong><small>Administration dashboard</small></div>
            </div>

            <div class="auth-copy">
                <span class="eyebrow">Protected access</span>
                <h1>Sign in</h1>
                <p>Use the dashboard credentials configured for this deployment.</p>
            </div>

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
