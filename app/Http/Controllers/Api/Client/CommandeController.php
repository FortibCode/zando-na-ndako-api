<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Panier;
use App\Models\Commande;
use App\Models\LigneCommande;
use App\Models\ZoneLivraison;
use App\Models\AdresseLivraison;
use App\Models\Commission;
use App\Models\Coupon;
use App\Models\ParametrePlateforme;
use App\Models\Vendeur;
use App\Services\SmsService;
use App\Services\PushService;
use App\Services\RoutingService;
use App\Support\CodeSequenceGenerator;
use App\Support\DashboardCache;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CommandeController extends Controller
{
    // POST /api/commandes/valider
    public function valider(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'adresse_livraison'       => 'required|string|max:500',
            'adresse_livraison_id'    => 'nullable|uuid|exists:adresses_livraison,id',
            'zone_id'                 => 'required|uuid|exists:zones_livraison,id',
            'creneau_livraison_debut' => 'required|date|after:now',
            'creneau_livraison_fin'   => 'required|date|after:creneau_livraison_debut',
            'type_commande'           => 'sometimes|in:locale,diaspora',
            'beneficiaire_id'         => 'nullable|uuid|exists:beneficiaires,id',
            'coordonnees_gps'         => 'nullable|array',
            'code_promo'              => 'nullable|string|max:30',
        ]);

        $client = $request->user()->client;

        // Si un id d'adresse est fourni, on vérifie qu'elle appartient bien au client
        if (!empty($validated['adresse_livraison_id'])) {
            $adresseExiste = AdresseLivraison::where('id', $validated['adresse_livraison_id'])
                ->where('client_id', $client->id)
                ->exists();

            if (!$adresseExiste) {
                return response()->json(['success' => false, 'message' => 'Adresse de livraison invalide.'], 422);
            }
        }

        $panier = Panier::where('client_id', $client->id)
            ->where('statut', 'actif')
            ->with('lignes.produit')
            ->first();

        if (!$panier || $panier->estVide()) {
            return response()->json(['success' => false, 'message' => 'Votre panier est vide.'], 400);
        }

        foreach ($panier->lignes as $ligne) {
            if (!$ligne->produit->estDisponible()) {
                return response()->json(['success' => false, 'message' => "Le produit \"{$ligne->produit->nom_produit}\" n'est plus disponible."], 400);
            }
            if ($ligne->produit->quantite_stock < $ligne->quantite) {
                return response()->json(['success' => false, 'message' => "Stock insuffisant pour \"{$ligne->produit->nom_produit}\"."], 400);
            }
        }

        $zone         = ZoneLivraison::findOrFail($validated['zone_id']);
        $sousTotal    = $panier->sous_total;
        $vendeurId    = $panier->lignes->first()->produit->vendeur_id;
        $vendeur      = Vendeur::find($vendeurId);

        // Un vendeur "en pause" ou "fermé" ne doit plus pouvoir recevoir de nouvelle commande :
        // c'est exactement ce que l'écran mobile "Statut de la boutique" promet au vendeur
        // ("En pause : vous ne recevez pas de nouvelles commandes"). Vérifié ici (et non plus tôt
        // dans PanierController::ajouter) pour laisser le client garder des articles en panier
        // sans bloquer la navigation — seule la validation finale de la commande est refusée.
        if (!$vendeur || $vendeur->statut_boutique !== 'ouverte') {
            return response()->json([
                'success' => false,
                'message' => 'Ce vendeur ne peut pas recevoir de nouvelle commande pour le moment.',
                'error_code' => 'VENDEUR_INDISPONIBLE',
            ], 400);
        }

        // Prix de livraison sur la distance réelle de l'itinéraire (vendeur → point de livraison)
        // quand on a les deux coordonnées et un service de routage configuré ; sinon, repli sur le
        // tarif de zone habituel — jamais de commande bloquée faute de géolocalisation.
        $distanceKm = null;
        $fraisLivraison = $zone->frais_livraison;
        if ($vendeur?->coordonnees_gps && !empty($validated['coordonnees_gps'])) {
            $itineraire = app(RoutingService::class)->calculerItineraire(
                $vendeur->coordonnees_gps,
                $validated['coordonnees_gps']
            );
            if ($itineraire) {
                $distanceKm = $itineraire['distance_km'];
                $fraisLivraison = app(RoutingService::class)->calculerFrais($distanceKm);
            }
        }
        // Coupon optionnel — n'affecte en rien le calcul quand aucun code n'est fourni, pour ne
        // jamais changer le comportement du checkout existant.
        $coupon = null;
        $reduction = 0;
        if (!empty($validated['code_promo'])) {
            $coupon = Coupon::where('code', strtoupper(trim($validated['code_promo'])))->first();

            if (!$coupon || !$coupon->estActif()) {
                return response()->json(['success' => false, 'message' => 'Ce code promo est invalide ou expiré.', 'error_code' => 'INVALID_COUPON'], 400);
            }
            if ($sousTotal < $coupon->montant_minimum_commande) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce code promo nécessite un minimum d\'achat de ' . number_format((float) $coupon->montant_minimum_commande, 0, ',', ' ') . ' FCFA.',
                    'error_code' => 'COUPON_MIN_NOT_MET',
                ], 400);
            }
            if ($coupon->limite_utilisation_totale !== null) {
                $utilisationsTotales = Commande::where('coupon_id', $coupon->id)->where('statut_commande', '!=', 'annulee')->count();
                if ($utilisationsTotales >= $coupon->limite_utilisation_totale) {
                    return response()->json(['success' => false, 'message' => 'Ce code promo a atteint sa limite d\'utilisation.', 'error_code' => 'COUPON_LIMIT_REACHED'], 400);
                }
            }
            if ($coupon->limite_utilisation_par_client !== null) {
                $utilisationsClient = Commande::where('coupon_id', $coupon->id)->where('client_id', $client->id)->where('statut_commande', '!=', 'annulee')->count();
                if ($utilisationsClient >= $coupon->limite_utilisation_par_client) {
                    return response()->json(['success' => false, 'message' => 'Vous avez déjà utilisé ce code promo.', 'error_code' => 'COUPON_ALREADY_USED'], 400);
                }
            }
            $reduction = $coupon->calculerReduction($sousTotal);
        }

        $montantTotal = $sousTotal + $fraisLivraison - $reduction;

        DB::beginTransaction();
        try {
            $commande = Commande::create([
                'numero_commande'         => CodeSequenceGenerator::next('commande', 'ZN'),
                'client_id'               => $client->id,
                'vendeur_id'              => $vendeurId,
                'beneficiaire_id'         => $validated['beneficiaire_id'] ?? $panier->beneficiaire_id,
                'adresse_livraison_id'    => $validated['adresse_livraison_id'] ?? null,
                'date_commande'           => now(),
                'statut_commande'         => 'confirmee',
                'mode_attribution'        => 'auto',
                'montant_sous_total'      => $sousTotal,
                'frais_livraison'         => $fraisLivraison,
                'distance_estimee_km'     => $distanceKm,
                'montant_total'           => $montantTotal,
                'coupon_id'               => $coupon?->id,
                'montant_reduction'       => $reduction,
                'creneau_livraison_debut' => $validated['creneau_livraison_debut'],
                'creneau_livraison_fin'   => $validated['creneau_livraison_fin'],
                'adresse_livraison'       => $validated['adresse_livraison'],
                'coordonnees_gps_livraison' => $validated['coordonnees_gps'] ?? null,
                'type_commande'           => $validated['type_commande'] ?? 'locale',
                'devise_paiement'         => $request->user()->devise_preferee ?? 'FCFA',
            ]);

            foreach ($panier->lignes as $ligne) {
                LigneCommande::create([
                    'commande_id' => $commande->id,
                    'produit_id'  => $ligne->produit_id,
                    'quantite'    => $ligne->quantite,
                    'prix_unitaire' => $ligne->prix_unitaire_moment,
                    'sous_total'  => $ligne->sous_total,
                ]);
                $ligne->produit->decrement('quantite_stock', $ligne->quantite);
            }

            $tauxCommission = (float) ParametrePlateforme::valeur('taux_commission_vendeur', '10') / 100;
            Commission::create([
                'commande_id'        => $commande->id,
                'montant_commission' => $sousTotal * $tauxCommission,
                'taux_commission'    => $tauxCommission,
                'statut'             => 'en_attente',
            ]);

            $panier->vider();
            $panier->update(['statut' => 'valide']);

            DB::commit();
            DashboardCache::bump();

            if ($commande->type_commande === 'diaspora' && $commande->beneficiaire_id) {
                $commande->load('beneficiaire');
                $beneficiaire = $commande->beneficiaire;
                if ($beneficiaire && $beneficiaire->telephone) {
                    $lienSuivi = url("/api/diaspora/suivi/{$commande->numero_commande}");
                    $message = "Zando na Ndako : une commande ({$commande->numero_commande}) a été passée en votre nom et sera livrée chez vous. Suivi : {$lienSuivi}";
                    app(SmsService::class)->envoyer($beneficiaire->telephone, $message);
                }
            }

            // Notifications push : c'est ici (et non dans VendeurController, qui ne crée aucune
            // commande) que le vendeur apprend qu'une nouvelle commande vient d'arriver ; le
            // client reçoit lui la confirmation — première étape du même parcours que suivi().
            if ($vendeur?->user) {
                app(PushService::class)->envoyerAUtilisateur(
                    $vendeur->user,
                    'Nouvelle commande !',
                    "Vous avez reçu une nouvelle commande {$commande->numero_commande}.",
                    ['type' => 'nouvelle_commande', 'commande_id' => $commande->id]
                );
            }
            app(PushService::class)->envoyerAUtilisateur(
                $request->user(),
                'Commande confirmée',
                "Votre commande {$commande->numero_commande} a bien été reçue et sera bientôt traitée.",
                ['type' => 'commande', 'commande_id' => $commande->id, 'statut' => 'confirmee']
            );

            return response()->json([
                'success' => true,
                'message' => 'Commande passée avec succès.',
                'data'    => [
                    'commande_id'     => $commande->id,
                    'numero_commande' => $commande->numero_commande,
                    'montant_total'   => $commande->montant_total,
                    'devise'          => $commande->devise_paiement,
                    'statut'          => $commande->statut_commande,
                ],
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Erreur lors de la création de la commande.', 'error' => $e->getMessage()], 500);
        }
    }

    // GET /api/commandes
    public function index(Request $request): JsonResponse
    {
        $client    = $request->user()->client;
        $commandes = Commande::where('client_id', $client->id)
            ->with([
                'lignes.produit', 'paiement', 'livraison', 'vendeur', 'livreur',
                // Uniquement les notations déjà laissées PAR ce client, pour que l'app puisse
                // savoir si la commande reste "à noter" sans requête supplémentaire par commande.
                'notations' => fn ($q) => $q->where('type_notateur', 'client'),
            ])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json(['success' => true, 'data' => $commandes]);
    }

    // GET /api/commandes/{id}
    public function show(Request $request, string $id): JsonResponse
    {
        $client   = $request->user()->client;
        $commande = Commande::where('id', $id)
            ->where('client_id', $client->id)
            ->with(['lignes.produit', 'vendeur.user', 'livreur.user', 'beneficiaire', 'paiement', 'livraison.suiviPositions', 'litige'])
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $commande]);
    }

    // GET /api/commandes/{id}/suivi
    public function suivi(Request $request, string $id): JsonResponse
    {
        $client   = $request->user()->client;
        $commande = Commande::where('id', $id)
            ->where('client_id', $client->id)
            ->with(['livraison.suiviPositions', 'livreur.user'])
            ->firstOrFail();

        $etapes = [
            ['code' => 'confirmee',    'label' => 'Commande confirmée',       'fait' => true],
            ['code' => 'achat_marche', 'label' => 'Achat au marché en cours', 'fait' => in_array($commande->statut_commande, ['achat_marche','preparation','en_route','livree'])],
            ['code' => 'preparation',  'label' => 'Préparation de colis',     'fait' => in_array($commande->statut_commande, ['preparation','en_route','livree'])],
            ['code' => 'en_route',     'label' => 'Livreur en route',         'fait' => in_array($commande->statut_commande, ['en_route','livree'])],
            ['code' => 'livree',       'label' => 'Livré',                    'fait' => $commande->statut_commande === 'livree'],
        ];

        // getDernierePositionAttribute() renvoie le modèle SuiviPositionLivraison brut (colonnes
        // latitude/longitude/horodatage) — reformaté ici en {lat,lng,date_heure} pour matcher la
        // convention déjà utilisée ailleurs (coordonnees_gps_vendeur, coordonnees_gps de navigation).
        $dernierePosition = $commande->livraison?->derniere_position;

        return response()->json([
            'success' => true,
            'data'    => [
                'numero_commande'  => $commande->numero_commande,
                'statut_actuel'    => $commande->statut_commande,
                'statut_label'     => $commande->statut_label,
                'etapes'           => $etapes,
                'livreur'          => $commande->livreur ? ['nom' => $commande->livreur->user->nom_complet ?? 'En attente', 'telephone' => $commande->livreur->user->telephone ?? null] : null,
                'derniere_position' => $dernierePosition ? [
                    'lat' => (float) $dernierePosition->latitude,
                    'lng' => (float) $dernierePosition->longitude,
                    'date_heure' => $dernierePosition->horodatage,
                ] : null,
                'destination' => $commande->coordonnees_gps_livraison,
            ],
        ]);
    }

    // POST /api/commandes/{id}/annuler
    public function annuler(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['motif' => 'required|string|max:500']);
        $client   = $request->user()->client;
        $commande = Commande::where('id', $id)->where('client_id', $client->id)->firstOrFail();

        if (!$commande->peutEtreAnnulee()) {
            return response()->json(['success' => false, 'message' => 'Cette commande ne peut plus être annulée.'], 400);
        }

        DB::transaction(function () use ($commande, $validated) {
            $commande->update(['statut_commande' => 'annulee', 'motif_annulation' => $validated['motif']]);
            foreach ($commande->lignes as $ligne) {
                $ligne->produit->increment('quantite_stock', $ligne->quantite);
            }
        });
        DashboardCache::bump();

        app(PushService::class)->envoyerAUtilisateur(
            $request->user(),
            'Commande annulée',
            "Votre commande {$commande->numero_commande} a été annulée.",
            ['type' => 'commande', 'commande_id' => $commande->id, 'statut' => 'annulee']
        );

        return response()->json(['success' => true, 'message' => 'Commande annulée.']);
    }
}