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

    public function produits(Request $request): JsonResponse
    {
        $q = Produit::with(['vendeur','categorie','promotions'])->where('statut_disponibilite','disponible');
        if ($c = $request->get('categorie')) $q->where('categorie_id', $c);
        if ($s = $request->get('search')) $q->where('nom_produit','like',"%{$s}%");
        if ($min = $request->get('prix_min')) $q->where('prix_unitaire','>=',$min);
        if ($max = $request->get('prix_max')) $q->where('prix_unitaire','<=',$max);
        return response()->json(['success'=>true,'data'=>$q->paginate(20)]);
    }

    public function populaires(Request $request): JsonResponse
    {
        // Pas de métrique de popularité par produit en base ; on retombe sur les plus récents.
        return response()->json(['success'=>true,'data'=>Produit::with('vendeur')->where('statut_disponibilite','disponible')->orderBy('created_at','desc')->take(10)->get()]);
    }

    public function recents(Request $request): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>Produit::with('vendeur')->where('statut_disponibilite','disponible')->orderBy('created_at','desc')->take(10)->get()]);
    }

    public function promotions(Request $request): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>Produit::with(['vendeur','promotions'])->whereHas('promotions',fn($q)=>$q->active())->where('statut_disponibilite','disponible')->get()]);
    }

    public function search(Request $request): JsonResponse
    {
        $s = $request->validate(['q'=>'required|string|max:100'])['q'];
        return response()->json(['success'=>true,'data'=>Produit::with('vendeur')->where('statut_disponibilite','disponible')->where('nom_produit','like',"%{$s}%")->take(20)->get()]);
    }

    public function produitDetail(Request $request, string $id): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>Produit::with(['vendeur.user','categorie','promotions'])->findOrFail($id)]);
    }

    public function produitsVendeur(Request $request, string $vendeurId): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>Produit::where('vendeur_id',$vendeurId)->where('statut_disponibilite','disponible')->with('categorie')->get()]);
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