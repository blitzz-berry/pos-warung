@php
    $money = fn ($value) => 'Rp '.number_format((float) $value, 0, ',', '.');
    $statusClass = fn ($status) => match ($status) {
        'completed', 'paid', 'active', 'received', 'approved', 'open' => 'success',
        'pending', 'draft', 'partial' => 'warning',
        'cancelled', 'failed', 'refunded', 'inactive', 'closed' => 'danger',
        default => 'neutral',
    };
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'WarungPOS' }} - WarungPOS</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@400;500;600" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="wp-body">
    <aside class="sidebar">
        <a class="store-card" href="{{ route('dashboard') }}">
            @if (! empty($settings['store.logo_path']))
                <img class="store-logo-img" src="{{ route('media', ['path' => $settings['store.logo_path']]) }}" alt="{{ $settings['store.name'] ?? $store->name ?? 'WarungPOS' }}">
            @else
                <span class="store-logo">WP</span>
            @endif
            <span>
                <strong>{{ $settings['store.name'] ?? $store->name ?? 'Warung Makmur' }}</strong>
                <small>Sembako & Grosir</small>
            </span>
        </a>

        <nav class="side-nav" aria-label="Navigasi utama">
            @foreach ($navItems as $item)
                <a class="{{ $active === $item['key'] ? 'active' : '' }}" href="{{ route($item['route']) }}">
                    <span class="material-symbols-outlined" aria-hidden="true">{{ $item['icon'] }}</span>
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>

        <div class="support-card">
            <strong>Shift</strong>
            @if ($activeShift)
                <span class="badge success">Aktif sejak {{ \Illuminate\Support\Carbon::parse($activeShift->opened_at)->format('H:i') }}</span>
                <small>Expected cash: {{ $money($activeShift->expected_cash) }}</small>
            @else
                <span class="badge warning">Belum dibuka</span>
                <small>Buka shift sebelum transaksi.</small>
            @endif
        </div>
    </aside>

    <header class="topbar">
        <form class="global-search" action="{{ route('products') }}">
            <span class="material-symbols-outlined" aria-hidden="true">search</span>
            <input name="q" value="{{ request('q') }}" placeholder="Cari produk, transaksi, pelanggan, atau laporan...">
        </form>
        <div class="top-actions">
            <span class="badge info">{{ $unreadNotifications }} notifikasi</span>
            <span class="branch">{{ $store->code ?? 'JKT-001' }}</span>
            <form method="post" action="{{ route('logout') }}">
                @csrf
                <button class="avatar-button" type="submit" title="Keluar">
                    <span>{{ strtoupper(substr($user->name ?? 'U', 0, 1)) }}</span>
                    <small>{{ $roleNames[0] ?? 'User' }}</small>
                </button>
            </form>
        </div>
    </header>

    <main class="content">
        @if (session('status'))
            <div class="alert success" data-toast>{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert danger">{{ $errors->first() }}</div>
        @endif

        @switch($active)
            @case('dashboard')
                <section class="page-header">
                    <div>
                        <h1>Dashboard</h1>
                        <p>Ringkasan kondisi toko hari ini untuk owner, admin, dan supervisor.</p>
                    </div>
                    <a class="btn primary" href="{{ route('pos') }}">Buka POS</a>
                </section>

                <section class="kpi-grid">
                    @foreach ($kpis as $kpi)
                        <article class="card kpi">
                            <span class="kpi-dot {{ $kpi['tone'] }}"></span>
                            <p>{{ $kpi['label'] }}</p>
                            <strong>{{ $kpi['value'] }}</strong>
                            <small>{{ $kpi['hint'] }}</small>
                        </article>
                    @endforeach
                </section>

                <section class="grid two-one">
                    <article class="card">
                        <div class="section-title">
                            <h2>Penjualan Terbaru</h2>
                            <a href="{{ route('sales') }}">Lihat semua</a>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Invoice</th><th>Kasir</th><th>Total</th><th>Status</th></tr></thead>
                                <tbody>
                                    @forelse ($recentSales as $sale)
                                        <tr>
                                            <td><a href="{{ route('sales.show', $sale->id) }}">{{ $sale->invoice_number }}</a></td>
                                            <td>{{ $sale->cashier_name }}</td>
                                            <td class="num">{{ $money($sale->total_amount) }}</td>
                                            <td><span class="badge {{ $statusClass($sale->status) }}">{{ $sale->status }}</span></td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="empty-cell">Belum ada transaksi.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </article>

                    <article class="card">
                        <div class="section-title">
                            <h2>Produk Terlaris</h2>
                            <a href="{{ route('reports') }}">Laporan</a>
                        </div>
                        <div class="rank-list">
                            @forelse ($topProducts as $product)
                                <div>
                                    <span>{{ $product->product_name }}</span>
                                    <strong>{{ (int) $product->qty }} terjual</strong>
                                </div>
                            @empty
                                <p class="muted">Belum ada data penjualan.</p>
                            @endforelse
                        </div>
                    </article>
                </section>

                <section class="grid two-one">
                    <article class="card">
                        <div class="section-title">
                            <h2>Produk Stok Rendah</h2>
                            <a href="{{ route('inventory') }}">Kelola stok</a>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Produk</th><th>Stok</th><th>Minimum</th></tr></thead>
                                <tbody>
                                    @forelse ($lowStock as $product)
                                        <tr>
                                            <td>{{ $product->name }}</td>
                                            <td class="num">{{ (float) $product->stock }} {{ $product->unit_code }}</td>
                                            <td class="num">{{ (float) $product->minimum_stock }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="3" class="empty-cell">Tidak ada stok rendah.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </article>

                    <article class="card">
                        <div class="section-title">
                            <h2>Aktivitas Terbaru</h2>
                            <a href="{{ route('audit') }}">Audit</a>
                        </div>
                        <div class="activity-list">
                            @forelse ($recentAudits as $log)
                                <div>
                                    <strong>{{ $log->module }} {{ $log->action }}</strong>
                                    <span>{{ $log->user_name ?? 'Sistem' }} - {{ \Illuminate\Support\Carbon::parse($log->created_at)->diffForHumans() }}</span>
                                </div>
                            @empty
                                <p class="muted">Belum ada aktivitas.</p>
                            @endforelse
                        </div>
                    </article>
                </section>
                @break

            @case('pos')
                <section class="pos-header">
                    <div>
                        <h1>POS Kasir</h1>
                        <p>Scanner siap, keranjang tersimpan lokal sampai transaksi selesai.</p>
                    </div>
                    <div class="shortcut-row">
                        <kbd>F2</kbd><span>Cari</span>
                        <kbd>F8</kbd><span>Bayar</span>
                        <kbd>Esc</kbd><span>Tutup</span>
                    </div>
                </section>

                @if (! $activeShift)
                    <section class="card compact-card">
                        <h2>Buka Shift Kasir</h2>
                        <p class="muted">Transaksi tidak bisa diproses sebelum shift dibuka.</p>
                        <form class="inline-form" method="post" action="{{ route('shifts.open') }}">
                            @csrf
                            <label><span>Modal awal</span><input name="opening_cash" type="number" min="0" value="300000" required></label>
                            <label><span>Catatan</span><input name="opening_notes" value="Shift kasir"></label>
                            <button class="btn primary" type="submit">Buka Shift</button>
                        </form>
                    </section>
                @endif

                <section class="pos-grid" data-pos-root data-products='{{ $productsJson }}'>
                    <div class="pos-products">
                        <div class="card search-panel">
                            <div class="scanner-row">
                                <label class="barcode-field">
                                    <span class="material-symbols-outlined">barcode_scanner</span>
                                    <input id="barcodeInput" placeholder="Scan barcode atau cari produk" autocomplete="off">
                                </label>
                                <button class="btn secondary scan-button" type="button" data-open-scanner data-scan-mode="pos" aria-haspopup="dialog">
                                    <span class="material-symbols-outlined" aria-hidden="true">photo_camera</span>
                                    Scan
                                </button>
                            </div>
                            <div class="category-chips">
                                <button class="chip active" data-category="">Semua</button>
                                @foreach ($categories as $category)
                                    <button class="chip" data-category="{{ $category->id }}">{{ $category->name }}</button>
                                @endforeach
                            </div>
                        </div>

                        <div class="product-grid">
                            @foreach ($products as $product)
                                <button class="product-card" data-add-product="{{ $product->id }}" data-category="{{ $product->category_id }}" @disabled($product->stock <= 0)>
                                    @if ($product->image_path)
                                        <img class="product-thumb-img" src="{{ route('media', ['path' => $product->image_path]) }}" alt="{{ $product->name }}">
                                    @else
                                        <span class="product-thumb">{{ strtoupper(substr($product->name, 0, 2)) }}</span>
                                    @endif
                                    <strong>{{ $product->name }}</strong>
                                    <span>{{ $money($product->selling_price) }}</span>
                                    <small class="{{ $product->stock <= $product->minimum_stock ? 'low' : '' }}">Stok: {{ (float) $product->stock }} {{ $product->unit_code }}</small>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <aside class="cart-panel card">
                        <div class="section-title">
                            <h2>Keranjang</h2>
                            <button class="link-button" data-clear-cart type="button">Kosongkan</button>
                        </div>
                        <div id="cartItems" class="cart-items empty-state">Belum ada produk.</div>
                        <div class="summary-lines">
                            <div><span>Subtotal</span><strong id="cartSubtotal">Rp 0</strong></div>
                            <div><span>Diskon</span><input id="discountInput" type="number" min="0" value="0"></div>
                            <div class="total"><span>TOTAL</span><strong id="cartTotal">Rp 0</strong></div>
                        </div>
                        <button class="btn primary pos-pay" type="button" data-open-payment @disabled(! $activeShift)>Bayar</button>
                    </aside>
                </section>

                <div class="modal" id="paymentModal" hidden>
                    <div class="modal-panel payment-panel">
                        <header>
                            <h2>Pembayaran</h2>
                            <button class="icon-button" type="button" data-close-modal>&times;</button>
                        </header>
                        <form method="post" action="{{ route('sales.checkout') }}" id="checkoutForm">
                            @csrf
                            <input type="hidden" name="items" id="checkoutItems">
                            <input type="hidden" name="idempotency_key" id="idempotencyKey">
                            <div class="payment-layout">
                                <div class="method-list">
                                    @foreach ($paymentMethods as $method)
                                        <label class="method-card">
                                            <input type="radio" name="payment_method" value="{{ $method->code }}" @checked($method->code === 'cash')>
                                            <span>{{ $method->name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <div class="payment-detail">
                                    <label><span>Uang diterima</span><input name="paid_amount" id="paidAmount" type="number" min="0" value="0"></label>
                                    <label><span>Referensi non-tunai</span><input name="reference_number" placeholder="Nomor referensi atau approval code"></label>
                                    <label><span>Catatan</span><textarea name="notes" rows="3"></textarea></label>
                                </div>
                                <div class="receipt-preview">
                                    <span>Total bayar</span>
                                    <strong id="modalTotal">Rp 0</strong>
                                    <small id="changePreview">Kembalian Rp 0</small>
                                    <button class="btn primary large" type="submit">Selesaikan Pembayaran</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                @break

            @case('products')
                <section class="page-header">
                    <div>
                        <h1>Produk</h1>
                        <p>Kelola produk, barcode, harga, dan status stok.</p>
                    </div>
                    <div class="action-row">
                        <a class="btn secondary" href="{{ route('products.export') }}">Export Excel</a>
                        <a class="btn primary" href="{{ route('products.create') }}">Tambah Produk</a>
                    </div>
                </section>

                <section class="card">
                    <form class="filter-row" method="post" action="{{ route('products.import') }}" enctype="multipart/form-data">
                        @csrf
                        <label><span>Import Excel</span><input type="file" name="file" accept=".xlsx,.csv,text/csv" required></label>
                        <button class="btn secondary" type="submit">Import</button>
                        <p class="muted">Header: sku,name,barcode,category,unit,purchase_price,selling_price,stock,minimum_stock</p>
                    </form>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Produk</th><th>Barcode</th><th>Kategori</th><th>Stok</th><th>Harga Jual</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                                @forelse ($products as $product)
                                    <tr>
                                        <td><strong>{{ $product->name }}</strong><small>{{ $product->sku }}</small></td>
                                        <td>{{ $product->barcode ?? '-' }}</td>
                                        <td>{{ $product->category_name ?? '-' }}</td>
                                        <td class="num">{{ (float) $product->stock }} {{ $product->unit_code }}</td>
                                        <td class="num">{{ $money($product->selling_price) }}</td>
                                        <td><span class="badge {{ $product->is_active ? 'success' : 'danger' }}">{{ $product->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                                        <td class="num"><a class="btn small secondary" href="{{ route('products.show', $product->id) }}">Detail</a></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="empty-cell">Belum ada produk.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $products->links() }}
                </section>
                @break

            @case('product-create')
                <section class="page-header">
                    <div>
                        <h1>Tambah Produk Baru</h1>
                        <p>Produk langsung memiliki barcode, harga, stok awal, dan stock movement.</p>
                    </div>
                    <a class="btn secondary" href="{{ route('products') }}">Kembali</a>
                </section>
                <form class="form-grid" method="post" action="{{ route('products.store') }}" enctype="multipart/form-data">
                    @csrf
                    <section class="card form-section">
                        <h2>Informasi Dasar</h2>
                        <label><span>Nama produk</span><input id="productNameInput" name="name" value="{{ old('name') }}" required></label>
                        <label><span>SKU</span><input id="productSkuInput" name="sku" value="{{ old('sku', request('barcode')) }}" required></label>
                        <div class="field-with-action">
                            <label><span>Barcode utama</span><input id="productBarcodeInput" name="barcode" value="{{ old('barcode', request('barcode')) }}"></label>
                            <button class="btn secondary scan-button" type="button" data-open-scanner data-scan-mode="barcode-field" data-target-input="#productBarcodeInput" data-target-sku="#productSkuInput" data-next-focus="#productNameInput" aria-haspopup="dialog">
                                <span class="material-symbols-outlined" aria-hidden="true">photo_camera</span>
                                Scan
                            </button>
                        </div>
                        <label><span>Kategori</span><select name="category_id"><option value="">Tanpa kategori</option>@foreach ($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label>
                        <label><span>Merek</span><select name="brand_id"><option value="">Tanpa merek</option>@foreach ($brands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach</select></label>
                        <label><span>Gambar produk</span><input name="image" type="file" accept="image/png,image/jpeg,image/webp"></label>
                        <label class="wide"><span>Deskripsi</span><textarea name="description" rows="3">{{ old('description') }}</textarea></label>
                    </section>
                    <section class="card form-section">
                        <h2>Harga dan Stok</h2>
                        <label><span>Satuan dasar</span><select name="base_unit_id" required>@foreach ($units as $unit)<option value="{{ $unit->id }}">{{ $unit->name }}</option>@endforeach</select></label>
                        <label><span>Harga beli</span><input name="purchase_price" type="number" min="0" value="{{ old('purchase_price', 0) }}" required></label>
                        <label><span>Harga jual</span><input name="selling_price" type="number" min="0" value="{{ old('selling_price', 0) }}" required></label>
                        <label><span>Stok awal</span><input name="opening_stock" type="number" min="0" value="{{ old('opening_stock', 0) }}"></label>
                        <label><span>Stok minimum</span><input name="minimum_stock" type="number" min="0" value="{{ old('minimum_stock', 0) }}"></label>
                        <button class="btn primary large wide" type="submit">Simpan Produk</button>
                    </section>
                </form>
                @break

            @case('product-detail')
                <section class="page-header">
                    <div>
                        <h1>{{ $product->name }}</h1>
                        <p>Detail produk, barcode, harga, status jual, dan gambar.</p>
                    </div>
                    <div class="action-row">
                        <a class="btn secondary" href="{{ route('products') }}">Kembali</a>
                    </div>
                </section>

                <form class="form-grid" method="post" action="{{ route('products.update', $product->id) }}" enctype="multipart/form-data">
                    @csrf
                    @method('put')
                    <section class="card form-section">
                        <h2>Informasi Produk</h2>
                        <div class="product-detail-head wide">
                            @if ($product->image_path)
                                <img class="product-detail-preview" src="{{ route('media', ['path' => $product->image_path]) }}" alt="{{ $product->name }}">
                            @else
                                <span class="product-detail-placeholder">{{ strtoupper(substr($product->name, 0, 2)) }}</span>
                            @endif
                            <div>
                                <strong>{{ $product->sku }}</strong>
                                <small>{{ $product->category_name ?? 'Tanpa kategori' }} / {{ $product->unit_code }}</small>
                            </div>
                        </div>
                        <label><span>Nama produk</span><input id="detailProductNameInput" name="name" value="{{ old('name', $product->name) }}" required></label>
                        <label><span>SKU</span><input id="detailProductSkuInput" name="sku" value="{{ old('sku', $product->sku) }}" required></label>
                        <div class="field-with-action">
                            <label><span>Barcode utama</span><input id="detailProductBarcodeInput" name="barcode" value="{{ old('barcode', $product->barcode) }}"></label>
                            <button class="btn secondary scan-button" type="button" data-open-scanner data-scan-mode="barcode-field" data-target-input="#detailProductBarcodeInput" data-next-focus="#detailProductNameInput" aria-haspopup="dialog">
                                <span class="material-symbols-outlined" aria-hidden="true">photo_camera</span>
                                Scan
                            </button>
                        </div>
                        <label><span>Kategori</span><select name="category_id"><option value="">Tanpa kategori</option>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected(old('category_id', $product->category_id) == $category->id)>{{ $category->name }}</option>@endforeach</select></label>
                        <label><span>Merek</span><select name="brand_id"><option value="">Tanpa merek</option>@foreach ($brands as $brand)<option value="{{ $brand->id }}" @selected(old('brand_id', $product->brand_id) == $brand->id)>{{ $brand->name }}</option>@endforeach</select></label>
                        <label><span>Ganti gambar produk</span><input name="image" type="file" accept="image/png,image/jpeg,image/webp"></label>
                        <label class="wide"><span>Deskripsi</span><textarea name="description" rows="3">{{ old('description', $product->description) }}</textarea></label>
                    </section>
                    <section class="card form-section">
                        <h2>Harga, Stok, Status</h2>
                        <div class="mini-list">
                            <div><span>Stok tersedia</span><strong>{{ (float) $product->stock }} {{ $product->unit_code }}</strong></div>
                            <div><span>HPP rata-rata</span><strong>{{ $money($product->average_cost) }}</strong></div>
                            <div><span>Dibuat</span><strong>{{ \Illuminate\Support\Carbon::parse($product->created_at)->format('d M Y') }}</strong></div>
                        </div>
                        <label><span>Satuan dasar</span><select name="base_unit_id" required>@foreach ($units as $unit)<option value="{{ $unit->id }}" @selected(old('base_unit_id', $product->base_unit_id) == $unit->id)>{{ $unit->name }}</option>@endforeach</select></label>
                        <label><span>Harga beli</span><input name="purchase_price" type="number" min="0" value="{{ old('purchase_price', $product->purchase_price) }}" required></label>
                        <label><span>Harga jual</span><input name="selling_price" type="number" min="0" value="{{ old('selling_price', $product->selling_price) }}" required></label>
                        <label><span>Stok minimum</span><input name="minimum_stock" type="number" min="0" value="{{ old('minimum_stock', $product->minimum_stock) }}"></label>
                        <label class="check-row"><input type="hidden" name="is_active" value="0"><input name="is_active" type="checkbox" value="1" @checked(old('is_active', $product->is_active))><span>Produk aktif</span></label>
                        <label class="check-row"><input type="hidden" name="sellable" value="0"><input name="sellable" type="checkbox" value="1" @checked(old('sellable', $product->sellable))><span>Bisa dijual di POS</span></label>
                        <label class="check-row"><input type="hidden" name="purchasable" value="0"><input name="purchasable" type="checkbox" value="1" @checked(old('purchasable', $product->purchasable))><span>Bisa dibeli/restok</span></label>
                        <button class="btn primary large" type="submit">Simpan Perubahan</button>
                    </section>
                </form>
                @break

            @case('inventory')
                <section class="page-header">
                    <div><h1>Stok & Inventaris</h1><p>Setiap perubahan stok menghasilkan stock movement.</p></div>
                </section>
                <section class="grid one-one">
                    <article class="card">
                        <h2>Penyesuaian Stok</h2>
                        <form class="form-stack" method="post" action="{{ route('inventory.adjust') }}">
                            @csrf
                            <div class="field-with-action">
                                <label><span>Produk</span><select id="adjustProductSelect" name="product_id" required>@foreach ($products as $product)<option value="{{ $product->id }}">{{ $product->name }} ({{ (float) $product->stock }})</option>@endforeach</select></label>
                                <button class="btn secondary scan-button" type="button" data-open-scanner data-scan-mode="select-product" data-target-select="#adjustProductSelect" aria-haspopup="dialog">
                                    <span class="material-symbols-outlined" aria-hidden="true">photo_camera</span>
                                    Scan
                                </button>
                            </div>
                            <label><span>Stok fisik</span><input name="actual_quantity" type="number" min="0" step="0.001" required></label>
                            <label><span>Alasan</span><input name="reason" required placeholder="Contoh: hasil stok opname"></label>
                            <button class="btn primary" type="submit">Simpan Penyesuaian</button>
                        </form>
                    </article>
                    <article class="card">
                        <h2>Stok Opname Cepat</h2>
                        <form class="form-stack" method="post" action="{{ route('inventory.stock-opnames') }}" data-confirm="Setujui stok opname dan ubah stok sistem?">
                            @csrf
                            <div class="field-with-action">
                                <label><span>Produk</span><select id="opnameProductSelect" name="product_id" required>@foreach ($products as $product)<option value="{{ $product->id }}">{{ $product->name }} (sistem: {{ (float) $product->stock }})</option>@endforeach</select></label>
                                <button class="btn secondary scan-button" type="button" data-open-scanner data-scan-mode="select-product" data-target-select="#opnameProductSelect" aria-haspopup="dialog">
                                    <span class="material-symbols-outlined" aria-hidden="true">photo_camera</span>
                                    Scan
                                </button>
                            </div>
                            <label><span>Jumlah aktual</span><input name="actual_quantity" type="number" min="0" step="0.001" required></label>
                            <label><span>Alasan opname</span><input name="reason" required placeholder="Contoh: opname rak pagi"></label>
                            <button class="btn primary" type="submit">Setujui Opname</button>
                        </form>
                    </article>
                </section>
                <section class="card">
                    <h2>Ringkasan Stok</h2>
                    <div class="mini-list">
                        @foreach ($products->take(8) as $product)
                            <div><span>{{ $product->name }}</span><strong>{{ (float) $product->stock }} {{ $product->unit_code }}</strong></div>
                        @endforeach
                    </div>
                </section>
                <section class="card">
                    <div class="section-title"><h2>Log Perubahan Stok</h2></div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Waktu</th><th>Produk</th><th>Tipe</th><th>Masuk</th><th>Keluar</th><th>Sesudah</th><th>Alasan</th></tr></thead>
                            <tbody>
                                @forelse ($movements as $movement)
                                    <tr>
                                        <td>{{ \Illuminate\Support\Carbon::parse($movement->created_at)->format('d M Y H:i') }}</td>
                                        <td>{{ $movement->product_name }}</td>
                                        <td><span class="badge neutral">{{ $movement->movement_type }}</span></td>
                                        <td class="num">{{ (float) $movement->quantity_in }}</td>
                                        <td class="num">{{ (float) $movement->quantity_out }}</td>
                                        <td class="num">{{ (float) $movement->stock_after }}</td>
                                        <td>{{ $movement->reason }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="empty-cell">Belum ada pergerakan stok.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
                @break

            @case('purchases')
                <section class="page-header">
                    <div><h1>Pembelian</h1><p>Terima barang supplier dan stok otomatis bertambah.</p></div>
                </section>
                <section class="card">
                    <h2>Terima Pembelian Cepat</h2>
                    <form class="inline-form" method="post" action="{{ route('purchases.receive') }}">
                        @csrf
                        <label><span>Supplier</span><select name="supplier_id" required>@foreach ($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></label>
                        <div class="field-with-action">
                            <label><span>Produk</span><select id="purchaseProductSelect" name="product_id" required>@foreach ($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select></label>
                            <button class="btn secondary scan-button" type="button" data-open-scanner data-scan-mode="select-product" data-target-select="#purchaseProductSelect" aria-haspopup="dialog">
                                <span class="material-symbols-outlined" aria-hidden="true">photo_camera</span>
                                Scan
                            </button>
                        </div>
                        <label><span>Jumlah</span><input name="quantity" type="number" min="0.001" step="0.001" required></label>
                        <label><span>Harga beli</span><input name="unit_cost" type="number" min="0" required></label>
                        <button class="btn primary" type="submit">Terima Barang</button>
                    </form>
                </section>
                <section class="card">
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Nomor PO</th><th>Supplier</th><th>Tanggal</th><th>Total</th><th>Status</th></tr></thead>
                            <tbody>
                                @foreach ($orders as $order)
                                    <tr><td>{{ $order->order_number }}</td><td>{{ $order->supplier_name }}</td><td>{{ $order->order_date }}</td><td class="num">{{ $money($order->total_amount) }}</td><td><span class="badge {{ $statusClass($order->status) }}">{{ $order->status }}</span></td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ $orders->links() }}
                </section>
                @break

            @case('sales')
                <section class="page-header">
                    <div><h1>Penjualan</h1><p>Riwayat transaksi, pembayaran, dan retur.</p></div>
                    <a class="btn secondary" href="{{ route('reports.sales.export') }}">Export Excel</a>
                </section>
                <section class="card">
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Invoice</th><th>Pelanggan</th><th>Kasir</th><th>Total</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($sales as $sale)
                                    <tr>
                                        <td>{{ $sale->invoice_number }}</td>
                                        <td>{{ $sale->customer_name ?? 'Pelanggan Umum' }}</td>
                                        <td>{{ $sale->cashier_name }}</td>
                                        <td class="num">{{ $money($sale->total_amount) }}</td>
                                        <td><span class="badge {{ $statusClass($sale->status) }}">{{ $sale->status }}</span></td>
                                        <td><a class="btn small secondary" href="{{ route('sales.show', $sale->id) }}">Detail</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ $sales->links() }}
                </section>
                @break

            @case('sale-detail')
                <section class="page-header">
                    <div><h1>{{ $sale->invoice_number }}</h1><p>{{ $sale->cashier_name }} - {{ \Illuminate\Support\Carbon::parse($sale->created_at)->format('d M Y H:i') }}</p></div>
                    <button class="btn secondary" type="button" data-print-receipt>Cetak Struk</button>
                </section>
                <section class="grid two-one">
                    <article class="card">
                        <h2>Daftar Produk</h2>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Produk</th><th>Qty</th><th>Harga</th><th>Total</th></tr></thead>
                                <tbody>
                                    @foreach ($items as $item)
                                        <tr><td>{{ $item->product_name }}</td><td class="num">{{ (float) $item->quantity }}</td><td class="num">{{ $money($item->unit_price) }}</td><td class="num">{{ $money($item->total) }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($sale->status === 'completed')
                            @if ($user->hasRole('owner') || $user->hasRole('admin') || $user->hasRole('supervisor'))
                                <form class="danger-zone" method="post" action="{{ route('sales.refund', $sale->id) }}" data-confirm="Buat retur dan refund transaksi ini?">
                                    @csrf
                                    <label><span>Alasan retur</span><input name="reason" required placeholder="Contoh: barang dikembalikan pelanggan"></label>
                                    <button class="btn danger" type="submit">Buat Retur & Refund</button>
                                </form>
                                <form class="danger-zone" method="post" action="{{ route('sales.cancel', $sale->id) }}" data-confirm="Batalkan transaksi ini dan kembalikan semua stok?">
                                    @csrf
                                    <label><span>Alasan pembatalan</span><input name="reason" required placeholder="Contoh: salah input transaksi"></label>
                                    <button class="btn danger" type="submit">Batalkan Transaksi</button>
                                </form>
                            @endif
                        @endif
                    </article>
                    <aside class="card receipt">
                        <h2>{{ $settings['store.name'] ?? 'Warung Makmur' }}</h2>
                        <p>{{ $settings['store.address'] ?? 'Jl. Merdeka No. 123' }}</p>
                        <div class="receipt-lines">
                            <div><span>Subtotal</span><strong>{{ $money($sale->subtotal) }}</strong></div>
                            <div><span>Diskon</span><strong>{{ $money($sale->discount_amount) }}</strong></div>
                            <div class="total"><span>Total</span><strong>{{ $money($sale->total_amount) }}</strong></div>
                            <div><span>Dibayar</span><strong>{{ $money($sale->paid_amount) }}</strong></div>
                            <div><span>Kembalian</span><strong>{{ $money($sale->change_amount) }}</strong></div>
                        </div>
                        @foreach ($payments as $payment)
                            <span class="badge success">{{ $payment->method_name }} {{ $payment->status }}</span>
                        @endforeach
                        <p class="receipt-footer">{{ $settings['receipt.footer'] ?? 'Terima kasih atas kunjungan Anda.' }}</p>
                    </aside>
                </section>
                @break

            @case('customers')
            @case('suppliers')
                <section class="page-header">
                    <div><h1>{{ $title }}</h1><p>Data relasi operasional toko.</p></div>
                </section>
                <section class="grid one-one">
                    <article class="card">
                        <h2>Tambah {{ $title }}</h2>
                        <form class="form-stack" method="post" action="{{ $active === 'customers' ? route('customers.store') : route('suppliers.store') }}">
                            @csrf
                            <label><span>Nama</span><input name="name" required></label>
                            <label><span>Telepon</span><input name="phone"></label>
                            @if ($active === 'suppliers')
                                <label><span>Kontak</span><input name="contact_person"></label>
                            @endif
                            <label><span>Alamat</span><textarea name="address" rows="3"></textarea></label>
                            <button class="btn primary" type="submit">Simpan</button>
                        </form>
                    </article>
                    <article class="card">
                        <h2>Daftar {{ $title }}</h2>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Nama</th><th>Telepon</th><th>Status</th></tr></thead>
                                <tbody>
                                    @foreach ($rows as $row)
                                        <tr><td>{{ $row->name }}</td><td>{{ $row->phone ?? '-' }}</td><td><span class="badge success">{{ $row->status }}</span></td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $rows->links() }}
                    </article>
                </section>
                @break

            @case('expenses')
                <section class="page-header"><div><h1>Pengeluaran</h1><p>Kas keluar dan biaya operasional warung.</p></div></section>
                <section class="grid one-one">
                    <article class="card">
                        <h2>Catat Pengeluaran</h2>
                        <form class="form-stack" method="post" action="{{ route('expenses.store') }}">
                            @csrf
                            <label><span>Kategori</span><select name="expense_category_id">@foreach ($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label>
                            <label><span>Jumlah</span><input name="amount" type="number" min="0" required></label>
                            <label><span>Tanggal</span><input name="expense_date" type="date" value="{{ now()->toDateString() }}" required></label>
                            <label><span>Keterangan</span><input name="description" required></label>
                            <button class="btn primary" type="submit">Simpan Pengeluaran</button>
                        </form>
                    </article>
                    <article class="card">
                        <h2>Riwayat</h2>
                        <div class="table-wrap"><table><thead><tr><th>Tanggal</th><th>Keterangan</th><th>Jumlah</th></tr></thead><tbody>@foreach ($expenses as $expense)<tr><td>{{ $expense->expense_date }}</td><td>{{ $expense->description }}</td><td class="num">{{ $money($expense->amount) }}</td></tr>@endforeach</tbody></table></div>
                        {{ $expenses->links() }}
                    </article>
                </section>
                @break

            @case('reports')
                <section class="page-header">
                    <div><h1>Laporan</h1><p>Filter penjualan, laba kotor, metode bayar, dan produk terlaris.</p></div>
                    <a class="btn secondary" href="{{ route('reports.sales.export') }}">Export Excel</a>
                </section>
                <section class="card">
                    <form class="filter-row" method="get" action="{{ route('reports') }}">
                        <label><span>Dari</span><input name="from" type="date" value="{{ $from }}"></label>
                        <label><span>Sampai</span><input name="to" type="date" value="{{ $to }}"></label>
                        <button class="btn primary" type="submit">Terapkan Filter</button>
                    </form>
                </section>
                <section class="kpi-grid">
                    <article class="card kpi"><p>Omzet</p><strong>{{ $money($summary['omzet']) }}</strong><small>Periode terfilter</small></article>
                    <article class="card kpi"><p>Laba Kotor</p><strong>{{ $money($summary['profit']) }}</strong><small>Gross profit</small></article>
                    <article class="card kpi"><p>Transaksi</p><strong>{{ $summary['transactions'] }}</strong><small>Nota selesai</small></article>
                    <article class="card kpi"><p>Tunai</p><strong>{{ $money($summary['cash']) }}</strong><small>Masuk shift kasir</small></article>
                </section>
                <section class="grid one-one">
                    <article class="card"><h2>Produk Terlaris</h2><div class="mini-list">@foreach ($bestProducts as $item)<div><span>{{ $item->product_name }}</span><strong>{{ $money($item->total) }}</strong></div>@endforeach</div></article>
                    <article class="card"><h2>Metode Pembayaran</h2><div class="mini-list">@foreach ($paymentMethods as $method)<div><span>{{ $method->name }}</span><strong>{{ $money($method->total) }}</strong></div>@endforeach</div></article>
                </section>
                @break

            @case('users')
                <section class="page-header"><div><h1>Pengguna</h1><p>Role owner, admin, supervisor, dan kasir.</p></div></section>
                <section class="grid one-one">
                    <article class="card">
                        <h2>Tambah Pengguna</h2>
                        <form class="form-stack" method="post" action="{{ route('users.store') }}">
                            @csrf
                            <label><span>Nama</span><input name="name" required></label>
                            <label><span>Username</span><input name="username" required></label>
                            <label><span>Email</span><input name="email" type="email" required></label>
                            <label><span>Telepon</span><input name="phone"></label>
                            <label><span>Role</span><select name="role_id" required>@foreach ($roles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach</select></label>
                            <label><span>Password awal</span><input name="password" type="password" minlength="8" required></label>
                            <button class="btn primary" type="submit">Simpan Pengguna</button>
                        </form>
                    </article>
                    <article class="card">
                        <h2>Daftar Pengguna</h2>
                        <div class="table-wrap">
                            <table><thead><tr><th>Nama</th><th>Username</th><th>Role</th><th>Status</th></tr></thead><tbody>
                                @foreach ($users as $row)
                                    <tr><td>{{ $row->name }}</td><td>{{ $row->username }}</td><td>{{ $row->roles->pluck('name')->join(', ') }}</td><td><span class="badge {{ $statusClass($row->status) }}">{{ $row->status }}</span></td></tr>
                                @endforeach
                            </tbody></table>
                        </div>
                        {{ $users->links() }}
                    </article>
                </section>
                @break

            @case('audit')
                <section class="page-header"><div><h1>Audit Log</h1><p>Riwayat ini tidak memiliki aksi edit atau hapus.</p></div></section>
                <section class="card">
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Waktu</th><th>User</th><th>Modul</th><th>Aksi</th><th>Record</th><th>IP</th></tr></thead>
                            <tbody>@foreach ($logs as $log)<tr><td>{{ $log->created_at }}</td><td>{{ $log->user_name ?? 'Sistem' }}</td><td>{{ $log->module }}</td><td>{{ $log->action }}</td><td>{{ $log->record_id }}</td><td>{{ $log->ip_address }}</td></tr>@endforeach</tbody>
                        </table>
                    </div>
                    {{ $logs->links() }}
                </section>
                @break

            @case('settings')
                <section class="page-header">
                    <div><h1>Pengaturan Toko</h1><p>Profil toko, POS, struk, backup, dan batas operasional.</p></div>
                    <a class="btn secondary" href="{{ route('settings.backup') }}">Unduh Backup JSON</a>
                </section>
                <form class="grid one-one" method="post" action="{{ route('settings.update') }}" enctype="multipart/form-data">
                    @csrf
                    <section class="card form-section">
                        <h2>Profil Toko</h2>
                        @if (! empty($settings['store.logo_path']))
                            <img class="settings-logo-preview" src="{{ route('media', ['path' => $settings['store.logo_path']]) }}" alt="{{ $settings['store.name'] ?? 'Logo toko' }}">
                        @endif
                        <label><span>Logo toko</span><input name="store_logo" type="file" accept="image/png,image/jpeg,image/webp"></label>
                        <label><span>Nama toko</span><input name="store__name" value="{{ $settings['store.name'] ?? '' }}"></label>
                        <label><span>Alamat</span><textarea name="store__address" rows="3">{{ $settings['store.address'] ?? '' }}</textarea></label>
                        <label><span>Telepon</span><input name="store__phone" value="{{ $settings['store.phone'] ?? '' }}"></label>
                        <label><span>Footer struk</span><textarea name="receipt__footer" rows="3">{{ $settings['receipt.footer'] ?? '' }}</textarea></label>
                    </section>
                    <section class="card form-section">
                        <h2>Pengaturan POS</h2>
                        <label><span>Pajak default (%)</span><input name="pos__tax_rate" type="number" min="0" value="{{ $settings['pos.tax_rate'] ?? 0 }}"></label>
                        <label><span>Batas diskon kasir (%)</span><input name="pos__discount_limit" type="number" min="0" value="{{ $settings['pos.discount_limit'] ?? 10 }}"></label>
                        <label><span>Retensi backup (hari)</span><input name="backup__retention_days" type="number" min="1" value="{{ $settings['backup.retention_days'] ?? 30 }}"></label>
                        <button class="btn primary large" type="submit">Simpan Pengaturan</button>
                        <p class="alert warning">Sebelum production, ganti password default, aktifkan HTTPS, queue worker, scheduler, monitoring error, dan backup database terjadwal.</p>
                    </section>
                </form>
                <section class="card">
                    <h2>Key Pengaturan</h2>
                    <div class="table-wrap"><table><thead><tr><th>Group</th><th>Key</th><th>Value</th></tr></thead><tbody>@foreach ($settingsRows as $row)<tr><td>{{ $row->group }}</td><td>{{ $row->key }}</td><td>{{ $row->value }}</td></tr>@endforeach</tbody></table></div>
                </section>
                @break
        @endswitch

        <div class="modal" id="barcodeScanModal" hidden data-scan-modal data-products="{{ $productsJson ?? '[]' }}" role="dialog" aria-modal="true" aria-labelledby="barcodeScanTitle">
            <div class="modal-panel scan-panel">
                <header>
                    <h2 id="barcodeScanTitle">Scan Barcode</h2>
                    <button class="icon-button" type="button" data-close-scanner aria-label="Tutup scanner">&times;</button>
                </header>
                <div class="scan-camera">
                    <video id="barcodeVideo" class="camera-preview" muted playsinline hidden></video>
                    <div class="camera-empty" data-camera-empty>
                        <span class="material-symbols-outlined" aria-hidden="true">barcode_scanner</span>
                    </div>
                </div>
                <div class="scanner-actions">
                    <button class="btn secondary" type="button" data-start-camera>
                        <span class="material-symbols-outlined" aria-hidden="true">photo_camera</span>
                        Kamera
                    </button>
                    <label class="scan-input"><span>Input barcode</span><input id="scannerCodeInput" autocomplete="off"></label>
                </div>
                <p class="muted" data-scan-status>Siap scan.</p>
                <a class="btn primary" href="{{ route('products.create') }}" data-create-from-scan hidden>Tambah Produk</a>
            </div>
        </div>
    </main>
</body>
</html>
