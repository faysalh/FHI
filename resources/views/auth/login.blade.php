<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Sign in — Reporting</title>
    @include('reports.partials.theme')
    <style>
        body.report-app--login {
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: linear-gradient(160deg, #0f172a 0%, #1e3a5f 100%);
        }
        .login-card {
            width: min(400px, 92vw);
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 24px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.15);
        }
        .login-card h1 { margin: 0 0 6px; font-size: 1.35rem; }
        .login-card p { margin: 0 0 18px; color: #64748b; font-size: 13px; }
        .login-card label { display: block; margin-bottom: 4px; font-size: 13px; font-weight: 600; color: #475569; }
        .login-card input { margin-bottom: 12px; }
        .login-card button { width: 100%; margin-top: 4px; }
    </style>
</head>
<body class="report-app report-app--login">
<div class="login-card">
    <h1>Reporting</h1>
    <p>Sign in to view sales, inventory, and operations reports.</p>

    @if ($errors->any())
        <div class="alert alert--error" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('login.attempt') }}">
        @csrf
        <label for="username">Username</label>
        <input id="username" type="text" name="username" value="{{ old('username') }}" required autocomplete="username">

        <label for="password">Password</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">

        <button type="submit" class="btn btn--primary">Sign in</button>
    </form>
</div>
@include('reports.partials.mobile-reports')
</body>
</html>
