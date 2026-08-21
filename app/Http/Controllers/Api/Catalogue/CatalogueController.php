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
        return response()->json(['success'=>true,'data'=>$q->paginate(20)]);
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

    public function promotions(Request $request): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>$this->excluBoutiquesFermees(Produit::with(['vendeur','promotions'])->whereHas('promotions',fn($q)=>$q->active())->where('statut_disponibilite','disponible'))->get()]);
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