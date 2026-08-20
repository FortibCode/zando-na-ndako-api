<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Litige;
use App\Models\Livraison;
use App\Models\Livreur;
use App\Models\Paiement;
use App\Models\Produit;
use App\Models\TauxChange;
use App\Models\User;
use App\Models\Vendeur;
use App\Support\DashboardCache;
use App\Support\DashboardPeriod;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Statistiques & analytics du dashboard administrateur — données 100% réelles, agrégées en SQL
 * (PostgreSQL) et mises en cache Redis (voir DashboardCache) avec un TTL court, jamais des
 * données pré-calculées en mémoire PHP sur des collections complètes.
 */
class DashboardStatsController extends Controller
{
    private const STATUT_LABELS = [
        'confirmee' => 'Confirmée',
        'achat_marche' => 'Achat au marché',
        'preparation' => 'Préparation',
        'en_route' => 'En livraison',
        'livree' => 'Livrée',
        'annulee' => 'Annulée',
    ];

    private const PAIEMENT_LABELS = [
        'stripe' => 'Carte bancaire (Stripe)',
        'carte_locale' => 'Carte bancaire',
        'paypal' => 'PayPal',
        'mtn_momo' => 'MTN Mobile Money',
        'airtel_money' => 'Airtel Money',
        'paiement_livraison' => 'Paiement à la livraison',
    ];

