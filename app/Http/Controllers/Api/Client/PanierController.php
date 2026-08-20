<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Panier;
use App\Models\Produit;
use App\Models\LignePanier;
use App\Models\Beneficiaire;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PanierController extends Controller
{
    private function getPanierActif(Request $request): Panier
    {
        $client = $request->user()->client;
        return Panier::firstOrCreate(
            ['client_id' => $client->id, 'statut' => 'actif'],
            ['date_creation' => now(), 'date_maj' => now()]
        );
    }

    public function index(Request $request): JsonResponse
    {
        $panier = $this->getPanierActif($request);
        $panier->load(['lignes.produit.vendeur', 'beneficiaire']);
        return response()->json([
            'success' => true,
            'data'    => [
                'id'              => $panier->id,
                'statut'          => $panier->statut,
                'beneficiaire'    => $panier->beneficiaire,
                'lien_partage'    => $panier->lien_partage_panier,
                'lignes'          => $panier->lignes->map(fn($l) => [
                    'id'                  => $l->id,
                    'produit_id'          => $l->produit_id,
                    'nom_produit'         => $l->produit->nom_produit,
                    'photo'               => $l->produit->photo_produit,
                    'vendeur'             => $l->produit->vendeur->nom_commerce ?? null,
                    'quantite'            => $l->quantite,
                    'prix_unitaire'       => $l->prix_unitaire_moment,
                    'sous_total'          => $l->sous_total,
                    'unite'               => $l->produit->unite_mesure,
                    'disponible'          => $l->produit->estDisponible(),
                ]),
                'sous_total'      => $panier->sous_total,
                'nombre_articles' => $panier->nombre_articles,
            ],
        ]);
    }

    public function ajouter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'produit_id' => 'required|uuid|exists:produits,id',
            'quantite'   => 'required|integer|min:1|max:100',
        ]);
        $produit = Produit::findOrFail($validated['produit_id']);
        if (!$produit->estDisponible()) {
            return response()->json(['success' => false, 'message' => 'Ce produit n\'est pas disponible.'], 400);
        }
        if ($produit->quantite_stock < $validated['quantite']) {
            return response()->json(['success' => false, 'message' => "Stock insuffisant. Disponible: {$produit->quantite_stock}."], 400);
        }
        $panier = $this->getPanierActif($request);
        $ligne = LignePanier::where('panier_id', $panier->id)->where('produit_id', $produit->id)->first();
        if ($ligne) {
            $ligne->increment('quantite', $validated['quantite']);
            $ligne->update(['prix_unitaire_moment' => $produit->prix_avec_promotion]);
        } else {
            LignePanier::create(['panier_id' => $panier->id, 'produit_id' => $produit->id, 'quantite' => $validated['quantite'], 'prix_unitaire_moment' => $produit->prix_avec_promotion]);
        }
        $panier->update(['date_maj' => now()]);
        return response()->json(['success' => true, 'message' => 'Produit ajouté au panier.', 'data' => ['sous_total' => $panier->fresh()->sous_total]]);
    }

    public function modifier(Request $request, string $ligneId): JsonResponse
    {
        $validated = $request->validate(['quantite' => 'required|integer|min:1|max:100']);
        $panier = $this->getPanierActif($request);
        $ligne = LignePanier::where('id', $ligneId)->where('panier_id', $panier->id)->firstOrFail();
        $produit = $ligne->produit;
        if ($produit->quantite_stock < $validated['quantite']) {
            return response()->json(['success' => false, 'message' => "Stock insuffisant. Disponible: {$produit->quantite_stock}."], 400);
        }
        $ligne->update(['quantite' => $validated['quantite'], 'prix_unitaire_moment' => $produit->prix_avec_promotion]);
        return response()->json(['success' => true, 'message' => 'Quantité mise à jour.', 'sous_total_ligne' => $ligne->fresh()->sous_total]);
    }

    public function supprimer(Request $request, string $ligneId): JsonResponse
    {
        $panier = $this->getPanierActif($request);
        LignePanier::where('id', $ligneId)->where('panier_id', $panier->id)->firstOrFail()->delete();
        return response()->json(['success' => true, 'message' => 'Article retiré du panier.']);
    }

    public function vider(Request $request): JsonResponse
    {
        $this->getPanierActif($request)->vider();
        return response()->json(['success' => true, 'message' => 'Panier vidé.']);
    }

    public function partager(Request $request): JsonResponse
    {
        $panier = $this->getPanierActif($request);
        if ($panier->estVide()) {
            return response()->json(['success' => false, 'message' => 'Impossible de partager un panier vide.'], 400);
        }
        $lien = $panier->genererLienPartage();
        return response()->json(['success' => true, 'message' => 'Lien de partage généré.', 'lien' => url("/api/panier/partage/{$lien}"), 'token' => $lien]);
    }

    // GET /api/panier/partage/{lien} — route publique, sans authentification :
    // consultée par le bénéficiaire qui reçoit le lien de partage, souvent sans compte.
    public function voirPartage(string $lien): JsonResponse
    {
        $panier = Panier::where('lien_partage_panier', $lien)->first();

        if (!$panier) {
            return response()->json(['success' => false, 'message' => 'Ce lien de panier est invalide ou a expiré.'], 404);
        }

        $panier->load(['lignes.produit', 'beneficiaire', 'client.user']);

        return response()->json([
            'success' => true,
            'data' => [
                'expediteur'      => $panier->client?->user?->prenom,
                'beneficiaire'    => $panier->beneficiaire?->nom,
                'lignes'          => $panier->lignes->map(fn ($l) => [
                    'nom_produit'   => $l->produit->nom_produit,
                    'photo'         => $l->produit->photo_produit,
                    'quantite'      => $l->quantite,
                    'prix_unitaire' => $l->prix_unitaire_moment,
                    'sous_total'    => $l->sous_total,
                    'unite'         => $l->produit->unite_mesure,
                ]),
                'sous_total'      => $panier->sous_total,
                'nombre_articles' => $panier->nombre_articles,
            ],
        ]);
    }

    public function assignerBeneficiaire(Request $request, string $beneficiaireId): JsonResponse
    {
        $beneficiaire = Beneficiaire::where('id', $beneficiaireId)->where('client_id', $request->user()->client->id)->firstOrFail();
        $panier = $this->getPanierActif($request);
        $panier->update(['beneficiaire_id' => $beneficiaire->id]);
        return response()->json(['success' => true, 'message' => "Bénéficiaire {$beneficiaire->nom} assigné.", 'beneficiaire' => $beneficiaire]);
    }

    public function retirerBeneficiaire(Request $request): JsonResponse
    {
        $this->getPanierActif($request)->update(['beneficiaire_id' => null]);
        return response()->json(['success' => true, 'message' => 'Bénéficiaire retiré du panier.']);
    }
}