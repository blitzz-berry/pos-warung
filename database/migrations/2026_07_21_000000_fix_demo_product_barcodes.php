<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $barcodes = [
        '8991000000011' => '8991000000010',
        '8991000000028' => '8991000000027',
        '8991000000035' => '8991000000034',
        '8991000000042' => '8991000000041',
        '8991000000059' => '8991000000058',
        '8991000000066' => '8991000000065',
        '8991000000073' => '8991000000072',
        '8991000000080' => '8991000000089',
    ];

    public function up(): void
    {
        foreach ($this->barcodes as $old => $new) {
            if (! DB::table('product_barcodes')->where('barcode', $new)->exists()) {
                DB::table('product_barcodes')->where('barcode', $old)->update(['barcode' => $new]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->barcodes as $old => $new) {
            if (! DB::table('product_barcodes')->where('barcode', $old)->exists()) {
                DB::table('product_barcodes')->where('barcode', $new)->update(['barcode' => $old]);
            }
        }
    }
};
