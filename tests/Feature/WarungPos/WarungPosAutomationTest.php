<?php

namespace Tests\Feature\WarungPos;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class WarungPosAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_main_web_pages_render_for_owner(): void
    {
        $owner = User::where('username', 'owner')->firstOrFail();
        $saleId = DB::table('sales')->value('id');
        $productId = DB::table('products')->value('id');

        foreach ([
            route('dashboard'),
            route('pos'),
            route('products'),
            route('products.create'),
            route('products.show', $productId),
            route('inventory'),
            route('purchases'),
            route('sales'),
            route('sales.show', $saleId),
            route('customers'),
            route('suppliers'),
            route('expenses'),
            route('reports'),
            route('users'),
            route('audit'),
            route('settings'),
        ] as $url) {
            $this->actingAs($owner)->get($url)->assertOk();
        }
    }

    public function test_admin_web_forms_for_master_data_settings_and_shift_work(): void
    {
        Storage::fake('public');
        $admin = User::where('username', 'admin')->firstOrFail();
        $supervisor = User::where('username', 'supervisor')->firstOrFail();
        $roleId = DB::table('roles')->where('slug', 'kasir')->value('id');
        $expenseCategoryId = DB::table('expense_categories')->value('id');

        $this->actingAs($admin)->post(route('customers.store'), [
            'name' => 'Pelanggan Test',
            'phone' => '081234567001',
            'address' => 'Jakarta',
        ])->assertRedirect();
        $this->assertDatabaseHas('customers', ['name' => 'Pelanggan Test', 'status' => 'active']);

        $this->actingAs($admin)->post(route('suppliers.store'), [
            'name' => 'Supplier Test',
            'phone' => '0215550001',
            'contact_person' => 'Budi',
            'address' => 'Bandung',
        ])->assertRedirect();
        $this->assertDatabaseHas('suppliers', ['name' => 'Supplier Test', 'status' => 'active']);

        $this->actingAs($admin)->post(route('expenses.store'), [
            'expense_category_id' => $expenseCategoryId,
            'amount' => 125000,
            'expense_date' => now()->toDateString(),
            'description' => 'Biaya operasional test',
        ])->assertRedirect();
        $this->assertDatabaseHas('expenses', ['description' => 'Biaya operasional test', 'status' => 'approved']);

        $this->actingAs($admin)->post(route('settings.update'), [
            'store__name' => 'Warung Test',
            'pos__discount_limit' => 15,
            'store_logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertRedirect();
        $this->assertDatabaseHas('settings', ['key' => 'store.name', 'value' => 'Warung Test']);
        $this->assertDatabaseHas('settings', ['key' => 'pos.discount_limit', 'value' => '15']);
        $logoPath = DB::table('settings')->where('key', 'store.logo_path')->value('value');
        $this->assertNotEmpty($logoPath);
        Storage::disk('public')->assertExists($logoPath);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Kasir Baru',
            'username' => 'kasirbaru',
            'email' => 'kasirbaru@warungpos.test',
            'phone' => '08129990001',
            'role_id' => $roleId,
            'password' => 'password-baru',
        ])->assertRedirect();
        $userId = DB::table('users')->where('username', 'kasirbaru')->value('id');
        $this->assertTrue(Hash::check('password-baru', DB::table('users')->where('id', $userId)->value('password')));
        $this->assertDatabaseHas('user_roles', ['user_id' => $userId, 'role_id' => $roleId]);

        $this->actingAs($supervisor)->post(route('shifts.open'), [
            'opening_cash' => 100000,
            'opening_notes' => 'Shift test',
        ])->assertRedirect();
        $this->assertDatabaseHas('cashier_shifts', ['user_id' => $supervisor->id, 'status' => 'open']);

        $this->actingAs($supervisor)->post(route('shifts.close'), [
            'actual_cash' => 100000,
            'closing_notes' => 'Tutup test',
        ])->assertRedirect();
        $this->assertDatabaseHas('cashier_shifts', ['user_id' => $supervisor->id, 'status' => 'closed']);
    }

    public function test_import_export_and_backup_workflows_work(): void
    {
        $owner = User::where('username', 'owner')->firstOrFail();

        $this->actingAs($owner)->post(route('products.import'), [
            'file' => $this->xlsxUpload('products.xlsx', [
                ['sku', 'name', 'barcode', 'category', 'unit', 'purchase_price', 'selling_price', 'stock', 'minimum_stock'],
                ['XLSX-001', 'Produk Import Test', '8995550000001', 'Minuman', 'pcs', 4000, 5500, 7, 2],
            ]),
        ])->assertRedirect();

        $productId = DB::table('products')->where('sku', 'XLSX-001')->value('id');
        $this->assertDatabaseHas('product_barcodes', ['product_id' => $productId, 'barcode' => '8995550000001']);
        $this->assertDatabaseHas('product_units', ['product_id' => $productId, 'is_default_sale' => true]);
        $this->assertDatabaseHas('inventories', ['product_id' => $productId, 'quantity' => 7]);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $productId, 'movement_type' => 'opening_stock']);

        $productsExport = $this->actingAs($owner)->get(route('products.export'))->assertOk();
        $this->assertStringContainsString('produk-warungpos.xlsx', $productsExport->headers->get('content-disposition'));
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $productsExport->headers->get('content-type'));

        $salesExport = $this->actingAs($owner)->get(route('reports.sales.export'))->assertOk();
        $this->assertStringContainsString('laporan-penjualan.xlsx', $salesExport->headers->get('content-disposition'));
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $salesExport->headers->get('content-type'));

        $backup = $this->actingAs($owner)->get(route('settings.backup'))->assertOk();
        $this->assertStringContainsString('warungpos-backup-', $backup->headers->get('content-disposition'));
        $this->assertStringContainsString('"products"', $backup->streamedContent());
    }

    public function test_api_endpoints_cover_core_workflows(): void
    {
        $owner = User::where('username', 'owner')->firstOrFail();
        $cashier = User::where('username', 'kasir')->firstOrFail();
        $unitId = DB::table('units')->where('code', 'pcs')->value('id');
        $product = DB::table('products')->where('sku', 'AQ-600')->first();
        $supplierId = DB::table('suppliers')->value('id');

        foreach ([
            '/api/dashboard',
            '/api/products',
            '/api/inventory',
            '/api/purchases',
            '/api/reports/sales',
            '/api/reports/inventory',
            '/api/reports/profit',
        ] as $url) {
            $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('success', true);
        }

        $createdProduct = $this->actingAs($owner)->postJson('/api/products', [
            'name' => 'Produk API Test',
            'sku' => 'API-001',
            'barcode' => '8995550000001',
            'base_unit_id' => $unitId,
            'purchase_price' => 2000,
            'selling_price' => 3000,
            'opening_stock' => 5,
        ])->assertCreated()->assertJsonPath('success', true)->json('data.product_id');

        $this->actingAs($owner)->putJson("/api/products/{$createdProduct}", [
            'name' => 'Produk API Update',
            'selling_price' => 3500,
        ])->assertOk()->assertJsonPath('data.product.name', 'Produk API Update');

        $this->actingAs($owner)->postJson('/api/inventory/adjustments', [
            'product_id' => $product->id,
            'actual_quantity' => 14,
            'reason' => 'Adjustment API test',
        ])->assertOk()->assertJsonPath('success', true);
        $this->assertSame(14.0, (float) DB::table('inventories')->where('product_id', $product->id)->value('quantity'));

        $this->actingAs($owner)->postJson('/api/purchases', [
            'supplier_id' => $supplierId,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_cost' => 2500,
            ]],
        ])->assertCreated()->assertJsonPath('success', true);
        $this->assertSame(16.0, (float) DB::table('inventories')->where('product_id', $product->id)->value('quantity'));

        $saleId = $this->actingAs($cashier)->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'paid_amount' => 5000,
            'idempotency_key' => 'api-sale-test',
        ])->assertCreated()->assertJsonPath('success', true)->json('data.sale.id');
        $this->actingAs($cashier)->getJson("/api/sales/{$saleId}")->assertOk()->assertJsonPath('data.sale.id', $saleId);

        $paymentId = DB::table('payments')->where('sale_id', $saleId)->value('id');
        $this->actingAs($owner)->postJson("/api/payments/{$paymentId}/verify")->assertOk()->assertJsonPath('success', true);

        $shiftId = $this->actingAs($owner)->postJson('/api/shifts/open', [
            'opening_cash' => 50000,
            'opening_notes' => 'API shift',
        ])->assertCreated()->assertJsonPath('success', true)->json('data.shift_id');
        $this->actingAs($owner)->postJson("/api/shifts/{$shiftId}/close", [
            'actual_cash' => 50000,
            'closing_notes' => 'API close',
        ])->assertOk()->assertJsonPath('success', true);

        $this->actingAs($owner)->deleteJson("/api/products/{$createdProduct}")->assertOk()->assertJsonPath('success', true);
        $this->assertNotNull(DB::table('products')->where('id', $createdProduct)->value('deleted_at'));
    }

    public function test_api_login_and_logout_work(): void
    {
        $this->postJson('/api/login', [
            'login' => 'owner',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.username', 'owner');

        $this->postJson('/api/logout')->assertOk()->assertJsonPath('success', true);
    }

    private function xlsxUpload(string $name, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 1), $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'warungpos-xlsx-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
