<?php

namespace App\Services;

use App\Models\ParametrePlateforme;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RoutingService
{
    private ?string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.openrouteservice.key');
    }

    /**
     * Calcule la distance et la durée réelles d'un itinéraire routier entre deux points GPS
     * via OpenRouteService (profil "driving-car", le plus proche d'un trajet moto/scooter urbain).
     *
     * Retourne null si aucune clé n'est configurée ou si l'appel échoue — l'appelant doit alors
     * se replier sur le tarif de zone habituel plutôt que de bloquer la commande.
     *
     * @param array{lat: float, lng: float} $origine
     * @param array{lat: float, lng: float} $destination
     * @return array{distance_km: float, duree_min: int}|null
     */
    public function calculerItineraire(array $origine, array $destination): ?array
    {
        if (!$this->apiKey) {
            Log::info('[RoutingService] ORS_API_KEY non configurée — repli sur le tarif de zone.');
            return null;
        }

        if (!isset($origine['lat'], $origine['lng'], $destination['lat'], $destination['lng'])) {
            return null;
        }

        try {
            $response = Http::withHeaders(['Authorization' => $this->apiKey])
                ->timeout(8)
                ->get('https://api.openrouteservice.org/v2/directions/driving-car', [
                    // ORS attend [longitude, latitude]
                    'start' => "{$origine['lng']},{$origine['lat']}",
                    'end'   => "{$destination['lng']},{$destination['lat']}",
                ]);

            if (!$response->successful()) {
                Log::warning('[RoutingService] Échec ORS : ' . $response->status() . ' ' . $response->body());
                return null;
            }

            $summary = $response->json('features.0.properties.summary');
            if (!$summary) return null;

            return [
                'distance_km' => round($summary['distance'] / 1000, 2),
                'duree_min'   => (int) round($summary['duration'] / 60),
            ];
        } catch (\Throwable $e) {
            Log::warning('[RoutingService] Exception : ' . $e->getMessage());
            return null;
        }
    }

    // Barème appliqué à la distance réelle de l'itinéraire : une base fixe (prise en charge,
    // quel que soit le trajet) + un tarif au kilomètre, avec un plancher pour rester cohérent
    // avec les anciens tarifs de zone (2000-3000 FCFA sur Brazzaville). Réglable depuis
    // /admin/parametres (préfixe frais_livraison_*, déjà reconnu par cette page) plutôt que codé en
    // dur — ce barème dépend du prix du carburant / des conditions du marché, pas de la logique.
    public function calculerFrais(float $distanceKm): float
    {
        $base = (float) ParametrePlateforme::valeur('frais_livraison_base', '800');
        $parKm = (float) ParametrePlateforme::valeur('frais_livraison_par_km', '300');
        $plancher = (float) ParametrePlateforme::valeur('frais_livraison_plancher', '1000');
        return max($plancher, $base + $distanceKm * $parKm);
    }

    /**
     * Distance à vol d'oiseau (Haversine, km) — utilisée uniquement comme repère de log,
     * jamais pour le prix facturé (qui doit venir d'un vrai itinéraire ou du tarif de zone).
     */
    public function distanceAVolDoiseau(array $origine, array $destination): float
    {
        $rayonTerre = 6371;
        $dLat = deg2rad($destination['lat'] - $origine['lat']);
        $dLng = deg2rad($destination['lng'] - $origine['lng']);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($origine['lat'])) * cos(deg2rad($destination['lat'])) * sin($dLng / 2) ** 2;
        return round($rayonTerre * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }
}
