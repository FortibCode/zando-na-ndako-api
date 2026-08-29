<?php

namespace App\Http\Controllers\Api\Catalogue;

use App\Http\Controllers\Controller;
use App\Models\Categorie;
use App\Models\Produit;
use App\Models\ZoneLivraison;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CatalogueController extends Controller
{
    public function categories(Request $request): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>Categorie::with('sousCategories')->whereNull('categorie_parente_id')->get()]);
    }

    // GET /api/zones — liste publique des zones de livraison actives (pour le checkout client)
    public function zones(Request $request): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>ZoneLivraison::where('statut_actif',true)->get()]);
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
        // Pas de métrique de popularité par produit en base ; on retombe sur les plus récents.
        return response()->json(['success'=>true,'data'=>$this->excluBoutiquesFermees(Produit::with('vendeur')->where('statut_disponibilite','disponible'))->orderBy('created_at','desc')->take(10)->get()]);
    }

    public function recents(Request $request): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>$this->excluBoutiquesFermees(Produit::with('vendeur')->where('statut_disponibilite','disponible'))->orderBy('created_at','desc')->take(10)->get()]);
    }

    // GET /api/produits/promotions — liste publique des produits en promotion, pour l'écran
    // client "Promotions" (mobile) et la page /promotions du site web. Combine deux sources bien
    // distinctes : les bannières marketing créées par l'administrateur (App\Models\Promotion via
    // promotion_produits — inchangé) ET les promotions self-service créées par les vendeurs
    // eux-mêmes (App\Models\PromotionVendeur — voir VendeurController::creerPromotionVendeur()).
    // Avant ce changement seule la première source existait : la promesse faite par l'écran
    // ("les réductions mises en place par nos vendeurs s'affichent ici automatiquement") était
    // fausse tant qu'aucun vendeur ne pouvait réellement créer de promotion. Chaque entrée du
    // tableau `promotions` renvoyé par produit est aplatie en {id, titre, type_reduction,
    // valeur_reduction} — au passage, corrige aussi le fait que le sérialisé brut de la relation
    // `promotions` (PromotionProduit) ne portait que les colonnes de la table pivot, jamais le
    // titre/type/valeur attendus par mobile/web (discountLabel()).
    public function promotions(Request $request): JsonResponse
    {
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

        $data = $produits->map(function ($p) use ($promosVendeurParProduit, $promosVendeurParBoutique) {
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

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function search(Request $request): JsonResponse
    {
        $s = $request->validate(['q'=>'required|string|max:100'])['q'];
        return response()->json(['success'=>true,'data'=>$this->excluBoutiquesFermees(Produit::with('vendeur')->where('statut_disponibilite','disponible'))->where('nom_produit','like',"%{$s}%")->take(20)->get()]);
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
        $vendeurs = \App\Models\Vendeur::with('zone')
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
                'photo_boutique' => $v->photo_boutique,
            ]);

        return response()->json(['success' => true, 'data' => $vendeurs]);
    }

    // GET /api/vendeurs/types — types de boutique réellement utilisés (categorie_principale est un
    // simple champ texte libre, pas une FK/enum) : sert à construire l'écran "types de boutique" côté
    // client sans coder en dur une liste qui pourrait ne pas correspondre aux vraies boutiques.
    public function vendeurTypes(Request $request): JsonResponse
    {
        $types = \App\Models\Vendeur::where('statut_validation', 'valide')
            ->where('statut_boutique', '!=', 'fermee')
            ->whereNotNull('categorie_principale')
            ->distinct()
            ->orderBy('categorie_principale')
            ->pluck('categorie_principale');

        return response()->json(['success' => true, 'data' => $types]);
    }

    // GET /api/vendeurs/types-disponibles — la liste COMPLÈTE des types de boutique autorisés
    // (App\Models\TypeBoutique, gérée par un admin via /admin/types-boutique), pas seulement ceux
    // déjà utilisés par un vrai vendeur (contrairement à vendeurTypes() ci-dessus) : sert aux
    // formulaires d'inscription/profil vendeur, où un premier vendeur d'un type donné doit pouvoir
    // le choisir avant qu'aucun autre vendeur de ce type n'existe encore.
    public function vendeurTypesDisponibles(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => \App\Models\TypeBoutique::libellesValides()]);
    }

    // GET /api/vendeurs/types-logos — la liste COMPLÈTE des types de boutique gérés par l'admin
    // (App\Models\TypeBoutique, voir /admin/types-boutique), avec leur logo s'il existe. Affiche
    // volontairement aussi les types sans aucun vendeur pour l'instant (contrairement à
    // vendeurTypes() ci-dessus, qui ne liste que ceux réellement en usage) : la page qui liste les
    // boutiques d'un type gère déjà proprement le cas "aucune boutique pour l'instant", donc un type
    // vide reste visible plutôt que de disparaître complètement de l'accueil. `logo` vaut null tant
    // qu'aucun admin n'a rien envoyé pour ce type — le frontend retombe alors sur une icône
    // générique, jamais une image inventée.
    public function vendeurTypesAvecLogos(Request $request): JsonResponse
    {
        $data = \App\Models\TypeBoutique::orderBy('type')->get(['type', 'logo']);

        return response()->json(['success' => true, 'data' => $data]);
    }

    // GET /api/vendeurs — liste publique paginée des boutiques, pour l'écran client "boutiques d'un
    // type" (remplace le parcours plat par catégorie de produit). Mêmes champs/exclusions que
    // vendeursTop(), avec filtres réels au lieu du top-8 fixe.
    public function vendeurs(Request $request): JsonResponse
    {
        $q = \App\Models\Vendeur::with('zone')
            ->where('statut_validation', 'valide')
            ->where('statut_boutique', '!=', 'fermee');

        if ($type = $request->get('type')) $q->where('categorie_principale', $type);
        if ($s = $request->get('search')) $q->where('nom_commerce', 'like', "%{$s}%");

        $vendeurs = $q->orderByDesc('note_moyenne')->paginate(20);
        $vendeurs->getCollection()->transform(fn ($v) => [
            'id' => $v->id,
            'nom_commerce' => $v->nom_commerce,
            'categorie_principale' => $v->categorie_principale,
            'note_moyenne' => (float) $v->note_moyenne,
            'ville' => $v->zone?->ville,
            'photo_boutique' => $v->photo_boutique,
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
            'photo_boutique' => $v->photo_boutique,
            'horaires_ouverture' => $v->horaires_ouverture,
            'message_boutique' => $v->message_boutique,
            'statut_boutique' => $v->statut_boutique,
        ]]);
    }

    // GET /api/avis/publics — public : derniers avis clients commentés (tous vendeurs confondus),
    // pour la vitrine de la page d'accueil. Jamais de témoignage inventé.
    public function avisPublics(Request $request): JsonResponse
    {
        $avis = \App\Models\NotationAvis::where('type_cible', 'vendeur')
            ->whereNotNull('commentaire')
            ->where('commentaire', '!=', '')
            ->orderByDesc('date_notation')
            ->take(10)
            ->get()
            ->map(function ($a) {
                $client = \App\Models\Client::with('user:id,nom,prenom,photo_profil,ville,pays_residence')->find($a->notateur_id);
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
        $avis = \App\Models\NotationAvis::where('cible_id', $vendeur->id)
            ->where('type_cible', 'vendeur')
            ->whereNotNull('commentaire')
            ->orderBy('date_notation', 'desc')
            ->get()
            ->map(function ($a) {
                $client = \App\Models\Client::with('user:id,nom,prenom,photo_profil')->find($a->notateur_id);
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
}