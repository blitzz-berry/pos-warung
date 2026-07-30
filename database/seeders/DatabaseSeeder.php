<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $seedPassword = env('WARUNGPOS_SEED_PASSWORD', app()->environment('production') ? null : 'password');
        $seedPin = env('WARUNGPOS_SEED_PIN', app()->environment('production') ? null : '123456');

        if (! $seedPassword || ! $seedPin) {
            throw new RuntimeException('Set WARUNGPOS_SEED_PASSWORD and WARUNGPOS_SEED_PIN before seeding production users.');
        }

        $storeId = $this->upsertId('stores', ['code' => 'JKT-001'], [
            'name' => 'Warung Makmur',
            'address' => 'Jl. Merdeka No. 123, Jakarta Selatan',
            'phone' => '0812-3456-7890',
            'email' => 'kontak@warungmakmur.test',
            'timezone' => 'Asia/Jakarta',
            'currency' => 'IDR',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $warehouseId = $this->upsertId('warehouses', ['store_id' => $storeId, 'code' => 'GUD-UTAMA'], [
            'name' => 'Gudang Utama',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $terminalId = $this->upsertId('terminals', ['store_id' => $storeId, 'code' => 'KSR-01'], [
            'name' => 'Kasir Depan',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roles = [
            'owner' => 'Owner',
            'admin' => 'Admin',
            'supervisor' => 'Supervisor',
            'kasir' => 'Kasir',
        ];

        $roleIds = [];
        foreach ($roles as $slug => $name) {
            $roleIds[$slug] = $this->upsertId('roles', ['slug' => $slug], [
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissions = [
            'dashboard' => ['dashboard.view'],
            'pos' => ['pos.access', 'sale.create', 'sale.view', 'sale.cancel', 'sale.refund', 'sale.reprint_receipt'],
            'payment' => ['payment.cash', 'payment.non_cash', 'payment.split', 'payment.refund'],
            'product' => ['product.view', 'product.create', 'product.update', 'product.delete', 'product.import', 'product.export'],
            'stock' => ['stock.view', 'stock.adjust', 'stock.transfer', 'stock.opname'],
            'purchase' => ['purchase.view', 'purchase.create', 'purchase.receive', 'purchase.cancel', 'purchase.return'],
            'party' => ['supplier.manage', 'customer.manage'],
            'shift' => ['shift.open', 'shift.close', 'shift.view_all'],
            'expense' => ['expense.create', 'expense.approve'],
            'report' => ['report.sales', 'report.profit', 'report.inventory', 'report.cashier'],
            'admin' => ['user.manage', 'role.manage', 'setting.manage', 'audit.view'],
        ];

        $permissionIds = [];
        foreach ($permissions as $module => $slugs) {
            foreach ($slugs as $slug) {
                $permissionIds[$slug] = $this->upsertId('permissions', ['slug' => $slug], [
                    'module' => $module,
                    'name' => Str::headline(str_replace('.', ' ', $slug)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $rolePermissions = [
            'owner' => array_keys($permissionIds),
            'admin' => array_values(array_filter(array_keys($permissionIds), fn ($slug) => ! str_starts_with($slug, 'role.'))),
            'supervisor' => [
                'dashboard.view', 'pos.access', 'sale.create', 'sale.view', 'sale.cancel', 'sale.refund',
                'discount.override_limit', 'payment.cash', 'payment.non_cash', 'payment.refund',
                'product.view', 'product.create', 'product.update', 'stock.view', 'stock.adjust', 'purchase.view', 'supplier.manage',
                'customer.manage', 'shift.open', 'shift.close', 'shift.view_all', 'report.sales',
                'report.inventory', 'report.cashier', 'audit.view',
            ],
            'kasir' => ['dashboard.view', 'pos.access', 'sale.create', 'sale.view', 'payment.cash', 'payment.non_cash', 'shift.open', 'shift.close'],
        ];

        foreach ($rolePermissions as $role => $slugs) {
            foreach ($slugs as $slug) {
                if (isset($permissionIds[$slug])) {
                    DB::table('role_permissions')->updateOrInsert([
                        'role_id' => $roleIds[$role],
                        'permission_id' => $permissionIds[$slug],
                    ]);
                }
            }
        }

        $users = [
            ['role' => 'owner', 'name' => 'Sari Owner', 'username' => 'owner', 'email' => 'owner@warungpos.test', 'phone' => '0812-1111-0001'],
            ['role' => 'admin', 'name' => 'Dian Admin', 'username' => 'admin', 'email' => 'admin@warungpos.test', 'phone' => '0812-1111-0002'],
            ['role' => 'supervisor', 'name' => 'Raka Supervisor', 'username' => 'supervisor', 'email' => 'supervisor@warungpos.test', 'phone' => '0812-1111-0003'],
            ['role' => 'kasir', 'name' => 'Bima Kasir', 'username' => 'kasir', 'email' => 'kasir@warungpos.test', 'phone' => '0812-1111-0004'],
        ];

        $userIds = [];
        foreach ($users as $user) {
            $userIds[$user['role']] = $this->upsertId('users', ['email' => $user['email']], [
                'name' => $user['name'],
                'username' => $user['username'],
                'phone' => $user['phone'],
                'password' => Hash::make($seedPassword),
                'pin_hash' => Hash::make($seedPin),
                'status' => 'active',
                'email_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('user_roles')->updateOrInsert(['user_id' => $userIds[$user['role']], 'role_id' => $roleIds[$user['role']]]);
            DB::table('user_stores')->updateOrInsert(['user_id' => $userIds[$user['role']], 'store_id' => $storeId]);
        }

        $categoryIds = [];
        foreach (['Beras', 'Minyak', 'Gula', 'Mie Instan', 'Minuman', 'Rumah Tangga'] as $name) {
            $categoryIds[$name] = $this->upsertId('categories', ['slug' => Str::slug($name)], [
                'name' => $name,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $brandIds = [];
        foreach (['Makmur', 'Tani Jaya', 'Sedaap', 'Bimoli', 'Indofood', 'Aqua', 'Wings'] as $name) {
            $brandIds[$name] = $this->upsertId('brands', ['slug' => Str::slug($name)], [
                'name' => $name,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $unitIds = [];
        foreach ([['Pcs', 'pcs'], ['Pack', 'pack'], ['Dus', 'dus'], ['Botol', 'botol'], ['Kilogram', 'kg'], ['Liter', 'liter']] as [$name, $code]) {
            $unitIds[$code] = $this->upsertId('units', ['code' => $code], [
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $supplierIds = [];
        foreach ([
            ['name' => 'PT Sumber Sembako Jaya', 'phone' => '021-555-0101', 'contact_person' => 'Pak Heru'],
            ['name' => 'CV Grosir Nusantara', 'phone' => '021-555-0102', 'contact_person' => 'Bu Wati'],
            ['name' => 'UD Makmur Abadi', 'phone' => '021-555-0103', 'contact_person' => 'Pak Dedi'],
        ] as $supplier) {
            $supplierIds[$supplier['name']] = $this->upsertId('suppliers', ['name' => $supplier['name']], [
                'phone' => $supplier['phone'],
                'email' => Str::slug($supplier['name']).'@supplier.test',
                'address' => 'Jakarta',
                'contact_person' => $supplier['contact_person'],
                'payment_terms' => 'Net 14',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $products = [
            ['sku' => 'BR-5KG', 'name' => 'Beras Premium 5kg', 'barcode' => '8991000000010', 'category' => 'Beras', 'brand' => 'Tani Jaya', 'unit' => 'pcs', 'buy' => 58000, 'sell' => 65000, 'stock' => 42, 'min' => 10],
            ['sku' => 'MG-2L', 'name' => 'Minyak Goreng 2L', 'barcode' => '8991000000027', 'category' => 'Minyak', 'brand' => 'Bimoli', 'unit' => 'botol', 'buy' => 33000, 'sell' => 38000, 'stock' => 24, 'min' => 12],
            ['sku' => 'GP-1KG', 'name' => 'Gula Pasir 1kg', 'barcode' => '8991000000034', 'category' => 'Gula', 'brand' => 'Makmur', 'unit' => 'kg', 'buy' => 14500, 'sell' => 17000, 'stock' => 8, 'min' => 15],
            ['sku' => 'MI-GR', 'name' => 'Mie Instan Goreng', 'barcode' => '8991000000041', 'category' => 'Mie Instan', 'brand' => 'Indofood', 'unit' => 'pcs', 'buy' => 2800, 'sell' => 3500, 'stock' => 120, 'min' => 30],
            ['sku' => 'MI-KR', 'name' => 'Mie Instan Kari', 'barcode' => '8991000000058', 'category' => 'Mie Instan', 'brand' => 'Sedaap', 'unit' => 'pcs', 'buy' => 2700, 'sell' => 3400, 'stock' => 95, 'min' => 30],
            ['sku' => 'AQ-600', 'name' => 'Air Mineral 600ml', 'barcode' => '8991000000065', 'category' => 'Minuman', 'brand' => 'Aqua', 'unit' => 'botol', 'buy' => 2500, 'sell' => 3500, 'stock' => 60, 'min' => 24],
            ['sku' => 'SK-800', 'name' => 'Sabun Cuci 800ml', 'barcode' => '8991000000072', 'category' => 'Rumah Tangga', 'brand' => 'Wings', 'unit' => 'pcs', 'buy' => 13500, 'sell' => 16500, 'stock' => 12, 'min' => 12],
            ['sku' => 'TP-250', 'name' => 'Teh Celup 25 pcs', 'barcode' => '8991000000089', 'category' => 'Minuman', 'brand' => 'Makmur', 'unit' => 'pack', 'buy' => 7000, 'sell' => 9500, 'stock' => 36, 'min' => 10],
        ];

        foreach ($products as $product) {
            $productId = $this->upsertId('products', ['sku' => $product['sku']], [
                'name' => $product['name'],
                'slug' => Str::slug($product['name']),
                'category_id' => $categoryIds[$product['category']],
                'brand_id' => $brandIds[$product['brand']],
                'base_unit_id' => $unitIds[$product['unit']],
                'description' => 'Produk sembako Warung Makmur',
                'product_type' => 'unit',
                'purchase_price' => $product['buy'],
                'selling_price' => $product['sell'],
                'wholesale_price' => max($product['sell'] - 1000, $product['buy']),
                'minimum_stock' => $product['min'],
                'track_stock' => true,
                'track_batch' => false,
                'track_expiry' => false,
                'allow_negative_stock' => false,
                'taxable' => false,
                'tax_rate' => 0,
                'is_active' => true,
                'sellable' => true,
                'purchasable' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $productUnitId = $this->upsertId('product_units', ['product_id' => $productId, 'unit_id' => $unitIds[$product['unit']]], [
                'conversion_to_base' => 1,
                'purchase_price' => $product['buy'],
                'selling_price' => $product['sell'],
                'is_default_purchase' => true,
                'is_default_sale' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('product_barcodes')->updateOrInsert(['barcode' => $product['barcode']], [
                'product_id' => $productId,
                'product_unit_id' => $productUnitId,
                'is_primary' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('inventories')->updateOrInsert([
                'store_id' => $storeId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
            ], [
                'quantity' => $product['stock'],
                'reserved_quantity' => 0,
                'available_quantity' => $product['stock'],
                'average_cost' => $product['buy'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ([
            ['code' => 'cash', 'name' => 'Tunai', 'type' => 'manual', 'is_cash' => true],
            ['code' => 'qris', 'name' => 'QRIS', 'type' => 'gateway', 'is_cash' => false],
            ['code' => 'ewallet', 'name' => 'E-Wallet', 'type' => 'manual', 'is_cash' => false],
            ['code' => 'transfer', 'name' => 'Transfer Bank', 'type' => 'manual', 'is_cash' => false],
            ['code' => 'card', 'name' => 'Kartu Debit/Kredit', 'type' => 'manual', 'is_cash' => false],
            ['code' => 'split', 'name' => 'Pembayaran Campuran', 'type' => 'manual', 'is_cash' => false],
        ] as $method) {
            $this->upsertId('payment_methods', ['code' => $method['code']], [
                'name' => $method['name'],
                'type' => $method['type'],
                'is_cash' => $method['is_cash'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $shiftId = $this->upsertId('cashier_shifts', [
            'store_id' => $storeId,
            'terminal_id' => $terminalId,
            'user_id' => $userIds['kasir'],
            'status' => 'open',
        ], [
            'opening_cash' => 300000,
            'expected_cash' => 300000,
            'opened_at' => $now->copy()->subHours(3),
            'opening_notes' => 'Shift pagi',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $adminShiftId = $this->upsertId('cashier_shifts', [
            'store_id' => $storeId,
            'terminal_id' => $terminalId,
            'user_id' => $userIds['admin'],
            'status' => 'open',
        ], [
            'opening_cash' => 500000,
            'expected_cash' => 500000,
            'opened_at' => $now->copy()->subHours(2),
            'opening_notes' => 'Shift admin demo',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $customerId = $this->upsertId('customers', ['phone' => '0812-2222-3333'], [
            'name' => 'Pelanggan Umum',
            'email' => null,
            'address' => 'Jakarta',
            'type' => 'retail',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $saleId = $this->seedSale($storeId, $terminalId, $shiftId, $customerId, $userIds['kasir'], 'INV-JKT-20260720-000086', [
            ['sku' => 'BR-5KG', 'qty' => 1],
            ['sku' => 'MG-2L', 'qty' => 2],
            ['sku' => 'GP-1KG', 'qty' => 2],
        ], 'cash', $now->copy()->subHour());

        $poId = $this->upsertId('purchase_orders', ['order_number' => 'PO-JKT-20260720-000045'], [
            'store_id' => $storeId,
            'supplier_id' => $supplierIds['PT Sumber Sembako Jaya'],
            'status' => 'received',
            'order_date' => $now->toDateString(),
            'expected_date' => $now->copy()->addDays(2)->toDateString(),
            'subtotal' => 2900000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 2900000,
            'notes' => 'Restok beras dan minyak',
            'created_by' => $userIds['admin'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('purchase_order_items')->updateOrInsert([
            'purchase_order_id' => $poId,
            'product_id' => DB::table('products')->where('sku', 'BR-5KG')->value('id'),
        ], [
            'quantity' => 50,
            'received_quantity' => 50,
            'unit_cost' => 58000,
            'subtotal' => 2900000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->upsertId('expenses', ['store_id' => $storeId, 'description' => 'Listrik toko'], [
            'expense_category_id' => $this->upsertId('expense_categories', ['slug' => 'operasional'], [
                'name' => 'Operasional',
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            'amount' => 250000,
            'status' => 'approved',
            'payment_method' => 'cash',
            'expense_date' => $now->toDateString(),
            'created_by' => $userIds['admin'],
            'approved_by' => $userIds['owner'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([
            'store.name' => ['general', 'Warung Makmur'],
            'store.address' => ['general', 'Jl. Merdeka No. 123, Jakarta Selatan'],
            'store.phone' => ['general', '0812-3456-7890'],
            'receipt.footer' => ['receipt', 'Terima kasih atas kunjungan Anda. Barang yang sudah dibeli tidak dapat ditukar.'],
            'pos.tax_rate' => ['pos', '0'],
            'pos.allow_negative_stock' => ['pos', '0'],
            'pos.discount_limit' => ['pos', '10'],
            'backup.retention_days' => ['backup', '30'],
        ] as $key => [$group, $value]) {
            DB::table('settings')->updateOrInsert(['key' => $key], [
                'group' => $group,
                'value' => $value,
                'type' => 'string',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('notifications')->updateOrInsert([
            'store_id' => $storeId,
            'type' => 'low_stock',
            'title' => 'Produk hampir habis',
        ], [
            'user_id' => $userIds['admin'],
            'message' => 'Gula Pasir 1kg berada di bawah stok minimum.',
            'level' => 'warning',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('audit_logs')->updateOrInsert([
            'module' => 'system',
            'action' => 'seeded',
            'record_id' => $storeId,
        ], [
            'user_id' => $userIds['owner'],
            'after_values' => json_encode(['sale_id' => $saleId, 'admin_shift_id' => $adminShiftId]),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'DatabaseSeeder',
            'reason' => 'Initial WarungPOS production sample data',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function upsertId(string $table, array $keys, array $values): int
    {
        DB::table($table)->updateOrInsert($keys, $values);

        return (int) DB::table($table)->where($keys)->value('id');
    }

    private function seedSale(int $storeId, int $terminalId, int $shiftId, int $customerId, int $cashierId, string $invoice, array $items, string $paymentCode, mixed $createdAt): int
    {
        if ($existing = DB::table('sales')->where('invoice_number', $invoice)->value('id')) {
            return (int) $existing;
        }

        $subtotal = 0;
        $cost = 0;
        $saleRows = [];

        foreach ($items as $item) {
            $product = DB::table('products')->where('sku', $item['sku'])->first();
            $lineSubtotal = (float) $product->selling_price * $item['qty'];
            $lineCost = (float) $product->purchase_price * $item['qty'];
            $subtotal += $lineSubtotal;
            $cost += $lineCost;
            $saleRows[] = [$product, $item['qty'], $lineSubtotal, $lineCost];
        }

        $saleId = DB::table('sales')->insertGetId([
            'store_id' => $storeId,
            'terminal_id' => $terminalId,
            'shift_id' => $shiftId,
            'invoice_number' => $invoice,
            'customer_id' => $customerId,
            'cashier_id' => $cashierId,
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => $subtotal,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'rounding_amount' => 0,
            'total_amount' => $subtotal,
            'paid_amount' => $subtotal,
            'change_amount' => 0,
            'cost_amount' => $cost,
            'gross_profit' => $subtotal - $cost,
            'idempotency_key' => 'seed-'.$invoice,
            'completed_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        foreach ($saleRows as [$product, $qty, $lineSubtotal, $lineCost]) {
            DB::table('sale_items')->insert([
                'sale_id' => $saleId,
                'product_id' => $product->id,
                'product_unit_id' => DB::table('product_units')->where('product_id', $product->id)->value('id'),
                'barcode' => DB::table('product_barcodes')->where('product_id', $product->id)->value('barcode'),
                'product_name' => $product->name,
                'quantity' => $qty,
                'base_quantity' => $qty,
                'unit_price' => $product->selling_price,
                'purchase_cost' => $product->purchase_price,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'subtotal' => $lineSubtotal,
                'total' => $lineSubtotal,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        DB::table('payments')->insert([
            'sale_id' => $saleId,
            'payment_method_id' => DB::table('payment_methods')->where('code', $paymentCode)->value('id'),
            'amount' => $subtotal,
            'status' => 'paid',
            'reference_number' => $invoice,
            'provider' => 'manual',
            'paid_at' => $createdAt,
            'created_by' => $cashierId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        DB::table('cashier_shifts')->where('id', $shiftId)->increment('expected_cash', $subtotal, ['updated_at' => now()]);

        return $saleId;
    }
}
