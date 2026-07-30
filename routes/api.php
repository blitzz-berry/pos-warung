<?php

use App\Http\Controllers\WarungPos\ApiController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web'])->group(function () {
    Route::post('/login', [ApiController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware(['auth'])->group(function () {
        Route::post('/logout', [ApiController::class, 'logout']);
        Route::get('/dashboard', [ApiController::class, 'dashboard'])->middleware('permission:dashboard.view');

        Route::get('/products', [ApiController::class, 'products'])->middleware('permission:product.view');
        Route::post('/products', [ApiController::class, 'storeProduct'])->middleware('permission:product.create');
        Route::put('/products/{id}', [ApiController::class, 'updateProduct'])->whereNumber('id')->middleware('permission:product.update');
        Route::delete('/products/{id}', [ApiController::class, 'deleteProduct'])->whereNumber('id')->middleware('permission:product.delete');
        Route::post('/pos/barcode', [ApiController::class, 'barcode'])->middleware('permission:pos.access');

        Route::post('/sales', [ApiController::class, 'storeSale'])->middleware('permission:sale.create');
        Route::get('/sales/{id}', [ApiController::class, 'showSale'])->whereNumber('id')->middleware('permission:sale.view');
        Route::post('/sales/{id}/cancel', [ApiController::class, 'cancelSale'])->whereNumber('id')->middleware('permission:sale.cancel');
        Route::post('/sales/{id}/refund', [ApiController::class, 'refundSale'])->whereNumber('id')->middleware('permission:sale.refund');

        Route::post('/payments', [ApiController::class, 'storePayment'])->middleware('permission:payment.cash,payment.non_cash');
        Route::post('/payments/{id}/verify', [ApiController::class, 'verifyPayment'])->whereNumber('id')->middleware('permission:payment.non_cash');

        Route::get('/inventory', [ApiController::class, 'inventory'])->middleware('permission:stock.view');
        Route::post('/inventory/adjustments', [ApiController::class, 'adjustInventory'])->middleware('permission:stock.adjust');
        Route::post('/inventory/stock-opnames', [ApiController::class, 'stockOpname'])->middleware('permission:stock.opname');

        Route::get('/purchases', [ApiController::class, 'purchases'])->middleware('permission:purchase.view');
        Route::post('/purchases', [ApiController::class, 'storePurchase'])->middleware('permission:purchase.create,purchase.receive');
        Route::post('/purchases/{id}/receive', [ApiController::class, 'receivePurchase'])->whereNumber('id')->middleware('permission:purchase.receive');

        Route::post('/shifts/open', [ApiController::class, 'openShift'])->middleware('permission:shift.open');
        Route::post('/shifts/{id}/close', [ApiController::class, 'closeShift'])->whereNumber('id')->middleware('permission:shift.close');

        Route::get('/reports/sales', [ApiController::class, 'salesReport'])->middleware('permission:report.sales');
        Route::get('/reports/inventory', [ApiController::class, 'inventoryReport'])->middleware('permission:report.inventory');
        Route::get('/reports/profit', [ApiController::class, 'profitReport'])->middleware('permission:report.profit');
    });
});
