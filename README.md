# WarungPOS

Sistem POS dan manajemen warung sembako berbasis Laravel 13, Tailwind, dan MySQL-ready schema. Implementasi mengikuti `md/prd.md`, `md/DESIGN.md`, dan patokan UI di `md/ref.md`.

## Fitur

- Login username/email, role owner/admin/supervisor/kasir, session, rate limit login.
- Dashboard operasional, POS kasir, cart lokal, barcode/search, shortcut F2/F8/Esc.
- Checkout tunai/non-tunai manual dengan database transaction, idempotency key, payment log, stock movement, audit log, dan update shift.
- Produk, barcode, import/export CSV, inventory, stock adjustment, pembelian supplier, expense, pelanggan, supplier, user, laporan, audit log, pengaturan, backup JSON.
- Schema siap multi-cabang secara struktural: `stores`, `warehouses`, `terminals`, `user_stores`, dan `store_id` di transaksi utama.

## Akun Demo

Semua akun memakai password `password`.

| Role | Username |
|---|---|
| Owner | `owner` |
| Admin | `admin` |
| Supervisor | `supervisor` |
| Kasir | `kasir` |

Ganti password default sebelum production.

## Setup Lokal

File `.env` sengaja tidak disimpan di repo. Untuk local development, buat `.env` sendiri dari template lalu sesuaikan database.

```powershell
composer install
npm.cmd install --ignore-scripts
Copy-Item .env.example .env
php artisan key:generate
New-Item -ItemType File -Force database\database.sqlite
```

Untuk SQLite lokal, ubah bagian database/cache di `.env` menjadi:

```dotenv
APP_ENV=local
APP_DEBUG=true
DB_CONNECTION=sqlite
DB_DATABASE=D:\kerjaan\pos-warung\database\database.sqlite
SESSION_DRIVER=file
CACHE_STORE=array
QUEUE_CONNECTION=sync
```

Lalu lanjutkan:

```powershell
php artisan migrate --seed
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000
```

Buka `http://127.0.0.1:8000/login`.

Jika `artisan serve` sulit dipakai di Windows shell tertentu, server PHP built-in langsung juga bisa:

```powershell
php -S 127.0.0.1:8000 -t public public/index.php
```

## Aplikasi Desktop

Project ini sudah memakai NativePHP Desktop, jadi Laravel tetap dipakai sebagai core app dan Electron menjadi shell desktop.

```powershell
composer install
npm.cmd install
php artisan native:migrate --no-interaction
php artisan native:seed --no-interaction
composer native:dev
```

Untuk build installer Windows:

```powershell
php artisan native:build win x64 --no-interaction
```

Jika build berhenti saat mengunduh `nativephp/php-bin`, jalankan Composer lagi sampai paket itu selesai terpasang. Di mesin ini dev desktop sudah terverifikasi jalan, tapi build installer production masih bergantung pada download paket NativePHP tersebut.

## CSV Import Produk

Header minimal:

```csv
sku,name,barcode,category,unit,purchase_price,selling_price,stock,minimum_stock
```

## Verifikasi

```powershell
php artisan test
npm.cmd run build
php artisan route:list
```

## Production Checklist

- Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY`, `APP_URL`, dan HTTPS lewat environment server, bukan file yang ikut repo.
- Jika menjalankan seeder di production, set `WARUNGPOS_SEED_PASSWORD` dan `WARUNGPOS_SEED_PIN` dengan nilai sementara yang kuat lalu wajib ganti setelah login pertama.
- Pakai MySQL 8, Redis untuk session/cache/queue/rate limit, queue worker, scheduler, log rotation, backup database harian, dan error monitoring.
- Isi `PAYMENT_WEBHOOK_SECRET` dan secret provider pembayaran di environment.
- Jalankan migration di staging dulu, lalu production.
- Uji restore backup dan simulasi transaksi kasir sebelum go-live.
