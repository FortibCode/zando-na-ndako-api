<?php

namespace App\Support;

use App\Models\LogActivite;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Journal d'audit des actions administratives sensibles. Aucun appelant existant n'écrivait dans
 * LogActivite avant ce helper (table lue par /super-admin/logs mais jamais alimentée) — centralisé
 * ici pour que chaque action sensible (nouvelle ou déjà existante) laisse une trace exploitable.
 */
class AuditLogger
{
    public static function log(
        User $admin,
        string $action,
        string $module,
        string $cibleType,
        string $cibleId,
        ?array $avant = null,
        ?array $apres = null,
        ?Request $request = null,
    ): void {
        try {
            LogActivite::create([
                'user_id' => $admin->id,
                'action' => $action,
                'date_action' => now(),
                'adresse_ip' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'details' => [
                    'module' => $module,
                    'cible_type' => $cibleType,
                    'cible_id' => $cibleId,
                    'avant' => $avant,
                    'apres' => $apres,
                ],
            ]);
        } catch (\Throwable) {
            // L'audit ne doit jamais faire échouer l'action métier qu'il journalise.
        }
    }
}
