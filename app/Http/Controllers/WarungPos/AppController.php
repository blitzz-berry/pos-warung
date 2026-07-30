<?php

namespace App\Http\Controllers\WarungPos;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WarungPos\SaleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AppController extends Controller
{
    public function loginForm()
    {
        return Auth::check()
            ? redirect()->route('dashboard')
            : view('warungpos.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $key = Str::lower($credentials['login']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['login' => 'Terlalu banyak percobaan login. Coba lagi sebentar.']);
        }

        $field = filter_var($credentials['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $user = User::where($field, $credentials['login'])->first();

        if (! $user || $user->status !== 'active' || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['login' => 'Username/email atau kata sandi salah.']);
        }

        RateLimiter::clear($key);
        Auth::login($user, (bool) ($credentials['remember'] ?? false));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit('login', 'auth', $user->id, null, ['login' => $credentials['login']]);

        return redirect()->intended(route('dashboard'))->with('status', 'Berhasil masuk.');
    }

    public function logout(Request $request)
    {
        $this->audit('logout', 'auth', Auth::id(), null, null);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function dashboard()
    {
        $today = now()->toDateString();
        $sales = DB::table('sales')->whereDate('created_at', $today)->where('status', 'completed');

        return $this->screen('dashboard', [
            'title' => 'Dashboard',
            'kpis' => [
                ['label' => 'Penjualan Hari Ini', 'value' => $this->rupiah((clone $sales)->sum('total_amount')), 'hint' => 'Omzet realtime dari transaksi paid', 'tone' => 'primary'],
                ['label' => 'Transaksi', 'value' => (string) (clone $sales)->count(), 'hint' => 'Nota selesai hari ini', 'tone' => 'info'],
                ['label' => 'Laba Kotor', 'value' => $this->rupiah((clone $sales)->sum('gross_profit')), 'hint' => 'Penjualan bersih dikurangi HPP', 'tone' => 'success'],
                ['label' => 'Produk Hampir Habis', 'value' => (string) $this->lowStockQuery()->count(), 'hint' => 'Butuh restok segera', 'tone' => 'warning'],
            ],
            'lowStock' => $this->lowStockQuery()->limit(6)->get(),
            'topProducts' => DB::table('sale_items')
                ->select('product_name', DB::raw('SUM(quantity) as qty'), DB::raw('SUM(total) as total'))
                ->groupBy('product_name')
                ->orderByDesc('qty')
                ->limit(5)
                ->get(),
            'recentSales' => DB::table('sales')
                ->join('users', 'users.id', '=', 'sales.cashier_id')
                ->select('sales.*', 'users.name as cashier_name')
                ->orderByDesc('sales.created_at')
                ->limit(6)
                ->get(),
            'recentAudits' => $this->auditQuery()->limit(6)->get(),
        ]);
    }

    public function pos()
    {
        $products = $this->productQuery()->where('products.is_active', true)->where('products.sellable', true)->get();

        return $this->screen('pos', [
            'title' => 'POS Kasir',
            'products' => $products,
            'productsJson' => $this->scannerProductsJson($products),
            'categories' => DB::table('categories')->where('status', 'active')->orderBy('name')->get(),
            'paymentMethods' => DB::table('payment_methods')->where('is_active', true)->orderBy('id')->get(),
            'activeShift' => $this->activeShift(),
        ]);
    }

    public function checkout(Request $request, SaleService $sales)
    {
        $data = $request->validate([
            'items' => ['required'],
            'payment_method' => ['required', 'string'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $data['items'] = is_string($data['items']) ? json_decode($data['items'], true) : $data['items'];
        $sale = $sales->checkout($request->user(), $data);

        return redirect()->route('sales.show', $sale->id)->with('status', 'Transaksi berhasil disimpan.');
    }

    public function openShift(Request $request, SaleService $sales)
    {
        $data = $request->validate([
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'opening_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $sales->openShift($request->user(), (float) $data['opening_cash'], $data['opening_notes'] ?? '');

        return back()->with('status', 'Shift kasir dibuka.');
    }

    public function closeShift(Request $request, SaleService $sales)
    {
        $data = $request->validate([
            'actual_cash' => ['required', 'numeric', 'min:0'],
            'closing_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $sales->closeShift($request->user(), (float) $data['actual_cash'], $data['closing_notes'] ?? '');

        return back()->with('status', 'Shift kasir ditutup.');
    }

    public function products()
    {
        return $this->screen('products', [
            'title' => 'Produk',
            'products' => $this->productQuery()->paginate(12),
            'categories' => DB::table('categories')->orderBy('name')->get(),
        ]);
    }

    public function createProduct()
    {
        return $this->screen('product-create', [
            'title' => 'Tambah Produk',
            'categories' => DB::table('categories')->where('status', 'active')->orderBy('name')->get(),
            'brands' => DB::table('brands')->orderBy('name')->get(),
            'units' => DB::table('units')->orderBy('name')->get(),
        ]);
    }

    public function showProduct(int $id)
    {
        $product = $this->productQuery()->where('products.id', $id)->firstOrFail();

        return $this->screen('product-detail', [
            'title' => 'Detail Produk',
            'product' => $product,
            'categories' => DB::table('categories')->where('status', 'active')->orderBy('name')->get(),
            'brands' => DB::table('brands')->orderBy('name')->get(),
            'units' => DB::table('units')->orderBy('name')->get(),
        ]);
    }

    public function storeProduct(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:60', 'unique:products,sku'],
            'barcode' => ['nullable', 'string', 'max:80', 'unique:product_barcodes,barcode'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'base_unit_id' => ['required', 'integer', 'exists:units,id'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'opening_stock' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $imagePath = $request->hasFile('image') ? $this->storePublicImage($request->file('image'), 'products') : null;

        DB::transaction(function () use ($request, $data, $imagePath) {
            $storeId = $this->storeId();
            $warehouseId = $this->warehouseId($storeId);
            $stock = round((float) ($data['opening_stock'] ?? 0), 3);
            $slug = Str::slug($data['name']);
            if (DB::table('products')->where('slug', $slug)->exists()) {
                $slug .= '-'.Str::lower($data['sku']);
            }

            $productId = DB::table('products')->insertGetId([
                'sku' => $data['sku'],
                'name' => $data['name'],
                'slug' => $slug,
                'category_id' => $data['category_id'] ?? null,
                'brand_id' => $data['brand_id'] ?? null,
                'base_unit_id' => $data['base_unit_id'],
                'description' => $data['description'] ?? null,
                'image_path' => $imagePath,
                'product_type' => 'unit',
                'purchase_price' => $data['purchase_price'],
                'selling_price' => $data['selling_price'],
                'wholesale_price' => $data['selling_price'],
                'minimum_stock' => $data['minimum_stock'] ?? 0,
                'track_stock' => true,
                'track_batch' => false,
                'track_expiry' => false,
                'allow_negative_stock' => false,
                'taxable' => false,
                'tax_rate' => 0,
                'is_active' => true,
                'sellable' => true,
                'purchasable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $productUnitId = DB::table('product_units')->insertGetId([
                'product_id' => $productId,
                'unit_id' => $data['base_unit_id'],
                'conversion_to_base' => 1,
                'purchase_price' => $data['purchase_price'],
                'selling_price' => $data['selling_price'],
                'is_default_purchase' => true,
                'is_default_sale' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (! empty($data['barcode'])) {
                DB::table('product_barcodes')->insert([
                    'product_id' => $productId,
                    'product_unit_id' => $productUnitId,
                    'barcode' => $data['barcode'],
                    'is_primary' => true,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('inventories')->insert([
                'store_id' => $storeId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'quantity' => $stock,
                'reserved_quantity' => 0,
                'available_quantity' => $stock,
                'average_cost' => $data['purchase_price'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('stock_movements')->insert([
                'store_id' => $storeId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'movement_type' => 'opening_stock',
                'quantity_in' => $stock,
                'quantity_out' => 0,
                'stock_before' => 0,
                'stock_after' => $stock,
                'unit_cost' => $data['purchase_price'],
                'reason' => 'Stok awal produk',
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit('created', 'products', $productId, null, ['sku' => $data['sku'], 'stock' => $stock]);
        });

        return redirect()->route('products')->with('status', 'Produk berhasil ditambahkan.');
    }

    public function updateProduct(Request $request, int $id)
    {
        $product = DB::table('products')->where('id', $id)->firstOrFail();
        $barcodeId = DB::table('product_barcodes')->where('product_id', $id)->where('is_primary', true)->value('id');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:60', Rule::unique('products', 'sku')->ignore($id)],
            'barcode' => ['nullable', 'string', 'max:80', Rule::unique('product_barcodes', 'barcode')->ignore($barcodeId)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'base_unit_id' => ['required', 'integer', 'exists:units,id'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
            'sellable' => ['nullable', 'boolean'],
            'purchasable' => ['nullable', 'boolean'],
        ]);

        $imagePath = $request->hasFile('image')
            ? $this->storePublicImage($request->file('image'), 'products')
            : $product->image_path;

        DB::transaction(function () use ($request, $id, $product, $data, $imagePath) {
            $slug = Str::slug($data['name']);
            if (DB::table('products')->where('slug', $slug)->where('id', '!=', $id)->exists()) {
                $slug .= '-'.Str::lower($data['sku']);
            }

            DB::table('products')->where('id', $id)->update([
                'sku' => $data['sku'],
                'name' => $data['name'],
                'slug' => $slug,
                'category_id' => $data['category_id'] ?? null,
                'brand_id' => $data['brand_id'] ?? null,
                'base_unit_id' => $data['base_unit_id'],
                'description' => $data['description'] ?? null,
                'image_path' => $imagePath,
                'purchase_price' => $data['purchase_price'],
                'selling_price' => $data['selling_price'],
                'wholesale_price' => $data['selling_price'],
                'minimum_stock' => $data['minimum_stock'] ?? 0,
                'is_active' => $request->boolean('is_active'),
                'sellable' => $request->boolean('sellable'),
                'purchasable' => $request->boolean('purchasable'),
                'updated_at' => now(),
            ]);

            DB::table('product_units')->where('product_id', $id)->where('unit_id', '!=', $data['base_unit_id'])->update([
                'is_default_purchase' => false,
                'is_default_sale' => false,
                'updated_at' => now(),
            ]);

            DB::table('product_units')->updateOrInsert([
                'product_id' => $id,
                'unit_id' => $data['base_unit_id'],
            ], [
                'conversion_to_base' => 1,
                'purchase_price' => $data['purchase_price'],
                'selling_price' => $data['selling_price'],
                'is_default_purchase' => true,
                'is_default_sale' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $productUnitId = DB::table('product_units')->where('product_id', $id)->where('unit_id', $data['base_unit_id'])->value('id');
            if (! empty($data['barcode'])) {
                DB::table('product_barcodes')->updateOrInsert([
                    'product_id' => $id,
                    'is_primary' => true,
                ], [
                    'product_unit_id' => $productUnitId,
                    'barcode' => $data['barcode'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('product_barcodes')->where('product_id', $id)->where('is_primary', true)->delete();
            }

            if ($request->hasFile('image') && $product->image_path) {
                Storage::disk('public')->delete($product->image_path);
            }

            $this->audit('updated', 'products', $id, (array) $product, [
                'sku' => $data['sku'],
                'barcode' => $data['barcode'] ?? null,
                'image_path' => $imagePath,
            ]);
        });

        return redirect()->route('products.show', $id)->with('status', 'Produk berhasil diperbarui.');
    }

    public function importProducts(Request $request)
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:4096',
                'mimes:csv,txt,xlsx',
                'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        ]);

        $allowedHeaders = ['sku', 'name', 'barcode', 'category', 'unit', 'purchase_price', 'selling_price', 'stock', 'minimum_stock'];
        $requiredHeaders = ['sku', 'name'];
        [$headers, $rows] = $this->importRows($request->file('file'));
        if (array_diff($requiredHeaders, $headers) || array_diff($headers, $allowedHeaders)) {
            throw ValidationException::withMessages(['file' => 'Header file tidak sesuai template produk.']);
        }

        $created = 0;
        $line = 1;
        $storeId = $this->storeId();
        $warehouseId = $this->warehouseId($storeId);

        DB::transaction(function () use ($request, $rows, $headers, $storeId, $warehouseId, &$created, &$line) {
            foreach ($rows as $row) {
                $line++;
                if ($line > 5001) {
                    throw ValidationException::withMessages(['file' => 'Import dibatasi maksimal 5.000 baris per file.']);
                }

                $row = array_slice(array_pad($row, count($headers), null), 0, count($headers));
                $data = array_combine($headers, $row);
                if (! $data || empty($data['sku']) || empty($data['name'])) {
                    continue;
                }

                if (DB::table('products')->where('sku', $data['sku'])->exists()) {
                    continue;
                }

                $unitId = DB::table('units')->where('code', $data['unit'] ?? 'pcs')->value('id') ?: DB::table('units')->value('id');
                $categoryId = DB::table('categories')->where('name', $data['category'] ?? '')->value('id');
                $productId = DB::table('products')->insertGetId([
                    'sku' => $data['sku'],
                    'name' => $data['name'],
                    'slug' => Str::slug($data['name']).'-'.Str::lower($data['sku']),
                    'category_id' => $categoryId,
                    'base_unit_id' => $unitId,
                    'purchase_price' => (float) ($data['purchase_price'] ?? 0),
                    'selling_price' => (float) ($data['selling_price'] ?? 0),
                    'minimum_stock' => (float) ($data['minimum_stock'] ?? 0),
                    'is_active' => true,
                    'sellable' => true,
                    'purchasable' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $productUnitId = DB::table('product_units')->insertGetId([
                    'product_id' => $productId,
                    'unit_id' => $unitId,
                    'conversion_to_base' => 1,
                    'purchase_price' => (float) ($data['purchase_price'] ?? 0),
                    'selling_price' => (float) ($data['selling_price'] ?? 0),
                    'is_default_purchase' => true,
                    'is_default_sale' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if (! empty($data['barcode'])) {
                    DB::table('product_barcodes')->insert([
                        'product_id' => $productId,
                        'product_unit_id' => $productUnitId,
                        'barcode' => $data['barcode'],
                        'is_primary' => true,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $stock = (float) ($data['stock'] ?? 0);
                DB::table('inventories')->insert([
                    'store_id' => $storeId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'quantity' => $stock,
                    'reserved_quantity' => 0,
                    'available_quantity' => $stock,
                    'average_cost' => (float) ($data['purchase_price'] ?? 0),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('stock_movements')->insert([
                    'store_id' => $storeId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'movement_type' => 'opening_stock',
                    'quantity_in' => $stock,
                    'quantity_out' => 0,
                    'stock_before' => 0,
                    'stock_after' => $stock,
                    'unit_cost' => (float) ($data['purchase_price'] ?? 0),
                    'reason' => 'Import produk',
                    'created_by' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $created++;
            }
        });

        $this->audit('imported', 'products', 0, null, ['created' => $created]);

        return back()->with('status', "{$created} produk berhasil diimport.");
    }

    public function exportProducts()
    {
        return $this->xlsx('produk-warungpos.xlsx', ['sku', 'name', 'barcode', 'category', 'unit', 'purchase_price', 'selling_price', 'stock', 'minimum_stock'], $this->productQuery()->get()->map(fn ($p) => [
            $p->sku,
            $p->name,
            $p->barcode,
            $p->category_name,
            $p->unit_code,
            $p->purchase_price,
            $p->selling_price,
            $p->stock,
            $p->minimum_stock,
        ])->all());
    }

    public function inventory()
    {
        $products = $this->productQuery()->get();

        return $this->screen('inventory', [
            'title' => 'Stok',
            'products' => $products,
            'productsJson' => $this->scannerProductsJson($products),
            'movements' => DB::table('stock_movements')
                ->join('products', 'products.id', '=', 'stock_movements.product_id')
                ->leftJoin('users', 'users.id', '=', 'stock_movements.created_by')
                ->select('stock_movements.*', 'products.name as product_name', 'users.name as user_name')
                ->orderByDesc('stock_movements.created_at')
                ->limit(25)
                ->get(),
        ]);
    }

    public function adjustStock(Request $request, SaleService $sales)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'actual_quantity' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $sales->adjustStock($request->user(), (int) $data['product_id'], (float) $data['actual_quantity'], $data['reason']);

        return back()->with('status', 'Stok berhasil disesuaikan.');
    }

    public function stockOpname(Request $request, SaleService $sales)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'actual_quantity' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $opnameId = $sales->stockOpname($request->user(), (int) $data['product_id'], (float) $data['actual_quantity'], $data['reason']);

        return back()->with('status', "Stok opname #{$opnameId} disetujui dan stok diperbarui.");
    }

    public function purchases()
    {
        $products = $this->productQuery()->where('products.purchasable', true)->get();

        return $this->screen('purchases', [
            'title' => 'Pembelian',
            'suppliers' => DB::table('suppliers')->where('status', 'active')->orderBy('name')->get(),
            'products' => $products,
            'productsJson' => $this->scannerProductsJson($products),
            'orders' => DB::table('purchase_orders')
                ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
                ->select('purchase_orders.*', 'suppliers.name as supplier_name')
                ->orderByDesc('purchase_orders.created_at')
                ->paginate(10),
        ]);
    }

    public function receivePurchase(Request $request, SaleService $sales)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $poId = $sales->receivePurchase($request->user(), [
            'supplier_id' => $data['supplier_id'],
            'items' => [[
                'product_id' => $data['product_id'],
                'quantity' => $data['quantity'],
                'unit_cost' => $data['unit_cost'],
            ]],
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('purchases')->with('status', "Pembelian PO #{$poId} diterima dan stok bertambah.");
    }

    public function sales()
    {
        return $this->screen('sales', [
            'title' => 'Penjualan',
            'sales' => DB::table('sales')
                ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
                ->join('users', 'users.id', '=', 'sales.cashier_id')
                ->select('sales.*', 'customers.name as customer_name', 'users.name as cashier_name')
                ->orderByDesc('sales.created_at')
                ->paginate(12),
        ]);
    }

    public function showSale(int $id)
    {
        $sale = DB::table('sales')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->join('users', 'users.id', '=', 'sales.cashier_id')
            ->select('sales.*', 'customers.name as customer_name', 'users.name as cashier_name')
            ->where('sales.id', $id)
            ->firstOrFail();

        return $this->screen('sale-detail', [
            'title' => 'Detail Penjualan',
            'sale' => $sale,
            'items' => DB::table('sale_items')->where('sale_id', $id)->get(),
            'payments' => DB::table('payments')
                ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
                ->select('payments.*', 'payment_methods.name as method_name')
                ->where('sale_id', $id)
                ->get(),
            'returns' => DB::table('sale_returns')->where('sale_id', $id)->get(),
        ]);
    }

    public function refundSale(Request $request, SaleService $sales, int $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $sales->refund($request->user(), $id, $data['reason']);

        return back()->with('status', 'Retur dan refund berhasil dicatat.');
    }

    public function cancelSale(Request $request, SaleService $sales, int $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $sales->cancel($request->user(), $id, $data['reason']);

        return back()->with('status', 'Transaksi berhasil dibatalkan dan stok dikembalikan.');
    }

    public function customers()
    {
        return $this->partyScreen('customers', 'Pelanggan', 'customers');
    }

    public function storeCustomer(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        DB::table('customers')->insert([...$data, 'type' => 'retail', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'Pelanggan berhasil ditambahkan.');
    }

    public function suppliers()
    {
        return $this->partyScreen('suppliers', 'Supplier', 'suppliers');
    }

    public function storeSupplier(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        DB::table('suppliers')->insert([...$data, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'Supplier berhasil ditambahkan.');
    }

    public function expenses()
    {
        return $this->screen('expenses', [
            'title' => 'Pengeluaran',
            'expenses' => DB::table('expenses')
                ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
                ->select('expenses.*', 'expense_categories.name as category_name')
                ->orderByDesc('expense_date')
                ->paginate(12),
            'categories' => DB::table('expense_categories')->orderBy('name')->get(),
        ]);
    }

    public function storeExpense(Request $request)
    {
        $data = $request->validate([
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'description' => ['required', 'string', 'max:500'],
            'expense_date' => ['required', 'date'],
        ]);

        DB::table('expenses')->insert([
            ...$data,
            'store_id' => $this->storeId(),
            'status' => 'approved',
            'payment_method' => 'cash',
            'created_by' => Auth::id(),
            'approved_by' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('status', 'Pengeluaran berhasil dicatat.');
    }

    public function reports()
    {
        $from = request('from', now()->subDays(6)->toDateString());
        $to = request('to', now()->toDateString());
        $sales = DB::table('sales')->whereBetween(DB::raw('DATE(created_at)'), [$from, $to]);

        return $this->screen('reports', [
            'title' => 'Laporan',
            'from' => $from,
            'to' => $to,
            'summary' => [
                'omzet' => (clone $sales)->sum('total_amount'),
                'profit' => (clone $sales)->sum('gross_profit'),
                'transactions' => (clone $sales)->count(),
                'cash' => DB::table('payments')
                    ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
                    ->where('payment_methods.is_cash', true)
                    ->whereBetween(DB::raw('DATE(payments.created_at)'), [$from, $to])
                    ->sum('payments.amount'),
            ],
            'bestProducts' => DB::table('sale_items')
                ->select('product_name', DB::raw('SUM(quantity) as qty'), DB::raw('SUM(total) as total'))
                ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
                ->groupBy('product_name')
                ->orderByDesc('total')
                ->limit(10)
                ->get(),
            'paymentMethods' => DB::table('payments')
                ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
                ->select('payment_methods.name', DB::raw('SUM(payments.amount) as total'))
                ->whereBetween(DB::raw('DATE(payments.created_at)'), [$from, $to])
                ->groupBy('payment_methods.name')
                ->get(),
        ]);
    }

    public function exportSales()
    {
        $rows = DB::table('sales')->orderByDesc('created_at')->get()->map(fn ($sale) => [
            $sale->invoice_number,
            $sale->status,
            $sale->total_amount,
            $sale->gross_profit,
            $sale->created_at,
        ])->all();

        return $this->xlsx('laporan-penjualan.xlsx', ['Invoice', 'Status', 'Total', 'Laba', 'Tanggal'], $rows);
    }

    public function users()
    {
        return $this->screen('users', [
            'title' => 'Pengguna',
            'users' => User::with('roles')->orderBy('name')->paginate(12),
            'roles' => DB::table('roles')->orderBy('name')->get(),
        ]);
    }

    public function storeUser(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'status' => 'active',
        ]);

        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $data['role_id']]);
        DB::table('user_stores')->insert(['user_id' => $user->id, 'store_id' => $this->storeId()]);
        $this->audit('created', 'users', $user->id, null, ['email' => $data['email']]);

        return back()->with('status', 'Pengguna berhasil ditambahkan.');
    }

    public function auditLogs()
    {
        return $this->screen('audit', [
            'title' => 'Audit Log',
            'logs' => $this->auditQuery()->paginate(20),
        ]);
    }

    public function settings()
    {
        return $this->screen('settings', [
            'title' => 'Pengaturan',
            'settingsRows' => DB::table('settings')->orderBy('group')->orderBy('key')->get(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'store_logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        if ($request->hasFile('store_logo')) {
            $oldLogo = DB::table('settings')->where('key', 'store.logo_path')->value('value');
            $logoPath = $this->storePublicImage($request->file('store_logo'), 'logos');
            DB::table('settings')->updateOrInsert(['key' => 'store.logo_path'], [
                'group' => 'store',
                'value' => $logoPath,
                'type' => 'string',
                'updated_at' => now(),
                'created_at' => now(),
            ]);
            if ($oldLogo) {
                Storage::disk('public')->delete($oldLogo);
            }
        }

        foreach ($request->except(['_token', 'store_logo']) as $key => $value) {
            DB::table('settings')->updateOrInsert(['key' => str_replace('__', '.', $key)], [
                'group' => str_contains($key, '__') ? Str::before($key, '__') : 'general',
                'value' => is_array($value) ? json_encode($value) : (string) $value,
                'type' => is_numeric($value) ? 'number' : 'string',
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }

        $this->audit('updated', 'settings', 0, null, ['keys' => array_keys($request->except(['_token', 'store_logo']))]);

        return back()->with('status', 'Pengaturan berhasil disimpan.');
    }

    public function paymentWebhook(Request $request, string $provider, PaymentGateway $gateway)
    {
        $secret = (string) config("services.{$provider}.webhook_secret", env('PAYMENT_WEBHOOK_SECRET', ''));
        $signature = (string) $request->header('X-Signature', '');
        $payload = $request->getContent();
        $valid = $gateway->verifyWebhook($payload, $signature, $secret);

        DB::table('webhook_logs')->insert([
            'provider' => $provider,
            'event' => $request->input('event'),
            'signature' => $signature,
            'signature_valid' => $valid,
            'status' => $valid ? 'processed' : 'rejected',
            'payload' => $payload ?: json_encode($request->all()),
            'processed_at' => $valid ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $valid) {
            return response()->json(['success' => false, 'message' => 'Signature webhook tidak valid.'], 403);
        }

        if ($trx = $request->input('provider_transaction_id')) {
            $payments = DB::table('payments')->where('provider_transaction_id', $trx)->where('status', 'pending')->get();
            DB::table('payments')->whereIn('id', $payments->pluck('id'))->update([
                'status' => 'paid',
                'paid_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($payments as $payment) {
                $sale = DB::table('sales')->where('id', $payment->sale_id)->first();
                $paid = DB::table('payments')->where('sale_id', $payment->sale_id)->where('status', 'paid')->sum('amount');
                if ($sale && (float) $paid >= (float) $sale->total_amount) {
                    DB::table('sales')->where('id', $sale->id)->update(['payment_status' => 'paid', 'updated_at' => now()]);
                }
            }
        }

        return response()->json(['success' => true, 'message' => 'Webhook diproses.']);
    }

    public function backup()
    {
        $tables = ['stores', 'users', 'products', 'inventories', 'sales', 'sale_items', 'payments', 'stock_movements', 'purchase_orders', 'expenses', 'settings'];
        $payload = [];
        foreach ($tables as $table) {
            $payload[$table] = DB::table($table)->get();
        }

        return response()->streamDownload(function () use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT);
        }, 'warungpos-backup-'.now()->format('Ymd-His').'.json', ['Content-Type' => 'application/json']);
    }

    public function publicMedia(string $path)
    {
        abort_if(str_contains($path, '..') || str_starts_with($path, '/'), 404);
        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path);
    }

    private function partyScreen(string $page, string $title, string $table)
    {
        return $this->screen($page, [
            'title' => $title,
            'rows' => DB::table($table)->orderByDesc('created_at')->paginate(12),
        ]);
    }

    private function screen(string $active, array $data)
    {
        return view('warungpos.app', [
            ...$this->base($active),
            ...$data,
        ]);
    }

    private function base(string $active): array
    {
        $user = Auth::user();

        return [
            'active' => $active,
            'user' => $user,
            'roleNames' => $user?->roles()->pluck('name')->all() ?? [],
            'store' => DB::table('stores')->where('id', $this->storeId())->first(),
            'settings' => DB::table('settings')->pluck('value', 'key'),
            'navItems' => $this->navItems($user),
            'unreadNotifications' => DB::table('notifications')->whereNull('read_at')->count(),
            'activeShift' => $this->activeShift(),
        ];
    }

    private function navItems(?User $user): array
    {
        $roles = $user?->roleSlugs() ?? [];
        $ownerish = array_intersect($roles, ['owner', 'admin']);
        $canManage = $ownerish !== [];

        return array_values(array_filter([
            ['key' => 'dashboard', 'label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'dashboard', 'show' => true],
            ['key' => 'pos', 'label' => 'POS Kasir', 'route' => 'pos', 'icon' => 'point_of_sale', 'show' => true],
            ['key' => 'products', 'label' => 'Produk', 'route' => 'products', 'icon' => 'inventory_2', 'show' => $canManage || in_array('supervisor', $roles, true)],
            ['key' => 'inventory', 'label' => 'Stok', 'route' => 'inventory', 'icon' => 'inventory', 'show' => $canManage || in_array('supervisor', $roles, true)],
            ['key' => 'purchases', 'label' => 'Pembelian', 'route' => 'purchases', 'icon' => 'shopping_cart', 'show' => $canManage],
            ['key' => 'sales', 'label' => 'Penjualan', 'route' => 'sales', 'icon' => 'receipt_long', 'show' => true],
            ['key' => 'customers', 'label' => 'Pelanggan', 'route' => 'customers', 'icon' => 'person', 'show' => $canManage || in_array('supervisor', $roles, true)],
            ['key' => 'suppliers', 'label' => 'Supplier', 'route' => 'suppliers', 'icon' => 'local_shipping', 'show' => $canManage],
            ['key' => 'expenses', 'label' => 'Pengeluaran', 'route' => 'expenses', 'icon' => 'payments', 'show' => $canManage],
            ['key' => 'reports', 'label' => 'Laporan', 'route' => 'reports', 'icon' => 'analytics', 'show' => $canManage || in_array('supervisor', $roles, true)],
            ['key' => 'users', 'label' => 'Pengguna', 'route' => 'users', 'icon' => 'group', 'show' => $canManage],
            ['key' => 'audit', 'label' => 'Audit Log', 'route' => 'audit', 'icon' => 'history', 'show' => $canManage],
            ['key' => 'settings', 'label' => 'Pengaturan', 'route' => 'settings', 'icon' => 'settings', 'show' => $canManage],
        ], fn ($item) => $item['show']));
    }

    private function productQuery()
    {
        return DB::table('products')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->leftJoin('units', 'units.id', '=', 'products.base_unit_id')
            ->leftJoin('inventories', 'inventories.product_id', '=', 'products.id')
            ->leftJoin('product_barcodes', function ($join) {
                $join->on('product_barcodes.product_id', '=', 'products.id')->where('product_barcodes.is_primary', true);
            })
            ->select(
                'products.*',
                'categories.name as category_name',
                'brands.name as brand_name',
                'units.code as unit_code',
                'product_barcodes.barcode',
                DB::raw('COALESCE(inventories.available_quantity, 0) as stock'),
                DB::raw('COALESCE(inventories.average_cost, products.purchase_price) as average_cost')
            )
            ->orderBy('products.name');
    }

    private function lowStockQuery()
    {
        return $this->productQuery()
            ->whereColumn('inventories.available_quantity', '<=', 'products.minimum_stock')
            ->where('products.track_stock', true)
            ->where('products.is_active', true);
    }

    private function auditQuery()
    {
        return DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->select('audit_logs.*', 'users.name as user_name')
            ->orderByDesc('audit_logs.created_at');
    }

    private function scannerProductsJson($products): string
    {
        return $products->map(fn ($product) => [
            'id' => $product->id,
            'name' => $product->name,
            'price' => (float) ($product->selling_price ?? 0),
            'stock' => (float) ($product->stock ?? 0),
            'barcode' => $product->barcode,
        ])->values()->toJson();
    }

    private function activeShift(): ?object
    {
        if (! Auth::check()) {
            return null;
        }

        return DB::table('cashier_shifts')
            ->where('user_id', Auth::id())
            ->where('status', 'open')
            ->orderByDesc('opened_at')
            ->first();
    }

    private function storeId(): int
    {
        return (int) (DB::table('user_stores')->where('user_id', Auth::id())->value('store_id') ?: DB::table('stores')->value('id'));
    }

    private function warehouseId(int $storeId): int
    {
        return (int) DB::table('warehouses')->where('store_id', $storeId)->value('id');
    }

    private function audit(string $action, string $module, ?int $recordId, ?array $before, ?array $after): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => Auth::id(),
            'action' => $action,
            'module' => $module,
            'record_id' => $recordId,
            'before_values' => $before ? json_encode($before) : null,
            'after_values' => $after ? json_encode($after) : null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rupiah(float|int|string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    private function storePublicImage($file, string $directory): string
    {
        return $file->store("uploads/{$directory}", 'public');
    }

    private function importRows($file): array
    {
        $path = $file->getRealPath();
        if (Str::lower($file->getClientOriginalExtension()) === 'xlsx') {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);
        } else {
            $handle = fopen($path, 'r');
            if (! $handle) {
                throw ValidationException::withMessages(['file' => 'File import tidak dapat dibaca.']);
            }
            $rows = [];
            while (($row = fgetcsv($handle)) !== false) {
                $rows[] = $row;
            }
            fclose($handle);
        }

        $headers = array_map(fn ($header) => Str::lower(trim((string) $header)), array_shift($rows) ?: []);

        return [$headers, $rows];
    }

    private function xlsx(string $filename, array $headers, array $rows)
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            foreach ([$headers, ...$rows] as $rowIndex => $row) {
                foreach (array_values($row) as $columnIndex => $value) {
                    $cell = Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 1);
                    if (is_int($value) || is_float($value)) {
                        $sheet->setCellValue($cell, $value);
                    } else {
                        $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
                    }
                }
            }

            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
