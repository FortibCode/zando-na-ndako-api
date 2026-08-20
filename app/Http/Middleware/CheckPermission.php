<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $permission
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $permission)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Non authentifié. Veuillez vous connecter.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        // Super admin a toutes les permissions
        if ($user->hasRole('super_admin')) {
            return $next($request);
        }

        if (!$user->hasPermission($permission)) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas la permission nécessaire pour effectuer cette action.',
                'required_permission' => $permission,
                'your_permissions' => $user->getPermissions(),
                'error_code' => 'PERMISSION_DENIED',
            ], 403);
        }

        return $next($request);
    }
}