<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'username')) {
                $table->string('username')->nullable()->after('name');
                $table->unique('username');
            }

            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone')->nullable()->after('email');
            }

            if (! Schema::hasColumn('users', 'pin_hash')) {
                $table->string('pin_hash')->nullable()->after('password');
            }

            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status')->default('active')->after('pin_hash')->index();
            }

            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('module')->index();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('timezone')->default('Asia/Jakarta');
            $table->string('currency')->default('IDR');
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('user_stores', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'store_id']);
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['store_id', 'code']);
        });

        Schema::create('terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['store_id', 'code']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name')->index();
            $table->string('slug')->unique();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('base_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('product_type')->default('unit')->index();
            $table->decimal('purchase_price', 14, 2)->default(0);
            $table->decimal('selling_price', 14, 2)->default(0);
            $table->decimal('wholesale_price', 14, 2)->nullable();
            $table->decimal('minimum_stock', 14, 3)->default(0);
            $table->boolean('track_stock')->default(true);
            $table->boolean('track_batch')->default(false);
            $table->boolean('track_expiry')->default(false);
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('taxable')->default(false);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('sellable')->default(true);
            $table->boolean('purchasable')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['category_id', 'is_active']);
        });

        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->decimal('conversion_to_base', 14, 3)->default(1);
            $table->decimal('purchase_price', 14, 2)->nullable();
            $table->decimal('selling_price', 14, 2)->nullable();
            $table->boolean('is_default_purchase')->default(false);
            $table->boolean('is_default_sale')->default(false);
            $table->timestamps();
        });

        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('barcode')->unique();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('product_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable();
            $table->string('supplier_sku')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('reserved_quantity', 14, 3)->default(0);
            $table->decimal('available_quantity', 14, 3)->default(0);
            $table->decimal('average_cost', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['store_id', 'warehouse_id', 'product_id']);
        });

        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable();
            $table->string('batch_number');
            $table->date('production_date')->nullable();
            $table->date('expiry_date')->nullable()->index();
            $table->decimal('purchase_price', 14, 2)->default(0);
            $table->decimal('initial_quantity', 14, 3)->default(0);
            $table->decimal('remaining_quantity', 14, 3)->default(0);
            $table->string('location')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('stock_batches')->nullOnDelete();
            $table->string('movement_type')->index();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('quantity_in', 14, 3)->default(0);
            $table->decimal('quantity_out', 14, 3)->default(0);
            $table->decimal('stock_before', 14, 3)->default(0);
            $table->decimal('stock_after', 14, 3)->default(0);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['store_id', 'product_id', 'created_at']);
        });

        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('opname_number')->unique();
            $table->string('status')->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('system_quantity', 14, 3)->default(0);
            $table->decimal('actual_quantity', 14, 3)->default(0);
            $table->decimal('difference_quantity', 14, 3)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable()->index();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('type')->default('retail');
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('product_suppliers', function (Blueprint $table) {
            $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
        });

        Schema::table('stock_batches', function (Blueprint $table) {
            $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('order_number')->unique();
            $table->string('status')->default('draft')->index();
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('order_date');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('received_quantity', 14, 3)->default(0);
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('subtotal', 14, 2);
            $table->timestamps();
        });

        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_number')->unique();
            $table->date('received_date');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 2);
            $table->timestamps();
        });

        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('method')->default('cash');
            $table->string('reference_number')->nullable();
            $table->date('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->string('return_number')->unique();
            $table->string('status')->default('completed')->index();
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 2);
            $table->timestamps();
        });

        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('terminal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('opening_cash', 14, 2)->default(0);
            $table->decimal('expected_cash', 14, 2)->default(0);
            $table->decimal('actual_cash', 14, 2)->nullable();
            $table->decimal('cash_difference', 14, 2)->nullable();
            $table->string('status')->default('open')->index();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['store_id', 'status']);
        });

        Schema::create('sale_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->cascadeOnDelete();
            $table->string('hold_number')->unique();
            $table->json('payload');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('terminal_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained('cashier_shifts')->restrictOnDelete();
            $table->string('invoice_number')->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('completed')->index();
            $table->string('payment_status')->default('paid')->index();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('rounding_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('change_amount', 14, 2)->default(0);
            $table->decimal('cost_amount', 14, 2)->default(0);
            $table->decimal('gross_profit', 14, 2)->default(0);
            $table->string('idempotency_key')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'created_at']);
            $table->index(['store_id', 'status', 'created_at']);
            $table->index(['cashier_id', 'created_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('stock_batches')->nullOnDelete();
            $table->string('barcode')->nullable();
            $table->string('product_name');
            $table->decimal('quantity', 14, 3);
            $table->decimal('base_quantity', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('purchase_cost', 14, 2);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('total', 14, 2);
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type')->default('manual');
            $table->boolean('is_cash')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('status')->default('paid')->index();
            $table->string('reference_number')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_transaction_id')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('webhook_payload')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('idempotency_key')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->string('return_number')->unique();
            $table->string('status')->default('completed')->index();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('amount', 14, 2);
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('method')->default('cash');
            $table->string('status')->default('completed')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_shift_id')->constrained()->cascadeOnDelete();
            $table->string('type')->index();
            $table->decimal('amount', 14, 2);
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('status')->default('approved')->index();
            $table->string('payment_method')->default('cash');
            $table->text('description');
            $table->date('expense_date')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type')->index();
            $table->string('title');
            $table->text('message');
            $table->string('level')->default('info');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->string('module')->index();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('group')->default('general')->index();
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->timestamps();
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['attachable_type', 'attachable_id']);
        });

        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->index();
            $table->string('event')->nullable();
            $table->string('signature')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('status')->default('received')->index();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'webhook_logs',
            'attachments',
            'settings',
            'audit_logs',
            'notifications',
            'expenses',
            'expense_categories',
            'cash_movements',
            'refunds',
            'sale_return_items',
            'sale_returns',
            'payment_transactions',
            'payments',
            'payment_methods',
            'sale_items',
            'sales',
            'sale_holds',
            'cashier_shifts',
            'purchase_return_items',
            'purchase_returns',
            'purchase_payments',
            'purchase_receipt_items',
            'purchase_receipts',
            'purchase_order_items',
            'purchase_orders',
            'suppliers',
            'customers',
            'stock_opname_items',
            'stock_opnames',
            'stock_movements',
            'stock_batches',
            'inventories',
            'product_suppliers',
            'product_barcodes',
            'product_units',
            'products',
            'units',
            'brands',
            'categories',
            'terminals',
            'warehouses',
            'user_stores',
            'user_roles',
            'stores',
            'role_permissions',
            'permissions',
            'roles',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('users', function (Blueprint $table) {
            foreach (['username', 'phone', 'pin_hash', 'status', 'last_login_at', 'deleted_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
