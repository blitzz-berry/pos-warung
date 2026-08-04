<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup Awal - WarungPOS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-page">
    <main class="auth-card">
        <section class="auth-brand">
            <div class="brand-mark">WP</div>
            <p class="eyebrow">WarungPOS</p>
            <h1>Setup toko pertama.</h1>
            <p>Buat akun owner dan profil warung sebelum aplikasi dipakai transaksi.</p>
            <dl class="auth-stats">
                <div><dt>Database</dt><dd>Lokal</dd></div>
                <div><dt>Mode</dt><dd>Desktop</dd></div>
                <div><dt>Akses</dt><dd>Owner</dd></div>
            </dl>
        </section>

        <section class="auth-form">
            <div>
                <p class="eyebrow">Setup awal</p>
                <h2>Buat owner toko</h2>
                <p class="muted">Gunakan password baru, bukan password demo.</p>
            </div>

            @if ($errors->any())
                <div class="alert danger">{{ $errors->first() }}</div>
            @endif

            <form method="post" action="{{ route('setup.store') }}" class="form-stack">
                @csrf
                <label>
                    <span>Nama warung</span>
                    <input name="store_name" value="{{ old('store_name') }}" required autofocus>
                </label>

                <label>
                    <span>Telepon warung</span>
                    <input name="store_phone" value="{{ old('store_phone') }}">
                </label>

                <label>
                    <span>Alamat warung</span>
                    <input name="store_address" value="{{ old('store_address') }}">
                </label>

                <label>
                    <span>Nama owner</span>
                    <input name="name" value="{{ old('name') }}" required>
                </label>

                <label>
                    <span>Username owner</span>
                    <input name="username" value="{{ old('username') }}" autocomplete="username" required>
                </label>

                <label>
                    <span>Email owner</span>
                    <input name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
                </label>

                <label>
                    <span>Password owner</span>
                    <input name="password" type="password" autocomplete="new-password" minlength="8" required>
                </label>

                <label>
                    <span>Ulangi password</span>
                    <input name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required>
                </label>

                <label>
                    <span>PIN owner 6 digit</span>
                    <input name="pin" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" required>
                </label>

                <button class="btn primary large" type="submit">Simpan Setup</button>
            </form>
        </section>
    </main>
</body>
</html>
