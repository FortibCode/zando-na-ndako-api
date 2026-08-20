<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$roles
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Non authentifié. Veuillez vous connecter.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        // Vérifier si l'utilisateur a l'un des rôles requis
        $hasRole = false;
        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                $hasRole = true;
                break;
            }
        }

        // Si l'utilisateur est super_admin, il a accès à tout
        if ($user->hasRole('super_admin')) {
            return $next($request);
        }

        if (!$hasRole) {
            return response()->json([
                'success' => false,
                'message' => 'Accès non autorisé. Rôle requis: ' . implode(', ', $roles),
                'your_role' => $user->roles()->first()?->role ?? 'Aucun rôle',
                'required_roles' => $roles,
                'error_code' => 'FORBIDDEN',
            ], 403);
        }

        return $next($request);
    }
}