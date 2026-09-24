@props([
    'status',
    'title',
    'message',
])

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $status }} · {{ $title }} · MockDeck</title>
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="">
    <script src="{{ asset('js/theme.js') }}?v={{ filemtime(public_path('js/theme.js')) }}" data-navigate-once></script>
    <link rel="preload" href="{{ asset('fonts/jetbrains-mono/jetbrains-mono-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}?v={{ filemtime(public_path('css/tokens.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="auth-page">
    <div class="auth-theme-control"><x-theme-control name="theme-error" /></div>
    <main class="auth-shell">
        <section class="card auth-card error-card">
            <div class="auth-brand">
                <span class="brand-mark" aria-hidden="true">M</span>
                <div><strong>MockDeck</strong><small>Error {{ $status }}</small></div>
            </div>
            <x-page-header :title="$title" :description="$message" />
            <a class="button button-primary" href="{{ url('/dashboard') }}">Back to endpoints</a>
        </section>
    </main>
</body>
</html>
