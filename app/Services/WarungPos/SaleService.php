<?php

namespace App\Services\WarungPos;

use App\Contracts\PaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(private readonly PaymentGateway $paymentGateway)
    {
    }

    public function checkout(User $user, array $payload): object
    {
        return DB::transaction(function () use ($user, $payload) {
            $storeId = $this->storeId($user);
            $warehouseId = $this->warehouseId($storeId);
            $terminalId = $this->terminalId($storeId);
            $shift = $this->currentOpenShift($user, $storeId);
            $idempotencyKey = $payload['idempotency_key'] ?? null;

            if ($idempotencyKey) {
                $existing = DB::table('sales')->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing;
                }
            }

            $items = $this->normalizeItems($payload['items'] ?? []);
            if ($items === []) {
                throw ValidationException::withMessages(['items' => 'Keranjang masih kosong.']);
            }

            $lines = [];
            $subtotal = 0.0;
            $costAmount = 0.0;

            foreach ($items as $item) {
                $product = DB::table('products')
                    ->where('id', $item['product_id'])
                    ->where('is_active', true)
                    ->where('sellable', true)
                    ->lockForUpdate()
                    ->first();

                if (! $product) {
                    throw ValidationException::withMessages(['items' => 'Produk tidak aktif atau tidak dapat dijual.']);
                }

                $inventory = DB::table('inventories')
                    ->where('store_id', $storeId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();

                if (! $inventory) {
                    throw ValidationException::withMessages(['items' => "{$product->name} belum memiliki stok."]);
                }

                if (! $product->allow_negative_stock && (float) $inventory->available_quantity < $item['quantity']) {
                    throw ValidationException::withMessages(['items' => "Stok {$product->name} tidak mencukupi."]);
                }

                $lineSubtotal = round((float) $product->selling_price * $item['quantity'], 2);
                $lineCost = round((float) $product->purchase_price * $item['quantity'], 2);
                $subtotal += $lineSubtotal;
                $costAmount += $lineCost;

                $lines[] = compact('product', 'inventory', 'item', 'lineSubtotal', 'lineCost');
            }

            $discount = round((float) ($payload['discount_amount'] ?? 0), 2);
            if ($discount < 0 || $discount > $subtotal) {
                throw ValidationException::withMessages(['discount_amount' => 'Diskon tidak boleh melebihi subtotal.']);
            }

            $tax = round((float) ($payload['tax_amount'] ?? 0), 2);
            $rounding = round((float) ($payload['rounding_amount'] ?? 0), 2);
            $total = round($subtotal - $discount + $tax + $rounding, 2);
            $paymentRows = $this->normalizePayments($payload, $total);
            $paidAmount = round(array_sum(array_column($paymentRows, 'received_amount')), 2);

            $saleId = DB::table('sales')->insertGetId([
                'store_id' => $storeId,
                'terminal_id' => $terminalId,
                'shift_id' => $shift->id,
                'invoice_number' => $this->nextInvoiceNumber($storeId),
                'customer_id' => $payload['customer_id'] ?? null,
                'cashier_id' => $user->id,
                'status' => 'completed',
                'payment_status' => 'paid',
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'rounding_amount' => $rounding,
                'total_amount' => $total,
                'paid_amount' => $paidAmount,
                'change_amount' => max(0, $paidAmount - $total),
                'cost_amount' => $costAmount,
                'gross_profit' => $total - $costAmount,
                'idempotency_key' => $idempotencyKey ?: (string) Str::uuid(),
                'notes' => $payload['notes'] ?? null,
                'completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($lines as $line) {
                $product = $line['product'];
                $inventory = $line['inventory'];
                $item = $line['item'];
                $after = round((float) $inventory->quantity - $item['quantity'], 3);

                DB::table('sale_items')->insert([
                    'sale_id' => $saleId,
                    'product_id' => $product->id,
                    'product_unit_id' => DB::table('product_units')->where('product_id', $product->id)->where('is_default_sale', true)->value('id'),
                    'barcode' => DB::table('product_barcodes')->where('product_id', $product->id)->where('is_primary', true)->value('barcode'),
                    'product_name' => $product->name,
                    'quantity' => $item['quantity'],
                    'base_quantity' => $item['quantity'],
                    'unit_price' => $product->selling_price,
                    'purchase_cost' => $product->purchase_price,
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                    'subtotal' => $line['lineSubtotal'],
                    'total' => $line['lineSubtotal'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('inventories')->where('id', $inventory->id)->update([
                    'quantity' => $after,
                    'available_quantity' => $after - (float) $inventory->reserved_quantity,
                    'updated_at' => now(),
                ]);

                DB::table('stock_movements')->insert([
                    'store_id' => $storeId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $product->id,
                    'movement_type' => 'sale',
                    'reference_type' => 'sales',
                    'reference_id' => $saleId,
                    'quantity_in' => 0,
                    'quantity_out' => $item['quantity'],
                    'stock_before' => $inventory->quantity,
                    'stock_after' => $after,
                    'unit_cost' => $product->purchase_price,
                    'reason' => 'Penjualan kasir',
                    'created_by' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($after <= (float) $product->minimum_stock) {
                    DB::table('notifications')->insert([
                        'store_id' => $storeId,
                        'user_id' => $user->id,
                        'type' => 'low_stock',
                        'title' => 'Produk hampir habis',
                        'message' => "{$product->name} tersisa {$after}.",
                        'level' => 'warning',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            foreach ($paymentRows as $row) {
                $paymentMethod = $row['method'];
                $gatewayPayment = $this->paymentGateway->createPayment([
                    'method' => $paymentMethod->code,
                    'amount' => $row['amount'],
                    'status' => 'paid',
                    'reference_number' => $row['reference_number'],
                    'provider_transaction_id' => $row['provider_transaction_id'],
                    'confirmed_by' => $user->id,
                ]);

                $paymentStatus = $gatewayPayment['status'] ?? 'paid';
                $paymentId = DB::table('payments')->insertGetId([
                    'sale_id' => $saleId,
                    'payment_method_id' => $paymentMethod->id,
                    'amount' => $row['amount'],
                    'status' => $paymentStatus,
                    'reference_number' => $gatewayPayment['reference_number'] ?? $row['reference_number'],
                    'provider' => $paymentMethod->type,
                    'provider_transaction_id' => $gatewayPayment['provider_transaction_id'] ?? $row['provider_transaction_id'],
                    'paid_at' => $paymentStatus === 'paid' ? now() : null,
                    'created_by' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('payment_transactions')->insert([
                    'payment_id' => $paymentId,
                    'type' => 'capture',
                    'request_payload' => json_encode(['method' => $paymentMethod->code, 'amount' => $row['amount']]),
                    'response_payload' => json_encode($gatewayPayment),
                    'signature_valid' => true,
                    'idempotency_key' => $idempotencyKey,
                    'status' => $paymentStatus,
                    'processed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($paymentMethod->is_cash) {
                    DB::table('cashier_shifts')->where('id', $shift->id)->increment('expected_cash', $row['amount'], ['updated_at' => now()]);
                }
            }

            $this->audit($user, 'created', 'sales', $saleId, null, ['total_amount' => $total]);

            return DB::table('sales')->where('id', $saleId)->first();
        });
    }

    public function refund(User $user, int $saleId, string $reason): object
    {
        return DB::transaction(function () use ($user, $saleId, $reason) {
            $sale = DB::table('sales')->where('id', $saleId)->lockForUpdate()->first();

            if (! $sale || $sale->status !== 'completed') {
                throw ValidationException::withMessages(['sale' => 'Transaksi tidak dapat diretur.']);
            }

            $storeId = (int) $sale->store_id;
            $warehouseId = $this->warehouseId($storeId);
            $payment = DB::table('payments')->where('sale_id', $sale->id)->lockForUpdate()->first();
            $returnId = DB::table('sale_returns')->insertGetId([
                'sale_id' => $sale->id,
                'return_number' => $this->nextReturnNumber($storeId),
                'status' => 'completed',
                'total_amount' => $sale->total_amount,
                'reason' => $reason,
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (DB::table('sale_items')->where('sale_id', $sale->id)->get() as $item) {
                $inventory = DB::table('inventories')
                    ->where('store_id', $storeId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $item->product_id)
                    ->lockForUpdate()
                    ->first();

                $after = round((float) $inventory->quantity + (float) $item->base_quantity, 3);

                DB::table('sale_return_items')->insert([
                    'sale_return_id' => $returnId,
                    'sale_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'amount' => $item->total,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('inventories')->where('id', $inventory->id)->update([
                    'quantity' => $after,
                    'available_quantity' => $after - (float) $inventory->reserved_quantity,
                    'updated_at' => now(),
                ]);

                DB::table('stock_movements')->insert([
                    'store_id' => $storeId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $item->product_id,
                    'movement_type' => 'sale_return',
                    'reference_type' => 'sale_returns',
                    'reference_id' => $returnId,
                    'quantity_in' => $item->base_quantity,
                    'quantity_out' => 0,
                    'stock_before' => $inventory->quantity,
                    'stock_after' => $after,
                    'unit_cost' => $item->purchase_cost,
                    'reason' => $reason,
                    'created_by' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $gatewayRefund = $payment
                ? $this->paymentGateway->refundPayment((string) ($payment->provider_transaction_id ?: $payment->reference_number ?: $sale->invoice_number), (float) $sale->total_amount, $reason)
                : [];

            DB::table('refunds')->insert([
                'sale_return_id' => $returnId,
                'payment_id' => $payment?->id,
                'amount' => $sale->total_amount,
                'method' => 'cash',
                'status' => 'completed',
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($payment) {
                DB::table('payments')->where('id', $payment->id)->update([
                    'status' => 'refunded',
                    'updated_at' => now(),
                ]);

                DB::table('payment_transactions')->insert([
                    'payment_id' => $payment->id,
                    'type' => 'refund',
                    'request_payload' => json_encode(['amount' => $sale->total_amount, 'reason' => $reason]),
                    'response_payload' => json_encode($gatewayRefund),
                    'signature_valid' => true,
                    'idempotency_key' => null,
                    'status' => 'refunded',
                    'processed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('sales')->where('id', $sale->id)->update([
                'status' => 'refunded',
                'payment_status' => 'refunded',
                'updated_at' => now(),
            ]);

            $this->reverseCashShift($sale);
            $this->audit($user, 'refunded', 'sales', $sale->id, ['status' => $sale->status], ['status' => 'refunded']);

            return DB::table('sale_returns')->where('id', $returnId)->first();
        });
    }

    public function cancel(User $user, int $saleId, string $reason): object
    {
        return DB::transaction(function () use ($user, $saleId, $reason) {
            $sale = DB::table('sales')->where('id', $saleId)->lockForUpdate()->first();

            if (! $sale || $sale->status !== 'completed') {
                throw ValidationException::withMessages(['sale' => 'Transaksi tidak dapat dibatalkan.']);
            }

            $storeId = (int) $sale->store_id;
            $warehouseId = $this->warehouseId($storeId);
            $payment = DB::table('payments')->where('sale_id', $sale->id)->lockForUpdate()->first();
            $returnId = DB::table('sale_returns')->insertGetId([
                'sale_id' => $sale->id,
                'return_number' => $this->nextReturnNumber($storeId),
                'status' => 'completed',
                'total_amount' => $sale->total_amount,
                'reason' => 'Pembatalan transaksi: '.$reason,
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (DB::table('sale_items')->where('sale_id', $sale->id)->get() as $item) {
                $inventory = DB::table('inventories')
                    ->where('store_id', $storeId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $item->product_id)
                    ->lockForUpdate()
                    ->first();

                $after = round((float) $inventory->quantity + (float) $item->base_quantity, 3);

                DB::table('sale_return_items')->insert([
                    'sale_return_id' => $returnId,
                    'sale_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'amount' => $item->total,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('inventories')->where('id', $inventory->id)->update([
                    'quantity' => $after,
                    'available_quantity' => $after - (float) $inventory->reserved_quantity,
                    'updated_at' => now(),
                ]);

                DB::table('stock_movements')->insert([
                    'store_id' => $storeId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $item->product_id,
                    'movement_type' => 'sale_return',
                    'reference_type' => 'sales',
                    'reference_id' => $sale->id,
                    'quantity_in' => $item->base_quantity,
                    'quantity_out' => 0,
                    'stock_before' => $inventory->quantity,
                    'stock_after' => $after,
                    'unit_cost' => $item->purchase_cost,
                    'reason' => 'Pembatalan transaksi: '.$reason,
                    'created_by' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($payment) {
                $gatewayCancel = $this->paymentGateway->cancelPayment((string) ($payment->provider_transaction_id ?: $payment->reference_number ?: $sale->invoice_number), $reason);

                DB::table('payments')->where('id', $payment->id)->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);

                DB::table('payment_transactions')->insert([
                    'payment_id' => $payment->id,
                    'type' => 'cancel',
                    'request_payload' => json_encode(['reason' => $reason]),
                    'response_payload' => json_encode($gatewayCancel),
                    'signature_valid' => true,
                    'idempotency_key' => null,
                    'status' => 'cancelled',
                    'processed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('sales')->where('id', $sale->id)->update([
                'status' => 'cancelled',
                'payment_status' => 'cancelled',
                'cancelled_at' => now(),
                'updated_at' => now(),
            ]);

            $this->reverseCashShift($sale);
            $this->audit($user, 'cancelled', 'sales', $sale->id, ['status' => $sale->status], ['status' => 'cancelled']);

            return DB::table('sales')->where('id', $sale->id)->first();
        });
    }

    public function receivePurchase(User $user, array $payload): int
    {
        return DB::transaction(function () use ($user, $payload) {
            $storeId = $this->storeId($user);
            $warehouseId = $this->warehouseId($storeId);
            $supplierId = (int) ($payload['supplier_id'] ?? 0);
            $items = $this->normalizeItems($payload['items'] ?? [], 'unit_cost');

            if (! DB::table('suppliers')->where('id', $supplierId)->exists()) {
                throw ValidationException::withMessages(['supplier_id' => 'Supplier tidak ditemukan.']);
            }

            if ($items === []) {
                throw ValidationException::withMessages(['items' => 'Item pembelian masih kosong.']);
            }

            $total = 0.0;
            foreach ($items as $item) {
                $total += round($item['quantity'] * (float) $item['unit_cost'], 2);
            }

            $poId = DB::table('purchase_orders')->insertGetId([
                'store_id' => $storeId,
                'supplier_id' => $supplierId,
                'order_number' => $this->nextPurchaseNumber($storeId),
                'status' => 'received',
                'order_date' => now()->toDateString(),
                'expected_date' => now()->toDateString(),
                'subtotal' => $total,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => $total,
                'notes' => $payload['notes'] ?? null,
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $receiptId = DB::table('purchase_receipts')->insertGetId([
                'purchase_order_id' => $poId,
                'receipt_number' => 'RCV-'.now()->format('Ymd').'-'.str_pad((string) $poId, 6, '0', STR_PAD_LEFT),
                'received_date' => now()->toDateString(),
                'received_by' => $user->id,
                'notes' => $payload['notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($items as $item) {
                $product = DB::table('products')->where('id', $item['product_id'])->lockForUpdate()->first();
                if (! $product) {
                    throw ValidationException::withMessages(['items' => 'Produk pembelian tidak ditemukan.']);
                }

                $inventory = DB::table('inventories')
                    ->where('store_id', $storeId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();

                if (! $inventory) {
                    DB::table('inventories')->insert([
                        'store_id' => $storeId,
                        'warehouse_id' => $warehouseId,
                        'product_id' => $product->id,
                        'quantity' => 0,
                        'reserved_quantity' => 0,
                        'available_quantity' => 0,
                        'average_cost' => $item['unit_cost'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $inventory = DB::table('inventories')
                        ->where('store_id', $storeId)
                        ->where('warehouse_id', $warehouseId)
                        ->where('product_id', $product->id)
                        ->lockForUpdate()
                        ->first();
                }

                $after = round((float) $inventory->quantity + $item['quantity'], 3);
                $averageCost = $after > 0
                    ? round((((float) $inventory->quantity * (float) $inventory->average_cost) + ($item['quantity'] * (float) $item['unit_cost'])) / $after, 2)
                    : (float) $item['unit_cost'];

                DB::table('purchase_order_items')->insert([
                    'purchase_order_id' => $poId,
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'received_quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'subtotal' => round($item['quantity'] * (float) $item['unit_cost'], 2),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('purchase_receipt_items')->insert([
                    'purchase_receipt_id' => $receiptId,
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('inventories')->where('id', $inventory->id)->update([
                    'quantity' => $after,
                    'available_quantity' => $after - (float) $inventory->reserved_quantity,
                    'average_cost' => $averageCost,
                    'updated_at' => now(),
                ]);

                DB::table('products')->where('id', $product->id)->update([
                    'purchase_price' => $item['unit_cost'],
                    'updated_at' => now(),
                ]);

                DB::table('stock_movements')->insert([
                    'store_id' => $storeId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $product->id,
                    'movement_type' => 'purchase',
                    'reference_type' => 'purchase_orders',
                    'reference_id' => $poId,
                    'quantity_in' => $item['quantity'],
                    'quantity_out' => 0,
                    'stock_before' => $inventory->quantity,
                    'stock_after' => $after,
                    'unit_cost' => $item['unit_cost'],
                    'reason' => 'Penerimaan pembelian',
                    'created_by' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->audit($user, 'received', 'purchase_orders', $poId, null, ['total_amount' => $total]);

            return $poId;
        });
    }

    public function adjustStock(User $user, int $productId, float $actualQuantity, string $reason): void
    {
        DB::transaction(function () use ($user, $productId, $actualQuantity, $reason) {
            $storeId = $this->storeId($user);
            $warehouseId = $this->warehouseId($storeId);
            $inventory = DB::table('inventories')
                ->where('store_id', $storeId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $inventory) {
                throw ValidationException::withMessages(['product_id' => 'Inventory produk tidak ditemukan.']);
            }

            $before = (float) $inventory->quantity;
            $type = $actualQuantity >= $before ? 'adjustment_in' : 'adjustment_out';

            DB::table('inventories')->where('id', $inventory->id)->update([
                'quantity' => $actualQuantity,
                'available_quantity' => $actualQuantity - (float) $inventory->reserved_quantity,
                'updated_at' => now(),
            ]);

            DB::table('stock_movements')->insert([
                'store_id' => $storeId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'movement_type' => $type,
                'reference_type' => 'stock_adjustments',
                'reference_id' => null,
                'quantity_in' => max(0, $actualQuantity - $before),
                'quantity_out' => max(0, $before - $actualQuantity),
                'stock_before' => $before,
                'stock_after' => $actualQuantity,
                'unit_cost' => $inventory->average_cost,
                'reason' => $reason,
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit($user, 'adjusted', 'stock', $productId, ['quantity' => $before], ['quantity' => $actualQuantity]);
        });
    }

    public function stockOpname(User $user, int $productId, float $actualQuantity, string $reason): int
    {
        return DB::transaction(function () use ($user, $productId, $actualQuantity, $reason) {
            $storeId = $this->storeId($user);
            $warehouseId = $this->warehouseId($storeId);
            $inventory = DB::table('inventories')
                ->where('store_id', $storeId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $inventory) {
                throw ValidationException::withMessages(['product_id' => 'Inventory produk tidak ditemukan.']);
            }

            $before = (float) $inventory->quantity;
            $difference = round($actualQuantity - $before, 3);
            $opnameId = DB::table('stock_opnames')->insertGetId([
                'store_id' => $storeId,
                'warehouse_id' => $warehouseId,
                'opname_number' => 'OPN-JKT-'.now()->format('Ymd').'-'.str_pad((string) (DB::table('stock_opnames')->where('store_id', $storeId)->count() + 1), 6, '0', STR_PAD_LEFT),
                'status' => 'approved',
                'created_by' => $user->id,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('stock_opname_items')->insert([
                'stock_opname_id' => $opnameId,
                'product_id' => $productId,
                'system_quantity' => $before,
                'actual_quantity' => $actualQuantity,
                'difference_quantity' => $difference,
                'notes' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('inventories')->where('id', $inventory->id)->update([
                'quantity' => $actualQuantity,
                'available_quantity' => $actualQuantity - (float) $inventory->reserved_quantity,
                'updated_at' => now(),
            ]);

            DB::table('stock_movements')->insert([
                'store_id' => $storeId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'movement_type' => 'stock_opname',
                'reference_type' => 'stock_opnames',
                'reference_id' => $opnameId,
                'quantity_in' => max(0, $difference),
                'quantity_out' => max(0, -$difference),
                'stock_before' => $before,
                'stock_after' => $actualQuantity,
                'unit_cost' => $inventory->average_cost,
                'reason' => $reason,
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit($user, 'approved', 'stock_opnames', $opnameId, ['quantity' => $before], ['quantity' => $actualQuantity]);

            return $opnameId;
        });
    }

    public function openShift(User $user, float $openingCash, string $notes = ''): int
    {
        return DB::transaction(function () use ($user, $openingCash, $notes) {
            $storeId = $this->storeId($user);

            if ($open = DB::table('cashier_shifts')->where('user_id', $user->id)->where('status', 'open')->first()) {
                return (int) $open->id;
            }

            $shiftId = DB::table('cashier_shifts')->insertGetId([
                'store_id' => $storeId,
                'terminal_id' => $this->terminalId($storeId),
                'user_id' => $user->id,
                'opening_cash' => $openingCash,
                'expected_cash' => $openingCash,
                'status' => 'open',
                'opened_at' => now(),
                'opening_notes' => $notes,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit($user, 'opened', 'cashier_shifts', $shiftId, null, ['opening_cash' => $openingCash]);

            return $shiftId;
        });
    }

    public function closeShift(User $user, float $actualCash, string $notes = ''): void
    {
        DB::transaction(function () use ($user, $actualCash, $notes) {
            $shift = DB::table('cashier_shifts')->where('user_id', $user->id)->where('status', 'open')->lockForUpdate()->first();

            if (! $shift) {
                throw ValidationException::withMessages(['shift' => 'Tidak ada shift aktif.']);
            }

            DB::table('cashier_shifts')->where('id', $shift->id)->update([
                'actual_cash' => $actualCash,
                'cash_difference' => round($actualCash - (float) $shift->expected_cash, 2),
                'status' => 'closed',
                'closed_at' => now(),
                'closing_notes' => $notes,
                'updated_at' => now(),
            ]);

            $this->audit($user, 'closed', 'cashier_shifts', $shift->id, ['status' => 'open'], ['actual_cash' => $actualCash]);
        });
    }

    private function normalizeItems(array|string $items, string $extraNumeric = ''): array
    {
        if (is_string($items)) {
            $items = json_decode($items, true) ?: [];
        }

        $normalized = [];
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = round((float) ($item['quantity'] ?? 0), 3);

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $row = ['product_id' => $productId, 'quantity' => $quantity];
            if ($extraNumeric !== '') {
                $row[$extraNumeric] = round((float) ($item[$extraNumeric] ?? 0), 2);
            }
            $normalized[] = $row;
        }

        return $normalized;
    }

    private function normalizePayments(array $payload, float $total): array
    {
        $payments = $payload['payments'] ?? null;
        if (is_string($payments)) {
            $payments = json_decode($payments, true) ?: null;
        }

        if (! is_array($payments) || $payments === []) {
            $method = $this->paymentMethod($payload['payment_method'] ?? 'cash');
            $received = $method->is_cash ? round((float) ($payload['paid_amount'] ?? 0), 2) : $total;

            if ($method->is_cash && $received < $total) {
                throw ValidationException::withMessages(['paid_amount' => 'Uang diterima masih kurang.']);
            }

            return [[
                'method' => $method,
                'amount' => $total,
                'received_amount' => max($received, $total),
                'reference_number' => $payload['reference_number'] ?? null,
                'provider_transaction_id' => $payload['provider_transaction_id'] ?? null,
            ]];
        }

        $rows = [];
        $allocated = 0.0;
        $receivedTotal = 0.0;
        foreach ($payments as $index => $payment) {
            $amount = round((float) ($payment['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages(["payments.{$index}.amount" => 'Nominal pembayaran harus lebih besar dari nol.']);
            }

            $method = $this->paymentMethod($payment['method'] ?? $payment['payment_method'] ?? '');
            $received = $method->is_cash ? round((float) ($payment['received_amount'] ?? $amount), 2) : $amount;
            if ($received < $amount) {
                throw ValidationException::withMessages(["payments.{$index}.received_amount" => 'Nominal diterima tidak boleh kurang dari komponen pembayaran.']);
            }

            $allocated += $amount;
            $receivedTotal += $received;
            $rows[] = [
                'method' => $method,
                'amount' => $amount,
                'received_amount' => $received,
                'reference_number' => $payment['reference_number'] ?? null,
                'provider_transaction_id' => $payment['provider_transaction_id'] ?? null,
            ];
        }

        if (abs($allocated - $total) > 0.01) {
            throw ValidationException::withMessages(['payments' => 'Total pembayaran campuran harus sama dengan total transaksi.']);
        }

        if ($receivedTotal < $total) {
            throw ValidationException::withMessages(['payments' => 'Total uang diterima masih kurang.']);
        }

        return $rows;
    }

    private function paymentMethod(string $code): object
    {
        $paymentMethod = DB::table('payment_methods')
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if (! $paymentMethod) {
            throw ValidationException::withMessages(['payment_method' => 'Metode pembayaran tidak tersedia.']);
        }

        return $paymentMethod;
    }

    private function nextInvoiceNumber(int $storeId): string
    {
        $next = DB::table('sales')->where('store_id', $storeId)->count() + 1;

        return 'INV-JKT-'.now()->format('Ymd').'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function nextReturnNumber(int $storeId): string
    {
        $next = DB::table('sale_returns')->whereIn('sale_id', DB::table('sales')->where('store_id', $storeId)->select('id'))->count() + 1;

        return 'RTR-JKT-'.now()->format('Ymd').'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function nextPurchaseNumber(int $storeId): string
    {
        $next = DB::table('purchase_orders')->where('store_id', $storeId)->count() + 1;

        return 'PO-JKT-'.now()->format('Ymd').'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function storeId(User $user): int
    {
        return (int) (DB::table('user_stores')->where('user_id', $user->id)->value('store_id') ?: DB::table('stores')->value('id'));
    }

    private function warehouseId(int $storeId): int
    {
        return (int) DB::table('warehouses')->where('store_id', $storeId)->value('id');
    }

    private function terminalId(int $storeId): int
    {
        return (int) DB::table('terminals')->where('store_id', $storeId)->value('id');
    }

    private function currentOpenShift(User $user, int $storeId): object
    {
        $shift = DB::table('cashier_shifts')
            ->where('store_id', $storeId)
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->lockForUpdate()
            ->first();

        if (! $shift) {
            throw ValidationException::withMessages(['shift' => 'Shift kasir belum dibuka.']);
        }

        return $shift;
    }

    private function audit(User $user, string $action, string $module, int $recordId, ?array $before, ?array $after): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $user->id,
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

    private function reverseCashShift(object $sale): void
    {
        $cashAmount = DB::table('payments')
            ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->where('payments.sale_id', $sale->id)
            ->where('payment_methods.is_cash', true)
            ->sum('payments.amount');

        if ((float) $cashAmount > 0) {
            DB::table('cashier_shifts')->where('id', $sale->shift_id)->decrement('expected_cash', (float) $cashAmount, ['updated_at' => now()]);
        }
    }
}
