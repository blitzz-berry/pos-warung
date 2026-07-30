<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if ($user->hasRole('owner')) {
            return $next($request);
        }

        $allowed = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->join('user_roles', 'user_roles.role_id', '=', 'role_permissions.role_id')
            ->where('user_roles.user_id', $user->id)
            ->whereIn('permissions.slug', $permissions)
            ->exists();

        if (! $allowed) {
            abort(403, 'Anda tidak memiliki izin untuk melakukan tindakan ini.');
        }

        return $next($request);
    }
}