    // GET /api/admin/dashboard/overview
    public function overview(Request $request): JsonResponse
    {
        $period = DashboardPeriod::fromRequest($request);

        $data = DashboardCache::remember('overview', $period->ttl(), $period->cacheKeyParts(), function () use ($period) {
            $current = $this->overviewMetrics($period->start, $period->end);
            $previous = $this->overviewMetrics($period->previousStart, $period->previousEnd);

            return [
                'periode' => [
                    'valeur' => $period->period,
                    'debut' => $period->start->toDateString(),
                    'fin' => $period->end->toDateString(),
                ],
                'chiffre_affaires' => $this->withEvolution($current['revenue'], $previous['revenue']),
                'commandes' => $this->withEvolution($current['orders'], $previous['orders']),
                'nouveaux_clients' => $this->withEvolution($current['clients'], $previous['clients']),
                'livraisons' => $this->withEvolution($current['deliveries'], $previous['deliveries']),
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function overviewMetrics(Carbon $start, Carbon $end): array
    {
        return [
            'revenue' => (float) Commande::where('statut_commande', 'livree')
                ->whereBetween('date_commande', [$start, $end])
                ->sum('montant_total'),
            'orders' => Commande::whereBetween('date_commande', [$start, $end])->count(),
            'clients' => User::where('type_utilisateur', 'client')
                ->whereBetween('date_inscription', [$start, $end])
                ->count(),
            'deliveries' => Livraison::where('statut_livraison', 'livree')
                ->whereBetween('date_livraison', [$start, $end])
                ->count(),
        ];
    }

    private function withEvolution(int|float $current, int|float $previous): array
    {
        return [
            'valeur' => $current,
            'evolution_pct' => $this->pctChange($current, $previous),
        ];
    }

    private function pctChange(int|float $current, int|float $previous): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    // GET /api/admin/dashboard/sales
    public function sales(Request $request): JsonResponse
    {
        $period = DashboardPeriod::fromRequest($request);

        $data = DashboardCache::remember('sales', $period->ttl(), $period->cacheKeyParts(), function () use ($period) {
            $bucket = $period->granularity();

            $rows = Commande::where('statut_commande', '!=', 'annulee')
                ->whereBetween('date_commande', [$period->start, $period->end])
                ->selectRaw("DATE_TRUNC('{$bucket}', date_commande) as periode, COUNT(*) as commandes, COALESCE(SUM(montant_total), 0) as revenus")
                ->groupBy('periode')
                ->orderBy('periode')
                ->get();

            return [
                'granularite' => $bucket,
                'points' => $rows->map(fn ($r) => [
                    'date' => Carbon::parse($r->periode)->toDateString(),
                    'commandes' => (int) $r->commandes,
                    'revenus' => (float) $r->revenus,
                ]),
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // GET /api/admin/dashboard/orders
    public function orders(Request $request): JsonResponse
    {
        $period = DashboardPeriod::fromRequest($request);

        $data = DashboardCache::remember('orders', $period->ttl(), $period->cacheKeyParts(), function () use ($period) {
            $counts = Commande::whereBetween('date_commande', [$period->start, $period->end])
                ->selectRaw('statut_commande, COUNT(*) as total')
                ->groupBy('statut_commande')
                ->pluck('total', 'statut_commande');

            $statuts = collect(self::STATUT_LABELS)->map(fn ($label, $code) => [
                'statut' => $code,
                'label' => $label,
                'total' => (int) ($counts[$code] ?? 0),
            ])->values();

            return [
                'total' => (int) $counts->sum(),
                'statuts' => $statuts,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // GET /api/admin/dashboard/products
    public function products(Request $request): JsonResponse
    {
        $period = DashboardPeriod::fromRequest($request);
        $limit = max(1, min((int) $request->get('limit', 10), 20));

        $data = DashboardCache::remember('products', $period->ttl(), [...$period->cacheKeyParts(), $limit], function () use ($period, $limit) {
            $produits = DB::table('lignes_commande')
                ->join('commandes', 'lignes_commande.commande_id', '=', 'commandes.id')
                ->join('produits', 'lignes_commande.produit_id', '=', 'produits.id')
                ->leftJoin('vendeurs', 'produits.vendeur_id', '=', 'vendeurs.id')
                ->where('commandes.statut_commande', '!=', 'annulee')
                ->whereBetween('commandes.date_commande', [$period->start, $period->end])
                ->selectRaw('produits.id, produits.nom_produit, produits.photo_produit, vendeurs.nom_commerce,
                    SUM(lignes_commande.quantite) as quantite_vendue, SUM(lignes_commande.sous_total) as revenus')
                ->groupBy('produits.id', 'produits.nom_produit', 'produits.photo_produit', 'vendeurs.nom_commerce')
                ->orderByDesc('quantite_vendue')
                ->limit($limit)
                ->get();

            return ['produits' => $produits];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // GET /api/admin/dashboard/payments
    public function payments(Request $request): JsonResponse
    {
        $period = DashboardPeriod::fromRequest($request);

        $data = DashboardCache::remember('payments', $period->ttl(), $period->cacheKeyParts(), function () use ($period) {
            $rows = Paiement::where('statut', 'valide')
                ->whereBetween('date_paiement', [$period->start, $period->end])
                ->selectRaw('methode, devise, COUNT(*) as nb, SUM(montant) as total')
                ->groupBy('methode', 'devise')
                ->get();

            $taux = TauxChange::where('devise_cible', 'FCFA')->get()->keyBy('devise_source');

            $parMethode = [];
            foreach ($rows as $row) {
                $montantFcfa = $row->devise === 'FCFA'
                    ? (float) $row->total
                    : (float) $row->total * (float) ($taux[$row->devise]->valeur_taux ?? 1);

                $parMethode[$row->methode] ??= [
                    'methode' => $row->methode,
                    'label' => self::PAIEMENT_LABELS[$row->methode] ?? ucfirst(str_replace('_', ' ', $row->methode)),
                    'nb' => 0,
                    'montant' => 0.0,
                ];
                $parMethode[$row->methode]['nb'] += (int) $row->nb;
                $parMethode[$row->methode]['montant'] += $montantFcfa;
            }

            $total = array_sum(array_column($parMethode, 'montant'));
            $methodes = array_values(array_map(function ($m) use ($total) {
                $m['pourcentage'] = $total > 0 ? round($m['montant'] / $total * 100, 1) : 0.0;

                return $m;
            }, $parMethode));

            usort($methodes, fn ($a, $b) => $b['montant'] <=> $a['montant']);

            return ['total' => $total, 'methodes' => $methodes];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // GET /api/admin/dashboard/customers
    public function customers(Request $request): JsonResponse
    {
        $period = DashboardPeriod::fromRequest($request);

        $data = DashboardCache::remember('customers', $period->ttl(), $period->cacheKeyParts(), function () use ($period) {
            $bucket = $period->granularity();

            $totalClients = User::where('type_utilisateur', 'client')->count();
            $nouveaux = User::where('type_utilisateur', 'client')
                ->whereBetween('date_inscription', [$period->start, $period->end])
                ->count();
            $diaspora = (int) DB::table('clients')->where('est_diaspora', true)->count();

            $actifs = (int) Commande::whereBetween('date_commande', [$period->start, $period->end])
                ->selectRaw('COUNT(DISTINCT client_id) as c')
                ->value('c');

            $recurrents = (int) DB::table(DB::raw(
                '(SELECT client_id FROM commandes GROUP BY client_id HAVING COUNT(*) >= 2) as clients_recurrents'
            ))->count();

            $inscriptions = User::where('type_utilisateur', 'client')
                ->whereBetween('date_inscription', [$period->start, $period->end])
                ->selectRaw("DATE_TRUNC('{$bucket}', date_inscription) as periode, COUNT(*) as total")
                ->groupBy('periode')
                ->orderBy('periode')
                ->get()
                ->map(fn ($r) => ['date' => Carbon::parse($r->periode)->toDateString(), 'total' => (int) $r->total]);

            return [
                'total_clients' => $totalClients,
                'nouveaux_clients' => $nouveaux,
                'clients_actifs' => $actifs,
                'clients_recurrents' => $recurrents,
                'clients_diaspora' => $diaspora,
                'evolution_inscriptions' => $inscriptions,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // GET /api/admin/dashboard/vendors
    public function vendors(Request $request): JsonResponse
    {
        $period = DashboardPeriod::fromRequest($request);
        $limit = max(1, min((int) $request->get('limit', 10), 20));

        $data = DashboardCache::remember('vendors', $period->ttl(), [...$period->cacheKeyParts(), $limit], function () use ($period, $limit) {
            $vendeursActifs = Vendeur::where('statut_validation', 'valide')->count();

            $topVendeurs = Commande::query()
                ->join('vendeurs', 'commandes.vendeur_id', '=', 'vendeurs.id')
                ->join('users', 'vendeurs.user_id', '=', 'users.id')
                ->where('commandes.statut_commande', '!=', 'annulee')
                ->whereBetween('commandes.date_commande', [$period->start, $period->end])
                ->selectRaw('vendeurs.id, vendeurs.nom_commerce, users.nom, users.prenom,
                    COUNT(commandes.id) as commandes, COALESCE(SUM(commandes.montant_total), 0) as chiffre_affaires')
                ->groupBy('vendeurs.id', 'vendeurs.nom_commerce', 'users.nom', 'users.prenom')
                ->orderByDesc('chiffre_affaires')
                ->limit($limit)
                ->get();

            return ['vendeurs_actifs' => $vendeursActifs, 'top_vendeurs' => $topVendeurs];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // GET /api/admin/dashboard/deliveries
    public function deliveries(Request $request): JsonResponse
    {
        $period = DashboardPeriod::fromRequest($request);

        $data = DashboardCache::remember('deliveries', $period->ttl(), $period->cacheKeyParts(), function () use ($period) {
            $base = fn () => Livraison::whereBetween('created_at', [$period->start, $period->end]);

            $total = $base()->count();
            $terminees = $base()->where('statut_livraison', 'livree')->count();
            $enCours = $base()->whereIn('statut_livraison', ['assigne', 'en_cours'])->count();
            $echouees = $base()->where('statut_livraison', 'echouee')->count();
            $dureeMoyenne = $base()->where('statut_livraison', 'livree')->whereNotNull('duree_reelle')->avg('duree_reelle');

            $revenusLivraison = (float) Commande::where('statut_commande', 'livree')
                ->whereBetween('date_commande', [$period->start, $period->end])
                ->sum('frais_livraison');

            $topLivreurs = Livraison::query()
                ->join('livreurs', 'livraisons.livreur_id', '=', 'livreurs.id')
                ->join('users', 'livreurs.user_id', '=', 'users.id')
                ->join('commandes', 'livraisons.commande_id', '=', 'commandes.id')
                ->where('livraisons.statut_livraison', 'livree')
                ->whereBetween('livraisons.date_livraison', [$period->start, $period->end])
                ->selectRaw('livreurs.id, users.nom, users.prenom,
                    COUNT(livraisons.id) as livraisons, COALESCE(SUM(commandes.frais_livraison), 0) as revenus')
                ->groupBy('livreurs.id', 'users.nom', 'users.prenom')
                ->orderByDesc('livraisons')
                ->limit(10)
                ->get();

            return [
                'total' => $total,
                'terminees' => $terminees,
                'en_cours' => $enCours,
                'echouees' => $echouees,
                'duree_moyenne_minutes' => $dureeMoyenne ? (int) round($dureeMoyenne) : null,
                'revenus_livraison' => $revenusLivraison,
                'top_livreurs' => $topLivreurs,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // GET /api/admin/dashboard/segments
    // Ventilation diaspora vs locale et par zone de livraison (via la zone du vendeur).
    public function segments(Request $request): JsonResponse
    {
        $period = DashboardPeriod::fromRequest($request);

        $data = DashboardCache::remember('segments', $period->ttl(), $period->cacheKeyParts(), function () use ($period) {
            $parClientele = Commande::query()
                ->join('clients', 'commandes.client_id', '=', 'clients.id')
                ->where('commandes.statut_commande', '!=', 'annulee')
                ->whereBetween('commandes.date_commande', [$period->start, $period->end])
                ->selectRaw('clients.est_diaspora, COUNT(commandes.id) as commandes, COALESCE(SUM(commandes.montant_total), 0) as chiffre_affaires')
                ->groupBy('clients.est_diaspora')
                ->get()
                ->keyBy(fn ($r) => $r->est_diaspora ? 'diaspora' : 'local');

            $clientele = collect(['local', 'diaspora'])->map(fn ($key) => [
                'segment' => $key,
                'commandes' => (int) ($parClientele[$key]->commandes ?? 0),
                'chiffre_affaires' => (float) ($parClientele[$key]->chiffre_affaires ?? 0),
            ])->values();

            $zones = Commande::query()
                ->join('vendeurs', 'commandes.vendeur_id', '=', 'vendeurs.id')
                ->join('zones_livraison', 'vendeurs.zone_id', '=', 'zones_livraison.id')
                ->where('commandes.statut_commande', '!=', 'annulee')
                ->whereBetween('commandes.date_commande', [$period->start, $period->end])
                ->selectRaw('zones_livraison.id, zones_livraison.nom_zone, zones_livraison.ville,
                    COUNT(commandes.id) as commandes, COALESCE(SUM(commandes.montant_total), 0) as chiffre_affaires')
                ->groupBy('zones_livraison.id', 'zones_livraison.nom_zone', 'zones_livraison.ville')
                ->orderByDesc('chiffre_affaires')
                ->get();

            return ['clientele' => $clientele, 'zones' => $zones];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // GET /api/admin/dashboard/activity
    public function activity(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->get('limit', 15), 30));

        $data = DashboardCache::remember('activity', 30, [$limit], function () use ($limit) {
            $items = collect();

            Commande::with('client.user')->latest('date_commande')->limit($limit)->get()->each(function ($c) use ($items) {
                $nom = $c->client?->user ? trim(($c->client->user->prenom ?? '').' '.($c->client->user->nom ?? '')) : null;
                $items->push([
                    'type' => 'commande',
                    'titre' => 'Nouvelle commande',
                    'description' => "Commande {$c->numero_commande}".($nom ? " par {$nom}" : ''),
                    'statut' => $c->statut_commande,
                    'date' => $c->date_commande,
                ]);
            });

            Commande::where('statut_commande', 'livree')->latest('updated_at')->limit($limit)->get()->each(function ($c) use ($items) {
                $items->push([
                    'type' => 'livraison',
                    'titre' => 'Commande livrée',
                    'description' => "Commande {$c->numero_commande} livrée avec succès",
                    'statut' => 'livree',
                    'date' => $c->updated_at,
                ]);
            });

            Commande::where('statut_commande', 'annulee')->latest('updated_at')->limit($limit)->get()->each(function ($c) use ($items) {
                $items->push([
                    'type' => 'annulation',
                    'titre' => 'Commande annulée',
                    'description' => "Commande {$c->numero_commande} annulée".($c->motif_annulation ? " — {$c->motif_annulation}" : ''),
                    'statut' => 'annulee',
                    'date' => $c->updated_at,
                ]);
            });

            Paiement::where('statut', 'valide')->latest('date_paiement')->limit($limit)->get()->each(function ($p) use ($items) {
                $items->push([
                    'type' => 'paiement',
                    'titre' => 'Paiement effectué',
                    'description' => 'Paiement de '.number_format((float) $p->montant, 0, ',', ' ')." {$p->devise} reçu",
                    'statut' => 'valide',
                    'date' => $p->date_paiement,
                ]);
            });

            User::where('type_utilisateur', 'client')->latest('date_inscription')->limit($limit)->get()->each(function ($u) use ($items) {
                $items->push([
                    'type' => 'client',
                    'titre' => 'Nouveau client',
                    'description' => trim(($u->prenom ?? '').' '.($u->nom ?? '')).' a rejoint la plateforme',
                    'statut' => 'actif',
                    'date' => $u->date_inscription,
                ]);
            });

            Vendeur::with('user')->latest('created_at')->limit($limit)->get()->each(function ($v) use ($items) {
                $items->push([
                    'type' => 'vendeur',
                    'titre' => 'Nouveau vendeur',
                    'description' => "{$v->nom_commerce} a rejoint la plateforme",
                    'statut' => $v->statut_validation,
                    'date' => $v->created_at,
                ]);
            });

            Livraison::where('statut_livraison', 'assigne')->latest('created_at')->limit($limit)->get()->each(function ($l) use ($items) {
                $items->push([
                    'type' => 'mission',
                    'titre' => 'Livraison acceptée',
                    'description' => 'Un livreur a pris en charge une livraison',
                    'statut' => 'assigne',
                    'date' => $l->created_at,
                ]);
            });

            return $items
                ->filter(fn ($i) => $i['date'] !== null)
                ->sortByDesc(fn ($i) => Carbon::parse($i['date']))
                ->values()
                ->take($limit);
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // GET /api/admin/dashboard/alerts
    // Panneau d'alertes temps réel : TTL court, non indexé par période (toujours "maintenant").
    public function alerts(Request $request): JsonResponse
    {
        $data = DashboardCache::remember('alerts', 60, [], function () {
            $commandesEnRetard = Commande::whereNotIn('statut_commande', ['livree', 'annulee'])
                ->whereNotNull('creneau_livraison_fin')
                ->where('creneau_livraison_fin', '<', now())
                ->count();

            $produitsRupture = Produit::where(function ($q) {
                $q->where('statut_disponibilite', '!=', 'disponible')
                    ->orWhere('quantite_stock', '<=', 0);
            })->count();

            $vendeursEnAttente = Vendeur::where('statut_validation', 'en_attente')->count();
            $livreursEnAttente = Livreur::where('statut_validation', 'en_attente')->count();
            $paiementsEnAttente = Paiement::where('statut', 'en_attente')->count();
            $litigesOuverts = Litige::where('statut', 'ouvert')->count();

            return [
                'commandes_en_retard' => $commandesEnRetard,
                'produits_rupture' => $produitsRupture,
                'vendeurs_en_attente' => $vendeursEnAttente,
                'livreurs_en_attente' => $livreursEnAttente,
                'paiements_en_attente' => $paiementsEnAttente,
                'litiges_ouverts' => $litigesOuverts,
                'total' => $commandesEnRetard + $produitsRupture + $vendeursEnAttente
                    + $livreursEnAttente + $paiementsEnAttente + $litigesOuverts,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }
}
