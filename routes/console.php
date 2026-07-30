<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('warungpos:backup-json', function () {
    $tables = ['stores', 'users', 'products', 'inventories', 'sales', 'sale_items', 'payments', 'stock_movements', 'purchase_orders', 'expenses', 'settings'];
    $payload = [];

    foreach ($tables as $table) {
        $payload[$table] = DB::table($table)->get();
    }

    $path = 'backups/warungpos-'.now()->format('Ymd-His').'.json.enc';
    Storage::disk('local')->put($path, Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)));

    $this->info("Backup terenkripsi dibuat: storage/app/private/{$path}");
})->purpose('Create an encrypted WarungPOS JSON backup');

Schedule::command('warungpos:backup-json')->dailyAt('23:30')->withoutOverlapping();
