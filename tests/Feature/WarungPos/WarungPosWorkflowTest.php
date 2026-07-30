<?php

namespace Tests\Feature\WarungPos;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WarungPosWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_user_can_login_with_username(): void
    {
        $this->post(route('login.attempt'), [
            'login' => 'owner',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    public function test_cash_checkout_reduces_stock_and_records_payment(): void
    {
        $cashier = User::where('username', 'kasir')->firstOrFail();
        $product = DB::table('products')->where('sku', 'MI-GR')->first();
        $inventoryBefore = (float) DB::table('inventories')->where('product_id', $product->id)->value('quantity');

        $this->actingAs($cashier)->post(route('sales.checkout'), [
            'items' => json_encode([['product_id' => $product->id, 'quantity' => 2]]),
            'payment_method' => 'cash',
            'paid_amount' => 10000,
            'idempotency_key' => 'test-cash-sale',
        ])->assertRedirect();

        $this->assertDatabaseHas('sales', [
            'idempotency_key' => 'test-cash-sale',
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);

        $this->assertSame($inventoryBefore - 2, (float) DB::table('inventories')->where('product_id', $product->id)->value('quantity'));
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'movement_type' => 'sale']);
        $this->assertDatabaseHas('payments', ['status' => 'paid']);
    }

    public function test_refund_restores_stock(): void
    {
        $cashier = User::where('username', 'kasir')->firstOrFail();
        $supervisor = User::where('username', 'supervisor')->firstOrFail();
        $product = DB::table('products')->where('sku', 'MI-KR')->first();
        $before = (float) DB::table('inventories')->where('product_id', $product->id)->value('quantity');

        $this->actingAs($cashier)->post(route('sales.checkout'), [
            'items' => json_encode([['product_id' => $product->id, 'quantity' => 1]]),
            'payment_method' => 'cash',
            'paid_amount' => 10000,
            'idempotency_key' => 'test-refund-sale',
        ]);

        $saleId = DB::table('sales')->where('idempotency_key', 'test-refund-sale')->value('id');

        $this->actingAs($supervisor)->post(route('sales.refund', $saleId), [
            'reason' => 'Barang dikembalikan pelanggan',
        ])->assertRedirect();

        $this->assertSame($before, (float) DB::table('inventories')->where('product_id', $product->id)->value('quantity'));
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'refunded']);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'movement_type' => 'sale_return']);
    }

    public function test_product_create_adds_inventory_and_opening_stock_movement(): void
    {
        Storage::fake('public');
        $admin = User::where('username', 'admin')->firstOrFail();
        $unitId = DB::table('units')->where('code', 'pcs')->value('id');
        $categoryId = DB::table('categories')->where('name', 'Minuman')->value('id');

        $this->actingAs($admin)->post(route('products.store'), [
            'name' => 'Kopi Sachet 10 pcs',
            'sku' => 'KP-10',
            'barcode' => '8991999000015',
            'category_id' => $categoryId,
            'base_unit_id' => $unitId,
            'purchase_price' => 12000,
            'selling_price' => 15000,
            'minimum_stock' => 5,
            'opening_stock' => 20,
            'image' => UploadedFile::fake()->image('kopi.jpg'),
        ])->assertRedirect(route('products'));

        $product = DB::table('products')->where('sku', 'KP-10')->first();

        $this->assertNotEmpty($product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
        $this->assertDatabaseHas('inventories', ['product_id' => $product->id, 'quantity' => 20]);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'movement_type' => 'opening_stock']);
    }

    public function test_public_media_route_serves_uploaded_file(): void
    {
        Storage::fake('public');
        $admin = User::where('username', 'admin')->firstOrFail();
        $path = UploadedFile::fake()->image('logo.png')->store('uploads/logos', 'public');

        $this->actingAs($admin)->get(route('media', ['path' => $path]))->assertOk();
        $this->actingAs($admin)->get(route('media', ['path' => '../.env']))->assertNotFound();
    }

    public function test_supervisor_can_create_product_with_scanned_barcode(): void
    {
        $supervisor = User::where('username', 'supervisor')->firstOrFail();
        $unitId = DB::table('units')->where('code', 'pcs')->value('id');
        $barcode = '8997777000000';

        $this->actingAs($supervisor)->get(route('products.create'))->assertOk()
            ->assertSee('data-target-sku="#productSkuInput"', false);

        $this->actingAs($supervisor)->post(route('products.store'), [
            'name' => 'Produk Scan Supervisor',
            'sku' => $barcode,
            'barcode' => $barcode,
            'base_unit_id' => $unitId,
            'purchase_price' => 1000,
            'selling_price' => 1500,
            'opening_stock' => 3,
        ])->assertRedirect(route('products'));

        $productId = DB::table('products')->where('sku', $barcode)->value('id');

        $this->assertDatabaseHas('product_barcodes', ['product_id' => $productId, 'barcode' => $barcode]);
        $this->assertDatabaseHas('inventories', ['product_id' => $productId, 'quantity' => 3]);
    }

    public function test_product_detail_can_update_data_barcode_and_photo(): void
    {
        Storage::fake('public');
        $supervisor = User::where('username', 'supervisor')->firstOrFail();
        $product = DB::table('products')->where('sku', 'BR-5KG')->first();
        $unitId = DB::table('units')->where('code', 'pcs')->value('id');
        $categoryId = DB::table('categories')->where('name', 'Minuman')->value('id');
        $brandId = DB::table('brands')->value('id');
        $oldImage = UploadedFile::fake()->image('old.jpg')->store('uploads/products', 'public');

        DB::table('products')->where('id', $product->id)->update(['image_path' => $oldImage]);

        $this->actingAs($supervisor)->get(route('products.show', $product->id))->assertOk()
            ->assertSee('Ganti gambar produk')
            ->assertSee('data-target-input="#detailProductBarcodeInput"', false);

        $this->actingAs($supervisor)->put(route('products.update', $product->id), [
            'name' => 'Beras Premium 5 Kg Edit',
            'sku' => 'BR-5KG-EDIT',
            'barcode' => '8998888000002',
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'base_unit_id' => $unitId,
            'purchase_price' => 56000,
            'selling_price' => 62000,
            'minimum_stock' => 8,
            'description' => 'Produk sudah diperbarui.',
            'image' => UploadedFile::fake()->image('updated.jpg'),
            'is_active' => 1,
            'sellable' => 1,
            'purchasable' => 1,
        ])->assertRedirect(route('products.show', $product->id));

        $updated = DB::table('products')->where('id', $product->id)->first();

        $this->assertSame('BR-5KG-EDIT', $updated->sku);
        $this->assertSame('Beras Premium 5 Kg Edit', $updated->name);
        $this->assertSame('Produk sudah diperbarui.', $updated->description);
        $this->assertNotSame($oldImage, $updated->image_path);
        Storage::disk('public')->assertMissing($oldImage);
        Storage::disk('public')->assertExists($updated->image_path);
        $this->assertDatabaseHas('product_barcodes', ['product_id' => $product->id, 'barcode' => '8998888000002']);
        $this->assertDatabaseHas('product_units', ['product_id' => $product->id, 'unit_id' => $unitId, 'is_default_sale' => true]);
    }

    public function test_purchase_receive_increases_stock(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $product = DB::table('products')->where('sku', 'GP-1KG')->first();
        $supplierId = DB::table('suppliers')->value('id');
        $before = (float) DB::table('inventories')->where('product_id', $product->id)->value('quantity');

        $this->actingAs($admin)->post(route('purchases.receive'), [
            'supplier_id' => $supplierId,
            'product_id' => $product->id,
            'quantity' => 10,
            'unit_cost' => 15000,
        ])->assertRedirect(route('purchases'));

        $this->assertSame($before + 10, (float) DB::table('inventories')->where('product_id', $product->id)->value('quantity'));
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'movement_type' => 'purchase']);
    }

    public function test_cashier_cannot_access_user_management(): void
    {
        $cashier = User::where('username', 'kasir')->firstOrFail();

        $this->actingAs($cashier)->get(route('users'))->assertForbidden();
    }

    public function test_cancel_sale_restores_stock_and_marks_payment_cancelled(): void
    {
        $cashier = User::where('username', 'kasir')->firstOrFail();
        $supervisor = User::where('username', 'supervisor')->firstOrFail();
        $product = DB::table('products')->where('sku', 'GP-1KG')->first();
        $before = (float) DB::table('inventories')->where('product_id', $product->id)->value('quantity');

        $this->actingAs($cashier)->post(route('sales.checkout'), [
            'items' => json_encode([['product_id' => $product->id, 'quantity' => 1]]),
            'payment_method' => 'cash',
            'paid_amount' => 25000,
            'idempotency_key' => 'test-cancel-sale',
        ])->assertRedirect();

        $saleId = DB::table('sales')->where('idempotency_key', 'test-cancel-sale')->value('id');

        $this->actingAs($supervisor)->post(route('sales.cancel', $saleId), [
            'reason' => 'Salah input transaksi',
        ])->assertRedirect();

        $this->assertSame($before, (float) DB::table('inventories')->where('product_id', $product->id)->value('quantity'));
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'cancelled', 'payment_status' => 'cancelled']);
        $this->assertDatabaseHas('payments', ['sale_id' => $saleId, 'status' => 'cancelled']);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'movement_type' => 'sale_return']);
    }

    public function test_stock_opname_creates_session_item_and_movement(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $product = DB::table('products')->where('sku', 'BR-5KG')->first();
        $before = (float) DB::table('inventories')->where('product_id', $product->id)->value('quantity');
        $actual = $before + 3;

        $this->actingAs($admin)->post(route('inventory.stock-opnames'), [
            'product_id' => $product->id,
            'actual_quantity' => $actual,
            'reason' => 'Opname rak pagi',
        ])->assertRedirect();

        $this->assertSame($actual, (float) DB::table('inventories')->where('product_id', $product->id)->value('quantity'));
        $this->assertDatabaseHas('stock_opnames', ['status' => 'approved']);
        $this->assertDatabaseHas('stock_opname_items', ['product_id' => $product->id, 'difference_quantity' => 3]);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'movement_type' => 'stock_opname']);
    }

    public function test_api_barcode_uses_standard_response(): void
    {
        $cashier = User::where('username', 'kasir')->firstOrFail();
        $barcode = DB::table('product_barcodes')->value('barcode');

        $this->actingAs($cashier)->postJson('/api/pos/barcode', [
            'barcode' => $barcode,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data' => ['product' => ['id', 'name', 'barcode']]]);
    }

    public function test_seeded_ean13_barcodes_have_valid_check_digits(): void
    {
        DB::table('product_barcodes')->pluck('barcode')->each(function (string $barcode) {
            $this->assertMatchesRegularExpression('/^\d{13}$/', $barcode);
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += (int) $barcode[$i] * ($i % 2 === 0 ? 1 : 3);
            }

            $this->assertSame((10 - ($sum % 10)) % 10, (int) $barcode[12], "Invalid EAN-13 barcode: {$barcode}");
        });
    }

    public function test_barcode_scanner_controls_render_on_workflow_screens(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $this->actingAs($admin)->get(route('pos'))->assertOk()
            ->assertSee('data-scan-mode="pos"', false)
            ->assertSee('id="barcodeScanModal"', false)
            ->assertSee('data-create-from-scan', false);

        $this->actingAs($admin)->get(route('products.create'))->assertOk()
            ->assertSee('data-scan-mode="barcode-field"', false);

        $this->actingAs($admin)->get(route('inventory'))->assertOk()
            ->assertSee('data-target-select="#adjustProductSelect"', false)
            ->assertSee('data-target-select="#opnameProductSelect"', false);

        $this->actingAs($admin)->get(route('purchases'))->assertOk()
            ->assertSee('data-target-select="#purchaseProductSelect"', false);
    }

    public function test_product_create_prefills_new_scanned_barcode(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $this->actingAs($admin)->get(route('products.create', ['barcode' => '8995550000001']))->assertOk()
            ->assertSee('id="productSkuInput" name="sku" value="8995550000001"', false)
            ->assertSee('id="productBarcodeInput" name="barcode" value="8995550000001"', false);
    }

    public function test_security_headers_allow_same_origin_camera_for_scanner(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $this->actingAs($admin)->get(route('products.create'))->assertHeader(
            'Permissions-Policy',
            'camera=(self), microphone=(), geolocation=()'
        );
    }

    public function test_payment_webhook_requires_valid_signature(): void
    {
        config(['services.manual.webhook_secret' => 'secret-webhook']);
        $payload = json_encode(['event' => 'payment.paid', 'provider_transaction_id' => 'manual-test']);

        $this->postJson('/webhooks/payments/manual', json_decode($payload, true), [
            'X-Signature' => 'bad-signature',
        ])->assertForbidden();

        $this->postJson('/webhooks/payments/manual', json_decode($payload, true), [
            'X-Signature' => hash_hmac('sha256', $payload, 'secret-webhook'),
        ])->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_payment_api_rejects_overpayment(): void
    {
        $owner = User::where('username', 'owner')->firstOrFail();
        $saleId = DB::table('sales')->where('payment_status', 'paid')->value('id');

        $this->actingAs($owner)->postJson('/api/payments', [
            'sale_id' => $saleId,
            'payment_method' => 'cash',
            'amount' => 1000,
        ])->assertUnprocessable()
            ->assertJsonPath('success', false);
    }
}
