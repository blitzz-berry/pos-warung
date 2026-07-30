<?php

use App\Http\Controllers\WarungPos\AppController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AppController::class, 'loginForm'])->name('login');
    Route::post('/login', [AppController::class, 'login'])->middleware('throttle:5,1')->name('login.attempt');
});

Route::post('/webhooks/payments/{provider}', [AppController::class, 'paymentWebhook'])->middleware('throttle:120,1')->name('payments.webhook');

Route::middleware('auth')->group(function () {
    Route::get('/', [AppController::class, 'dashboard'])->middleware('permission:dashboard.view')->name('dashboard');
    Route::post('/logout', [AppController::class, 'logout'])->name('logout');

    Route::get('/pos', [AppController::class, 'pos'])->middleware('permission:pos.access')->name('pos');
    Route::post('/sales', [AppController::class, 'checkout'])->middleware('permission:sale.create')->name('sales.checkout');
    Route::post('/shifts/open', [AppController::class, 'openShift'])->middleware('permission:shift.open')->name('shifts.open');
    Route::post('/shifts/close', [AppController::class, 'closeShift'])->middleware('permission:shift.close')->name('shifts.close');

    Route::get('/products', [AppController::class, 'products'])->middleware('permission:product.view')->name('products');
    Route::get('/products/create', [AppController::class, 'createProduct'])->middleware('permission:product.create')->name('products.create');
    Route::post('/products', [AppController::class, 'storeProduct'])->middleware('permission:product.create')->name('products.store');
    Route::post('/products/import', [AppController::class, 'importProducts'])->middleware('permission:product.import')->name('products.import');
    Route::get('/products/export', [AppController::class, 'exportProducts'])->middleware('permission:product.export')->name('products.export');
    Route::get('/products/{id}', [AppController::class, 'showProduct'])->whereNumber('id')->middleware('permission:product.view')->name('products.show');
    Route::put('/products/{id}', [AppController::class, 'updateProduct'])->whereNumber('id')->middleware('permission:product.update')->name('products.update');

    Route::get('/inventory', [AppController::class, 'inventory'])->middleware('permission:stock.view')->name('inventory');
    Route::post('/inventory/adjustments', [AppController::class, 'adjustStock'])->middleware('permission:stock.adjust')->name('inventory.adjust');
    Route::post('/inventory/stock-opnames', [AppController::class, 'stockOpname'])->middleware('permission:stock.opname')->name('inventory.stock-opnames');

    Route::get('/purchases', [AppController::class, 'purchases'])->middleware('permission:purchase.view')->name('purchases');
    Route::post('/purchases/receive', [AppController::class, 'receivePurchase'])->middleware('permission:purchase.receive')->name('purchases.receive');

    Route::get('/sales', [AppController::class, 'sales'])->middleware('permission:sale.view')->name('sales');
    Route::get('/sales/{id}', [AppController::class, 'showSale'])->whereNumber('id')->middleware('permission:sale.view')->name('sales.show');
    Route::post('/sales/{id}/refund', [AppController::class, 'refundSale'])->whereNumber('id')->middleware('permission:sale.refund')->name('sales.refund');
    Route::post('/sales/{id}/cancel', [AppController::class, 'cancelSale'])->whereNumber('id')->middleware('permission:sale.cancel')->name('sales.cancel');

    Route::get('/customers', [AppController::class, 'customers'])->middleware('permission:customer.manage')->name('customers');
    Route::post('/customers', [AppController::class, 'storeCustomer'])->middleware('permission:customer.manage')->name('customers.store');
    Route::get('/suppliers', [AppController::class, 'suppliers'])->middleware('permission:supplier.manage')->name('suppliers');
    Route::post('/suppliers', [AppController::class, 'storeSupplier'])->middleware('permission:supplier.manage')->name('suppliers.store');

    Route::get('/expenses', [AppController::class, 'expenses'])->middleware('permission:expense.create')->name('expenses');
    Route::post('/expenses', [AppController::class, 'storeExpense'])->middleware('permission:expense.create')->name('expenses.store');

    Route::get('/reports', [AppController::class, 'reports'])->middleware('permission:report.sales')->name('reports');
    Route::get('/reports/sales/export', [AppController::class, 'exportSales'])->middleware('permission:report.sales')->name('reports.sales.export');

    Route::get('/users', [AppController::class, 'users'])->middleware('permission:user.manage')->name('users');
    Route::post('/users', [AppController::class, 'storeUser'])->middleware('permission:user.manage')->name('users.store');

    Route::get('/audit-log', [AppController::class, 'auditLogs'])->middleware('permission:audit.view')->name('audit');
    Route::get('/settings', [AppController::class, 'settings'])->middleware('permission:setting.manage')->name('settings');
    Route::post('/settings', [AppController::class, 'updateSettings'])->middleware('permission:setting.manage')->name('settings.update');
    Route::get('/settings/backup', [AppController::class, 'backup'])->middleware('permission:setting.manage')->name('settings.backup');
});
