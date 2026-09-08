<?php

namespace App\Http\Controllers\Api\Catalogue;

use App\Http\Controllers\Controller;
use App\Models\Categorie;
use App\Models\Produit;
use App\Models\ZoneLivraison;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class CatalogueController extends Controller
{
    public function categories(Request $request): JsonResponse
    {
        $data = Cache::remember('catalogue_categories_v1', 3600, function () {
            return Categorie::with('sousCategories')->whereNull('categorie_parente_id')->get();
        });
        return response()->json(['success' => true, 'data' => $data]);
    }

    // GET /api/zones — liste publique des zones de livraison actives (pour le checkout client)
    public function zones(Request $request): JsonResponse
    {
        $data = Cache::remember('catalogue_zones_v1', 3600, function () {
            return ZoneLivraison::where('statut_actif', true)->get();
        });
        return response()->json(['success' => true, 'data' => $data]);
    }

    // GET /api/locations — liste publique des arrondissements et quartiers par ville
    public function locations(Request $request): JsonResponse
    {
        $data = Cache::remember('catalogue_locations_v1', 43200, function () {
            return \App\Helpers\ArrondissementHelper::getLocations();
        });
        return response()->json(['success' => true, 'data' => $data]);
    }

    // Une boutique "fermée" (voir VendeurController::mettreAJourStatutBoutique) ne doit plus
    // apparaître au catalogue public : c'est ce que l'écran mobile "Statut de la boutique" promet
    // ("Fermée : votre boutique est fermée"). Une boutique "en pause" reste volontairement visible
    // ici — sa description ne promet que l'absence de nouvelles commandes (bloqué côté
    // CommandeController::valider), pas l'invisibilité au catalogue.
    private function excluBoutiquesFermees($query)
    {
        return $query->whereHas('vendeur', fn ($q) => $q->where('statut_boutique', '!=', 'fermee'));
    }

    public function produits(Request $request): JsonResponse
    {
        $q = $this->excluBoutiquesFermees(Produit::with(['vendeur','categorie','promotions'])->where('statut_disponibilite','disponible'));
        if ($c = $request->get('categorie')) $q->where('categorie_id', $c);
        if ($s = $request->get('search')) $q->where('nom_produit','like',"%{$s}%");
        if ($min = $request->get('prix_min')) $q->where('prix_unitaire','>=',$min);
        if ($max = $request->get('prix_max')) $q->where('prix_unitaire','<=',$max);
        // per_page ajustable par l'appelant (ex: 5 pour la vitrine "Tous nos produits" de l'accueil
        // client, web comme mobile) — borné pour éviter un appel abusif, 20 par défaut inchangé.
        $perPage = min(max((int) $request->get('per_page', 20), 1), 50);
        return response()->json(['success'=>true,'data'=>$q->paginate($perPage)]);
    }

    public function populaires(Request $request): JsonResponse
    {
        $data = Cache::remember('catalogue_populaires_v1', 600, function () {
            return $this->excluBoutiquesFermees(Produit::with('vendeur')->where('statut_disponibilite','disponible'))->orderBy('created_at','desc')->take(10)->get();
        });
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function recents(Request $request): JsonResponse
    {
        $data = Cache::remember('catalogue_recents_v1', 300, function () {
            return $this->excluBoutiquesFermees(Produit::with('vendeur')->where('statut_disponibilite','disponible'))->orderBy('created_at','desc')->take(10)->get();
        });
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function promotions(Request $request): JsonResponse
    {
        $data = Cache::remember('catalogue_promotions_v1', 300, function () {
            $vendeurIdsBoutique = \App\Models\PromotionVendeur::whereNull('produit_id')->active()->pluck('vendeur_id');
            $produitIdsVendeur = \App\Models\PromotionVendeur::whereNotNull('produit_id')->active()->pluck('produit_id');

            $produits = $this->excluBoutiquesFermees(
                Produit::with(['vendeur', 'promotions.promotion'])
                    ->where('statut_disponibilite', 'disponible')
                    ->where(function ($query) use ($vendeurIdsBoutique, $produitIdsVendeur) {
                        $query->whereHas('promotions', fn ($q) => $q->active())
                            ->orWhereIn('vendeur_id', $vendeurIdsBoutique)
                            ->orWhereIn('id', $produitIdsVendeur);
                    })
            )->get();

            $promosVendeurParProduit = \App\Models\PromotionVendeur::whereIn('produit_id', $produits->pluck('id'))->active()->get()->groupBy('produit_id');
            $promosVendeurParBoutique = \App\Models\PromotionVendeur::whereNull('produit_id')->whereIn('vendeur_id', $produits->pluck('vendeur_id')->unique())->active()->get()->groupBy('vendeur_id');

            return $produits->map(function ($p) use ($promosVendeurParProduit, $promosVendeurParBoutique) {
                $admin = $p->promotions
                    ->filter(fn ($pp) => $pp->promotion && $pp->promotion->estActive())
                    ->map(fn ($pp) => [
                        'id' => $pp->promotion->id, 'titre' => $pp->promotion->titre,
                        'type_reduction' => $pp->promotion->type_reduction, 'valeur_reduction' => $pp->promotion->valeur_reduction,
                    ]);
                $vendeur = ($promosVendeurParProduit->get($p->id, collect()))
                    ->concat($promosVendeurParBoutique->get($p->vendeur_id, collect()))
                    ->map(fn ($v) => ['id' => $v->id, 'titre' => $v->titre, 'type_reduction' => $v->type_reduction, 'valeur_reduction' => $v->valeur_reduction]);

                $arr = $p->toArray();
                $arr['promotions'] = $admin->concat($vendeur)->values();
                return $arr;
            })->filter(fn ($arr) => count($arr['promotions']) > 0)->values();
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function search(Request $request): JsonResponse
    {
        $s = trim($request->validate(['q' => 'required|string|max:100'])['q']);
        if (strlen($s) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $cacheKey = 'catalogue_search_' . md5(mb_strtolower($s));
        $data = Cache::remember($cacheKey, 60, function () use ($s) {
            // Produits disponibles correspondant à la recherche avec relations légères vendeur.zone et promotions
            $produits = $this->excluBoutiquesFermees(
                Produit::with(['vendeur.zone:id,ville', 'categorie:id,nom_categorie', 'promotions.promotion'])
                    ->where('statut_disponibilite', 'disponible')
                    ->where('nom_produit', 'like', "%{$s}%")
                    ->take(50)
            )->get();

            // Regroupement par nom de produit (insensible à la casse)
            $grouped = $produits->groupBy(function ($item) {
                return mb_strtolower(trim($item->nom_produit));
            });

            $results = [];
            foreach ($grouped as $nomNormalise => $items) {
                // Trier les offres par prix effectif croissant (compte tenu des promotions actives)
                $sortedItems = $items->sortBy(function ($item) {
                    return (float) $item->prix_avec_promotion;
                })->values();

                $mainProduit = $sortedItems->first();

                $offresVendeurs = $sortedItems->map(function ($item) {
                    $v = $item->vendeur;
                    return [
                        'produit_id' => $item->id,
                        'vendeur_id' => $v?->id,
                        'nom_commerce' => $v?->nom_commerce,
                        'photo_boutique' => $v?->photo_boutique,
                        'note_moyenne' => (float) ($v?->note_moyenne ?? 0),
                        'ville' => $v?->zone?->ville,
                        'prix_unitaire' => (float) $item->prix_unitaire,
                        'prix_effectif' => (float) $item->prix_avec_promotion,
                        'est_en_promotion' => (bool) $item->est_en_promotion,
                        'quantite_stock' => $item->quantite_stock,
                        'unite_mesure' => $item->unite_mesure,
                        'photo_produit' => $item->photo_produit,
                    ];
                })->values();

                $prixMin = (float) $offresVendeurs->min('prix_effectif');
                $prixMax = (float) $offresVendeurs->max('prix_effectif');

                $mainArr = $mainProduit->toArray();
                $mainArr['prix_min'] = $prixMin;
                $mainArr['prix_max'] = $prixMax;
                $mainArr['nombre_boutiques'] = $offresVendeurs->count();
                $mainArr['offres_vendeurs'] = $offresVendeurs;

                $results[] = $mainArr;
            }

            return array_slice($results, 0, 20);
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function produitDetail(Request $request, string $id): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>Produit::with(['vendeur.user','categorie','promotions'])->findOrFail($id)]);
    }

    public function produitsVendeur(Request $request, string $vendeurId): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>$this->excluBoutiquesFermees(Produit::where('vendeur_id',$vendeurId)->where('statut_disponibilite','disponible'))->with('categorie')->get()]);
    }

    // GET /api/vendeurs/top — public : meilleurs vendeurs actifs, pour la vitrine de la page d'accueil.
    // Seuls les vendeurs validés, non fermés et ayant reçu au moins une note apparaissent (jamais de
    // partenaire fictif ni de note inventée).
    public function vendeursTop(Request $request): JsonResponse
    {
        $vendeurs = Cache::remember('catalogue_vendeurs_top_v1', 900, function () {
            return \App\Models\Vendeur::with('zone')
                ->where('statut_validation', 'valide')
                ->where('statut_boutique', '!=', 'fermee')
                ->where('note_moyenne', '>', 0)
                ->orderByDesc('note_moyenne')
                ->take(8)
                ->get()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'nom_commerce' => $v->nom_commerce,
                    'categorie_principale' => $v->categorie_principale,
                    'note_moyenne' => (float) $v->note_moyenne,
                    'ville' => $v->zone?->ville,
                    'arrondissement' => $v->arrondissement,
                    'quartier' => $v->quartier_custom ?: $v->quartier,
                    'photo_boutique' => $v->photo_boutique,
                ]);
        });

        return response()->json(['success' => true, 'data' => $vendeurs]);
    }

    // GET /api/vendeurs/types — types de boutique réellement utilisés
    public function vendeurTypes(Request $request): JsonResponse
    {
        $types = Cache::remember('catalogue_vendeur_types_v1', 3600, function () {
            return \App\Models\Vendeur::where('statut_validation', 'valide')
                ->where('statut_boutique', '!=', 'fermee')
                ->whereNotNull('categorie_principale')
                ->distinct()
                ->orderBy('categorie_principale')
                ->pluck('categorie_principale');
        });

        return response()->json(['success' => true, 'data' => $types]);
    }

    public function vendeurTypesDisponibles(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => \App\Models\TypeBoutique::libellesValides()]);
    }

    public function vendeurTypesAvecLogos(Request $request): JsonResponse
    {
        $data = Cache::remember('catalogue_vendeur_types_logos_v1', 3600, function () {
            return \App\Models\TypeBoutique::orderBy('type')->get(['type', 'logo']);
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function vendeurs(Request $request): JsonResponse
    {
        $q = \App\Models\Vendeur::with('zone')
            ->where('statut_validation', 'valide')
            ->where('statut_boutique', '!=', 'fermee');

        if ($type = $request->get('type')) $q->where('categorie_principale', $type);
        if ($arr = $request->get('arrondissement')) $q->where('arrondissement', $arr);
        if ($s = $request->get('search')) $q->where('nom_commerce', 'like', "%{$s}%");

        $vendeurs = $q->orderByRaw("CASE WHEN formule_abonnement = 'vip' THEN 1 WHEN formule_abonnement = 'pro' THEN 2 ELSE 3 END")
            ->orderByDesc('note_moyenne')
            ->paginate(20);

        $vendeurs->getCollection()->transform(fn ($v) => [
            'id' => $v->id,
            'nom_commerce' => $v->nom_commerce,
            'categorie_principale' => $v->categorie_principale,
            'note_moyenne' => (float) $v->note_moyenne,
            'ville' => $v->zone?->ville,
            'arrondissement' => $v->arrondissement,
            'quartier' => $v->quartier_custom ?: $v->quartier,
            'photo_boutique' => $v->photo_boutique,
            'formule_abonnement' => $v->formule_abonnement,
            'badge_vendeur' => $v->badge_vendeur,
        ]);

        return response()->json(['success' => true, 'data' => $vendeurs]);
    }

    // GET /api/vendeurs/{id} — profil public complet d'une boutique (en-tête de la fiche boutique
    // côté client). N'exclut pas les boutiques fermées : un lien déjà partagé/en historique doit
    // toujours résoudre, le frontend affiche un bandeau "boutique fermée" via statut_boutique.
    public function vendeurDetail(Request $request, string $id): JsonResponse
    {
        $v = \App\Models\Vendeur::with('zone')->where('statut_validation', 'valide')->findOrFail($id);

        return response()->json(['success' => true, 'data' => [
            'id' => $v->id,
            'nom_commerce' => $v->nom_commerce,
            'categorie_principale' => $v->categorie_principale,
            'note_moyenne' => (float) $v->note_moyenne,
            'ville' => $v->zone?->ville,
            'arrondissement' => $v->arrondissement,
            'quartier' => $v->quartier_custom ?: $v->quartier,
            'photo_boutique' => $v->photo_boutique,
            'horaires_ouverture' => $v->horaires_ouverture,
            'message_boutique' => $v->message_boutique,
            'statut_boutique' => $v->statut_boutique,
            'formule_abonnement' => $v->formule_abonnement,
            'badge_vendeur' => $v->badge_vendeur,
        ]]);
    }

    // GET /api/avis/publics — public : derniers avis clients commentés (tous vendeurs confondus),
    // pour la vitrine de la page d'accueil. Jamais de témoignage inventé.
    public function avisPublics(Request $request): JsonResponse
    {
        $avisList = \App\Models\NotationAvis::where('type_cible', 'vendeur')
            ->whereNotNull('commentaire')
            ->where('commentaire', '!=', '')
            ->orderByDesc('date_notation')
            ->take(10)
            ->get();

        $clientIds = $avisList->pluck('notateur_id')->filter()->unique();
        $clients = \App\Models\Client::with('user:id,nom,prenom,photo_profil,ville,pays_residence')
            ->whereIn('id', $clientIds)
            ->get()
            ->keyBy('id');

        $avis = $avisList->map(function ($a) use ($clients) {
                $client = $clients->get($a->notateur_id);
                if (!$client?->user) return null;
                return [
                    'id' => $a->id,
                    'note' => $a->note,
                    'commentaire' => $a->commentaire,
                    'date_notation' => $a->date_notation,
                    'client' => [
                        'nom' => $client->user->nom_complet,
                        'photo' => $client->user->photo_profil,
                        'ville' => $client->user->ville ?: $client->user->pays_residence,
                    ],
                ];
            })
            ->filter()
            ->values();

        return response()->json(['success' => true, 'data' => $avis]);
    }

    // GET /api/vendeurs/{id}/avis — public : avis clients d'un vendeur, consultables avant de commander.
    public function avisVendeur(Request $request, string $vendeurId): JsonResponse
    {
        $vendeur = \App\Models\Vendeur::findOrFail($vendeurId);
        $avisList = \App\Models\NotationAvis::where('cible_id', $vendeur->id)
            ->where('type_cible', 'vendeur')
            ->whereNotNull('commentaire')
            ->orderBy('date_notation', 'desc')
            ->get();

        $clientIds = $avisList->pluck('notateur_id')->filter()->unique();
        $clients = \App\Models\Client::with('user:id,nom,prenom,photo_profil')
            ->whereIn('id', $clientIds)
            ->get()
            ->keyBy('id');

        $avis = $avisList->map(function ($a) use ($clients) {
                $client = $clients->get($a->notateur_id);
                return [
                    'note' => $a->note, 'commentaire' => $a->commentaire, 'date_notation' => $a->date_notation,
                    'client' => $client?->user ? ['nom' => $client->user->nom_complet, 'photo' => $client->user->photo_profil] : null,
                ];
            });

        return response()->json(['success' => true, 'data' => [
            'note_moyenne' => (float) $vendeur->note_moyenne,
            'avis' => $avis,
        ]]);
    }

    // GET /api/bannieres — Liste des bannières publicitaires actives pour le carrousel mobile
    public function bannieres(Request $request): JsonResponse
    {
        $bannieres = Cache::remember('catalogue_bannieres_actives_v1', 900, function () {
            return \App\Models\BannierePublicitaire::actives()
                // `logo` n'existe pas sur vendeurs (c'est une colonne de types_boutique) : la
                // sélectionner faisait échouer la requête, donc GET /api/bannieres répondait 500.
                // Seul nom_commerce est affiché (badge du carrousel publicitaire côté client).
                ->with(['vendeur:id,nom_commerce'])
                ->get();
        });

        // Incrémenter les vues après l'envoi de la réponse HTTP au client (non bloquant)
        if ($bannieres->isNotEmpty()) {
            $ids = $bannieres->pluck('id')->toArray();
            register_shutdown_function(function () use ($ids) {
                try {
                    \App\Models\BannierePublicitaire::whereIn('id', $ids)->increment('nombre_vues');
                } catch (\Throwable $e) { /* ignore */ }
            });
        }

        return response()->json([
            'success' => true,
            'data'    => $bannieres,
        ]);
    }

    // POST /api/bannieres/{id}/clic — Enregistrer un clic sur une bannière publicitaire
    public function enregistrerClicBanniere(string $id): JsonResponse
    {
        $banniere = \App\Models\BannierePublicitaire::find($id);
        if ($banniere) {
            $banniere->increment('nombre_clics');
        }

        return response()->json(['success' => true]);
    }
}