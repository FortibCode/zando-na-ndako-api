<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Cache Redis pour les statistiques du dashboard admin, avec invalidation par version : chaque
 * écriture pertinente (commande, paiement, livraison) incrémente un compteur, ce qui rend
 * instantanément obsolètes toutes les clés déjà en cache sans avoir à les énumérer une à une.
 * Le store redis est toujours utilisé explicitement (indépendamment de CACHE_STORE) pour ne pas
 * changer le comportement de cache du reste de l'application.
 */
class DashboardCache
{
    private const STORE = 'redis';

    private const VERSION_KEY = 'dashboard:cache:version';

    public static function bump(): void
    {
        try {
            Cache::store(self::STORE)->add(self::VERSION_KEY, 1, now()->addDays(30));
            Cache::store(self::STORE)->increment(self::VERSION_KEY);
        } catch (\Throwable) {
            // Le cache est un accélérateur, pas une dépendance critique : une panne Redis ne doit
            // jamais faire échouer la création d'une commande, un paiement ou une livraison.
        }
    }

    /**
     * @param  array<int, scalar>  $parts
     */
    public static function remember(string $name, int $ttlSeconds, array $parts, \Closure $callback): mixed
    {
        try {
            return Cache::store(self::STORE)->remember(self::key($name, $parts), $ttlSeconds, $callback);
        } catch (\Throwable) {
            return $callback();
        }
    }

    private static function version(): int
    {
        try {
            return (int) (Cache::store(self::STORE)->get(self::VERSION_KEY) ?? 1);
        } catch (\Throwable) {
            return 1;
        }
    }

    private static function key(string $name, array $parts): string
    {
        $suffix = implode(':', array_map(static fn ($p) => (string) $p, $parts));

        return 'dashboard:v'.self::version().":{$name}:{$suffix}";
    }
}
