<?php

namespace App\Http\Controllers\WarungPos;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Services\WarungPos\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApiController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $field = filter_var($data['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $user = \App\Models\User::where($field, $data['login'])->first();

        if (! $user || $user->status !== 'active' || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['login' => 'Username/email atau kata sandi salah.']);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return $this->ok('Berhasil masuk.', ['user' => $user->only(['id', 'name', 'username', 'email'])]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->ok('Berhasil keluar.');
    }

    public function dashboard(): JsonResponse
    {
        $today = now()->toDateString();
        $sales = DB::table('sales')->whereDate('created_at', $today)->where('status', 'completed');

        return $this->ok('Dashboard berhasil dimuat.', [
            'sales_today' => (float) (clone $sales)->sum('total_amount'),
            'transactions_today' => (int) (clone $sales)->count(),
            'gross_profit_today' => (float) (clone $sales)->sum('gross_profit'),
            'low_stock_count' => $this->productQuery()->whereColumn('inventories.available_quantity', '<=', 'products.minimum_stock')->count(),
        ]);
    }

    public function products(): JsonResponse
    {
        return $this->ok('Produk berhasil dimuat.', [
            'products' => $this->productQuery()->paginate((int) request('per_page', 25)),
        ]);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:60', 'unique:products,sku'],
            'barcode' => ['nullable', 'string', 'max:80', 'unique:product_barcodes,barcode'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'base_unit_id' => ['required', 'integer', 'exists:units,id'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'opening_stock' => ['nullable', 'numeric', 'min:0'],
        ]);

        $productId = DB::transaction(function () use ($request, $data) {
            $storeId = $this->storeId($request);
            $warehouseId = $this->warehouseId($storeId);
            $stock = round((float) ($data['opening_stock'] ?? 0), 3);
            $productId = DB::table('products')->insertGetId([
                'sku' => $data['sku'],
                'name' => $data['name'],
                'slug' => Str::slug($data['name']).'-'.Str::lower($data['sku']),
                'category_id' => $data['category_id'] ?? null,
                'base_unit_id' => $data['base_unit_id'],
                'product_type' => 'unit',
                'purchase_price' => $data['purchase_price'],
                'selling_price' => $data['selling_price'],
                'wholesale_price' => $data['selling_price'],
                'minimum_stock' => $data['minimum_stock'] ?? 0,
                'track_stock' => true,
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

            return $productId;
        });

        return $this->ok('Produk berhasil dibuat.', ['product_id' => $productId], 201);
    }

    public function updateProduct(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'purchase_price' => ['sometimes', 'numeric', 'min:0'],
            'selling_price' => ['sometimes', 'numeric', 'min:0'],
            'minimum_stock' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'sellable' => ['sometimes', 'boolean'],
            'purchasable' => ['sometimes', 'boolean'],
        ]);

        $updated = DB::table('products')->where('id', $id)->whereNull('deleted_at')->update([
            ...$data,
            'updated_at' => now(),
        ]);

        if (! $updated) {
            return $this->fail('Produk tidak ditemukan.', [], 404);
        }

        return $this->ok('Produk berhasil diperbarui.', ['product' => DB::table('products')->where('id', $id)->first()]);
    }

    public function deleteProduct(int $id): JsonResponse
    {
        $updated = DB::table('products')->where('id', $id)->whereNull('deleted_at')->update([
            'is_active' => false,
            'sellable' => false,
            'purchasable' => false,
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $updated) {
            return $this->fail('Produk tidak ditemukan.', [], 404);
        }

        return $this->ok('Produk berhasil dinonaktifkan.');
    }

    public function barcode(Request $request): JsonResponse
    {
        $data = $request->validate(['barcode' => ['required', 'string', 'max:100']]);
        $product = $this->productQuery()->where('product_barcodes.barcode', $data['barcode'])->first();

        if (! $product) {
            return $this->fail('Barcode tidak ditemukan.', ['barcode' => ['Barcode tidak ditemukan.']], 404);
        }

        return $this->ok('Produk ditemukan.', ['product' => $product]);
    }

    public function storeSale(Request $request, SaleService $sales): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required'],
            'payment_method' => ['required_without:payments', 'nullable', 'string'],
            'payments' => ['nullable'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $sale = $sales->checkout($request->user(), $data);

        return $this->ok('Transaksi berhasil.', ['sale' => $sale], 201);
    }

    public function showSale(int $id): JsonResponse
    {
        $sale = DB::table('sales')->where('id', $id)->first();

        if (! $sale) {
            return $this->fail('Transaksi tidak ditemukan.', [], 404);
        }

        return $this->ok('Transaksi berhasil dimuat.', [
            'sale' => $sale,
            'items' => DB::table('sale_items')->where('sale_id', $id)->get(),
            'payments' => DB::table('payments')->where('sale_id', $id)->get(),
        ]);
    }

    public function cancelSale(Request $request, SaleService $sales, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->ok('Transaksi berhasil dibatalkan.', [
            'sale' => $sales->cancel($request->user(), $id, $data['reason']),
        ]);
    }

    public function refundSale(Request $request, SaleService $sales, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->ok('Retur dan refund berhasil dicatat.', [
            'return' => $sales->refund($request->user(), $id, $data['reason']),
        ]);
    }

    public function storePayment(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $data = $request->validate([
            'sale_id' => ['required', 'integer', 'exists:sales,id'],
            'payment_method' => ['required', 'string', 'exists:payment_methods,code'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'provider_transaction_id' => ['nullable', 'string', 'max:100'],
        ]);

        $payment = DB::transaction(function () use ($request, $gateway, $data) {
            $sale = DB::table('sales')->where('id', $data['sale_id'])->lockForUpdate()->first();
            $method = DB::table('payment_methods')->where('code', $data['payment_method'])->where('is_active', true)->first();

            if (! $method) {
                throw ValidationException::withMessages(['payment_method' => 'Metode pembayaran tidak tersedia.']);
            }

            $paidBefore = (float) DB::table('payments')->where('sale_id', $sale->id)->where('status', 'paid')->sum('amount');
            $remaining = round((float) $sale->total_amount - $paidBefore, 2);
            if ($remaining <= 0) {
                throw ValidationException::withMessages(['sale_id' => 'Transaksi sudah lunas.']);
            }

            if ((float) $data['amount'] > $remaining) {
                throw ValidationException::withMessages(['amount' => 'Nominal pembayaran melebihi sisa tagihan.']);
            }

            $gatewayPayment = $gateway->createPayment([
                'method' => $method->code,
                'amount' => $data['amount'],
                'status' => 'paid',
                'reference_number' => $data['reference_number'] ?? null,
                'provider_transaction_id' => $data['provider_transaction_id'] ?? null,
                'confirmed_by' => $request->user()->id,
            ]);

            $paymentId = DB::table('payments')->insertGetId([
                'sale_id' => $sale->id,
                'payment_method_id' => $method->id,
                'amount' => $data['amount'],
                'status' => $gatewayPayment['status'] ?? 'paid',
                'reference_number' => $gatewayPayment['reference_number'] ?? $data['reference_number'] ?? null,
                'provider' => $method->type,
                'provider_transaction_id' => $gatewayPayment['provider_transaction_id'] ?? $data['provider_transaction_id'] ?? null,
                'paid_at' => now(),
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('payment_transactions')->insert([
                'payment_id' => $paymentId,
                'type' => 'capture',
                'request_payload' => json_encode(['method' => $method->code, 'amount' => $data['amount']]),
                'response_payload' => json_encode($gatewayPayment),
                'signature_valid' => true,
                'status' => $gatewayPayment['status'] ?? 'paid',
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $paid = DB::table('payments')->where('sale_id', $sale->id)->where('status', 'paid')->sum('amount');
            if ((float) $paid >= (float) $sale->total_amount) {
                DB::table('sales')->where('id', $sale->id)->update(['payment_status' => 'paid', 'updated_at' => now()]);
            }

            return DB::table('payments')->where('id', $paymentId)->first();
        });

        return $this->ok('Pembayaran berhasil dicatat.', ['payment' => $payment], 201);
    }

    public function verifyPayment(PaymentGateway $gateway, int $id): JsonResponse
    {
        $payment = DB::table('payments')->where('id', $id)->first();
        if (! $payment) {
            return $this->fail('Pembayaran tidak ditemukan.', [], 404);
        }

        $status = $gateway->getPaymentStatus((string) ($payment->provider_transaction_id ?: $payment->reference_number ?: $payment->id));
        if ($payment->status !== 'paid') {
            DB::table('payments')->where('id', $payment->id)->update([
                'status' => $status['status'] ?? $payment->status,
                'paid_at' => ($status['status'] ?? null) === 'paid' ? now() : $payment->paid_at,
                'updated_at' => now(),
            ]);

            if (($status['status'] ?? null) === 'paid') {
                $sale = DB::table('sales')->where('id', $payment->sale_id)->first();
                $paid = DB::table('payments')->where('sale_id', $payment->sale_id)->where('status', 'paid')->sum('amount');
                if ($sale && (float) $paid >= (float) $sale->total_amount) {
                    DB::table('sales')->where('id', $sale->id)->update(['payment_status' => 'paid', 'updated_at' => now()]);
                }
            }
        }

        return $this->ok('Status pembayaran berhasil diverifikasi.', ['status' => $status]);
    }

    public function inventory(): JsonResponse
    {
        return $this->ok('Inventory berhasil dimuat.', [
            'products' => $this->productQuery()->get(),
            'movements' => DB::table('stock_movements')->orderByDesc('created_at')->limit(50)->get(),
        ]);
    }

    public function adjustInventory(Request $request, SaleService $sales): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'actual_quantity' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $sales->adjustStock($request->user(), (int) $data['product_id'], (float) $data['actual_quantity'], $data['reason']);

        return $this->ok('Stok berhasil disesuaikan.');
    }

    public function stockOpname(Request $request, SaleService $sales): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'actual_quantity' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $opnameId = $sales->stockOpname($request->user(), (int) $data['product_id'], (float) $data['actual_quantity'], $data['reason']);

        return $this->ok('Stok opname berhasil disetujui.', ['stock_opname_id' => $opnameId], 201);
    }

    public function purchases(): JsonResponse
    {
        return $this->ok('Pembelian berhasil dimuat.', [
            'purchases' => DB::table('purchase_orders')->orderByDesc('created_at')->paginate((int) request('per_page', 25)),
        ]);
    }

    public function storePurchase(Request $request, SaleService $sales): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $poId = $sales->receivePurchase($request->user(), $data);

        return $this->ok('Pembelian berhasil dibuat dan diterima.', ['purchase_order_id' => $poId], 201);
    }

    public function receivePurchase(Request $request, int $id): JsonResponse
    {
        $receiptId = DB::transaction(function () use ($request, $id) {
            $order = DB::table('purchase_orders')->where('id', $id)->lockForUpdate()->first();
            if (! $order) {
                throw ValidationException::withMessages(['purchase_order_id' => 'Purchase order tidak ditemukan.']);
            }

            if ($order->status === 'received') {
                return DB::table('purchase_receipts')->where('purchase_order_id', $order->id)->value('id');
            }

            $warehouseId = $this->warehouseId((int) $order->store_id);
            $receiptId = DB::table('purchase_receipts')->insertGetId([
                'purchase_order_id' => $order->id,
                'receipt_number' => 'RCV-'.now()->format('Ymd').'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
                'received_date' => now()->toDateString(),
                'received_by' => $request->user()->id,
                'notes' => $request->input('notes'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (DB::table('purchase_order_items')->where('purchase_order_id', $order->id)->get() as $item) {
                $quantity = max(0, (float) $item->quantity - (float) $item->received_quantity);
                if ($quantity <= 0) {
                    continue;
                }

                $inventory = DB::table('inventories')
                    ->where('store_id', $order->store_id)
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $item->product_id)
                    ->lockForUpdate()
                    ->first();

                $before = (float) ($inventory->quantity ?? 0);
                $after = round($before + $quantity, 3);
                if ($inventory) {
                    DB::table('inventories')->where('id', $inventory->id)->update([
                        'quantity' => $after,
                        'available_quantity' => $after - (float) $inventory->reserved_quantity,
                        'average_cost' => $item->unit_cost,
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('inventories')->insert([
                        'store_id' => $order->store_id,
                        'warehouse_id' => $warehouseId,
                        'product_id' => $item->product_id,
                        'quantity' => $after,
                        'reserved_quantity' => 0,
                        'available_quantity' => $after,
                        'average_cost' => $item->unit_cost,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('purchase_receipt_items')->insert([
                    'purchase_receipt_id' => $receiptId,
                    'product_id' => $item->product_id,
                    'quantity' => $quantity,
                    'unit_cost' => $item->unit_cost,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('purchase_order_items')->where('id', $item->id)->update([
                    'received_quantity' => $item->quantity,
                    'updated_at' => now(),
                ]);

                DB::table('stock_movements')->insert([
                    'store_id' => $order->store_id,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $item->product_id,
                    'movement_type' => 'purchase',
                    'reference_type' => 'purchase_orders',
                    'reference_id' => $order->id,
                    'quantity_in' => $quantity,
                    'quantity_out' => 0,
                    'stock_before' => $before,
                    'stock_after' => $after,
                    'unit_cost' => $item->unit_cost,
                    'reason' => 'Penerimaan pembelian',
                    'created_by' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('purchase_orders')->where('id', $order->id)->update(['status' => 'received', 'updated_at' => now()]);

            return $receiptId;
        });

        return $this->ok('Purchase order berhasil diterima.', ['receipt_id' => $receiptId]);
    }

    public function openShift(Request $request, SaleService $sales): JsonResponse
    {
        $data = $request->validate([
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'opening_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $shiftId = $sales->openShift($request->user(), (float) $data['opening_cash'], $data['opening_notes'] ?? '');

        return $this->ok('Shift kasir dibuka.', ['shift_id' => $shiftId], 201);
    }

    public function closeShift(Request $request, SaleService $sales, int $id): JsonResponse
    {
        $data = $request->validate([
            'actual_cash' => ['required', 'numeric', 'min:0'],
            'closing_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $sales->closeShift($request->user(), (float) $data['actual_cash'], $data['closing_notes'] ?? '');

        return $this->ok('Shift kasir ditutup.', ['shift_id' => $id]);
    }

    public function salesReport(): JsonResponse
    {
        $from = request('from', now()->subDays(6)->toDateString());
        $to = request('to', now()->toDateString());

        return $this->ok('Laporan penjualan berhasil dimuat.', [
            'from' => $from,
            'to' => $to,
            'total_sales' => (float) DB::table('sales')->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])->sum('total_amount'),
            'transactions' => (int) DB::table('sales')->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])->count(),
        ]);
    }

    public function inventoryReport(): JsonResponse
    {
        return $this->ok('Laporan stok berhasil dimuat.', [
            'stock_value' => (float) DB::table('inventories')->sum(DB::raw('quantity * average_cost')),
            'low_stock' => $this->productQuery()->whereColumn('inventories.available_quantity', '<=', 'products.minimum_stock')->get(),
        ]);
    }

    public function profitReport(): JsonResponse
    {
        $from = request('from', now()->subDays(6)->toDateString());
        $to = request('to', now()->toDateString());

        return $this->ok('Laporan laba berhasil dimuat.', [
            'gross_profit' => (float) DB::table('sales')->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])->sum('gross_profit'),
        ]);
    }

    private function ok(string $message, array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    private function fail(string $message, array $errors = [], int $status = 422): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'errors' => $errors], $status);
    }

    private function productQuery()
    {
        return DB::table('products')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('units', 'units.id', '=', 'products.base_unit_id')
            ->leftJoin('inventories', 'inventories.product_id', '=', 'products.id')
            ->leftJoin('product_barcodes', function ($join) {
                $join->on('product_barcodes.product_id', '=', 'products.id')->where('product_barcodes.is_primary', true);
            })
            ->select(
                'products.id',
                'products.sku',
                'products.name',
                'products.selling_price',
                'products.minimum_stock',
                'products.is_active',
                'categories.name as category_name',
                'units.code as unit_code',
                'product_barcodes.barcode',
                DB::raw('COALESCE(inventories.available_quantity, 0) as stock'),
                DB::raw('COALESCE(inventories.average_cost, products.purchase_price) as average_cost')
            )
            ->orderBy('products.name');
    }

    private function storeId(Request $request): int
    {
        return (int) (DB::table('user_stores')->where('user_id', $request->user()->id)->value('store_id') ?: DB::table('stores')->value('id'));
    }

    private function warehouseId(int $storeId): int
    {
        return (int) DB::table('warehouses')->where('store_id', $storeId)->value('id');
    }
}
