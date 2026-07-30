<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', 'supervisor')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'product.update')->value('id');

        if ($roleId && $permissionId) {
            DB::table('role_permissions')->updateOrInsert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'supervisor')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'product.update')->value('id');

        if ($roleId && $permissionId) {
            DB::table('role_permissions')->where([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ])->delete();
        }
    }
};
