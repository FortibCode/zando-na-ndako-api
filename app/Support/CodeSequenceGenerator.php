<?php

namespace App\Support;

use App\Models\SequenceCode;
use Illuminate\Support\Facades\DB;

/**
 * Génère des codes de référence lisibles au format PREFIXE-AANNN (ex: ZN-26086 pour la 86ᵉ
 * commande de 2026, LIT-26014 pour le 14ᵉ litige de 2026...). L'année à 2 chiffres est calculée
 * à chaque appel à partir de la date courante — le passage à l'année suivante est donc entièrement
 * automatique, sans tâche planifiée : le premier code généré après le 1ᵉʳ janvier repart à AANNN
 * avec la nouvelle année et un compteur à 1.
 *
 * L'incrémentation passe par un verrou de ligne (lockForUpdate, dans une transaction) sur la ligne
 * (type, année) de la table sequences_codes, pour rester correcte même si deux requêtes créent une
 * commande/un litige/un ticket exactement au même instant.
 */
class CodeSequenceGenerator
{
    public static function next(string $type, string $prefixe, int $chiffresMin = 3): string
    {
        $annee = (int) now()->format('Y');
        $anneeCourte = now()->format('y');

        $numero = DB::transaction(function () use ($type, $annee) {
            $sequence = SequenceCode::where('type', $type)->where('annee', $annee)->lockForUpdate()->first();

            if (!$sequence) {
                $sequence = SequenceCode::create(['type' => $type, 'annee' => $annee, 'dernier_numero' => 1]);
                return 1;
            }

            $sequence->increment('dernier_numero');
            return $sequence->dernier_numero;
        });

        return sprintf('%s-%s%s', $prefixe, $anneeCourte, str_pad((string) $numero, $chiffresMin, '0', STR_PAD_LEFT));
    }
}
