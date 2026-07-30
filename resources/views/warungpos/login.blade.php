<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk - WarungPOS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-page">
    <main class="auth-card">
        <section class="auth-brand">
            <div class="brand-mark">WP</div>
            <p class="eyebrow">WarungPOS</p>
            <h1>Kasir cepat, stok rapi, laporan jelas.</h1>
            <p>Sistem POS dan manajemen sembako untuk Warung Makmur.</p>
            <dl class="auth-stats">
                <div><dt>Shift aktif</dt><dd>2 kasir</dd></div>
                <div><dt>Stok aman</dt><dd>96%</dd></div>
                <div><dt>Backup</dt><dd>Harian</dd></div>
            </dl>
        </section>

        <section class="auth-form">
            <div>
                <p class="eyebrow">Masuk sistem</p>
                <h2>Gunakan akun kasir atau owner</h2>
                <p class="muted">Masukkan akun yang sudah dibuat oleh owner atau admin toko.</p>
            </div>

            @if ($errors->any())
                <div class="alert danger">{{ $errors->first() }}</div>
            @endif

            <form method="post" action="{{ route('login.attempt') }}" class="form-stack">
                @csrf
                <label>
                    <span>Username atau email</span>
                    <input name="login" value="{{ old('login') }}" autocomplete="username" required autofocus>
                </label>

                <label>
                    <span>Kata sandi</span>
                    <input name="password" type="password" autocomplete="current-password" required>
                </label>

                <label class="check-row">
                    <input name="remember" type="checkbox" value="1">
                    <span>Ingat perangkat ini</span>
                </label>

                <button class="btn primary large" type="submit">Masuk ke Dashboard</button>
            </form>
        </section>
    </main>
</body>
</html>
