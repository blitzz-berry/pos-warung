# WarungPOS Security Report

Tanggal audit: 20 Juli 2026

## Ringkasan

Audit produksi dilakukan setelah implementasi PRD. Temuan `.env` di workspace sudah ditutup dengan menghapus `.env` dari repo kerja dan menjadikan `.env.example` template production-safe tanpa secret nyata.

## Kontrol Keamanan Aktif

- Autentikasi username/email dengan rate limit login dan session regeneration.
- Role/permission server-side melalui middleware `permission`.
- CSRF aktif pada form web dan API session route.
- Security headers aktif: CSP, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, Referrer Policy, dan Permissions Policy.
- CSP tidak mengizinkan inline script; data POS dikirim lewat atribut HTML dan event listener bundled asset.
- Payment webhook memakai HMAC signature dan status tidak diturunkan dari `paid` ke `pending`.
- Webhook payment dikecualikan dari CSRF karena dipanggil provider eksternal, tetapi tetap wajib HMAC dan rate limit `120/min`.
- Checkout, refund, cancel sale, pembelian, stock adjustment, dan stock opname berjalan dalam database transaction.
- Seeder production wajib `WARUNGPOS_SEED_PASSWORD` dan `WARUNGPOS_SEED_PIN`; akun demo tidak diprefill pada halaman login.
- Backup command `warungpos:backup-json` menyimpan file terenkripsi dan dijadwalkan harian.

## Import CSV Produk

Import produk adalah fitur PRD, sehingga aplikasi memang memiliki permukaan input file. Kontrol yang diterapkan:

- Wajib login dan permission `product.import`.
- Upload dibatasi maksimal 2 MB.
- Hanya menerima ekstensi `csv` atau `txt` dan MIME CSV/text umum.
- Header CSV harus sesuai allowlist template produk.
- Import dibatasi 5.000 baris per file.
- File tidak disimpan permanen ke storage publik.
- Penyimpanan data dilakukan dalam database transaction.
- Aktivitas import dicatat ke audit log.
- Export CSV meloloskan formula-leading value dengan prefix apostrophe untuk mengurangi risiko CSV injection.

## Catatan MCP

MCP final-testing masih menandai `File upload surface detected` sebagai medium blocker karena scanner konservatif mendeteksi fitur import file yang memang diwajibkan PRD serta disebut di `md/prd.md`, `md/DESIGN.md`, dan `md/ref.md`. Risiko sudah dimitigasi di kode dan didokumentasikan di laporan ini.
