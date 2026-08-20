<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Beneficiaire;
use App\Models\TauxChange;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DiasporaController extends Controller
{
    // GET /api/diaspora/beneficiaires
    public function beneficiaires(Request $request): JsonResponse
    {
        $client = $request->user()->client;
        return response()->json(['success' => true, 'data' => Beneficiaire::where('client_id', $client->id)->orderBy('est_defaut', 'desc')->get()]);
    }

    // POST /api/diaspora/beneficiaires
    public function ajouterBeneficiaire(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom'            => 'required|string|max:100',
            'telephone'      => 'required|string|max:20',
            'ville'          => 'nullable|string|max:100',
            'adresse'        => 'required|string|max:300',
            'quartier'       => 'required|string|max:100',
            'relation'       => 'nullable|string|max:50',
            'coordonnees_gps'=> 'nullable|array',
            'instructions'   => 'nullable|string|max:500',
            'est_defaut'     => 'sometimes|boolean',
        ]);

        $client = $request->user()->client;
        if (!empty($validated['est_defaut'])) {
            Beneficiaire::where('client_id', $client->id)->update(['est_defaut' => false]);
        }

        $beneficiaire = Beneficiaire::create([
            'client_id'      => $client->id,
            'nom'            => $validated['nom'],
            'telephone'      => $validated['telephone'],
            'ville'          => $validated['ville'] ?? null,
            'adresse'        => $validated['adresse'],
            'quartier'       => $validated['quartier'],
            'relation'       => $validated['relation'] ?? null,
            'coordonnees_gps'=> $validated['coordonnees_gps'] ?? null,
            'instructions'   => $validated['instructions'] ?? null,
            'est_defaut'     => $validated['est_defaut'] ?? false,
            'date_ajout'     => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Bénéficiaire ajouté.', 'data' => $beneficiaire], 201);
    }

    // PUT /api/diaspora/beneficiaires/{id}
    public function modifierBeneficiaire(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'nom'            => 'sometimes|string|max:100',
            'telephone'      => 'sometimes|string|max:20',
            'ville'          => 'nullable|string|max:100',
            'adresse'        => 'sometimes|string|max:300',
            'quartier'       => 'sometimes|string|max:100',
            'relation'       => 'nullable|string|max:50',
            'coordonnees_gps'=> 'nullable|array',
            'instructions'   => 'nullable|string|max:500',
            'est_defaut'     => 'sometimes|boolean',
        ]);

        $client       = $request->user()->client;
        $beneficiaire = Beneficiaire::where('id', $id)->where('client_id', $client->id)->firstOrFail();

        if (!empty($validated['est_defaut'])) {
            Beneficiaire::where('client_id', $client->id)->where('id', '!=', $id)->update(['est_defaut' => false]);
        }

        $beneficiaire->update($validated);
        return response()->json(['success' => true, 'message' => 'Bénéficiaire mis à jour.', 'data' => $beneficiaire]);
    }

    // DELETE /api/diaspora/beneficiaires/{id}
    public function supprimerBeneficiaire(Request $request, string $id): JsonResponse
    {
        $client       = $request->user()->client;
        $beneficiaire = Beneficiaire::where('id', $id)->where('client_id', $client->id)->firstOrFail();
        $beneficiaire->delete();
        return response()->json(['success' => true, 'message' => 'Bénéficiaire supprimé.']);
    }

    // POST /api/diaspora/commander
    public function commanderPourProche(Request $request): JsonResponse
    {
        $request->merge(['type_commande' => 'diaspora']);
        return app(CommandeController::class)->valider($request);
    }

    // GET /api/diaspora/historique
    public function historique(Request $request): JsonResponse
    {
        $client = $request->user()->client;
        return response()->json(['success' => true, 'data' => Commande::where('client_id', $client->id)->where('type_commande', 'diaspora')->with(['beneficiaire', 'lignes.produit', 'paiement', 'livraison'])->orderBy('created_at', 'desc')->paginate(15)]);
    }

    // POST /api/diaspora/paiement/confirmer
    public function confirmerPaiement(Request $request): JsonResponse
    {
        $validated = $request->validate(['commande_id' => 'required|uuid|exists:commandes,id']);
        $client   = $request->user()->client;
        $commande = Commande::where('id', $validated['commande_id'])->where('client_id', $client->id)->where('type_commande', 'diaspora')->firstOrFail();

        if ($commande->paiement && $commande->paiement->estValide()) {
            return response()->json(['success' => false, 'message' => 'Déjà payée.'], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Utilisez /api/payment/* pour payer.',
            'data'    => ['commande_id' => $commande->id, 'numero_commande' => $commande->numero_commande, 'montant_total' => $commande->montant_total, 'devise' => $commande->devise_paiement, 'methodes' => ['stripe', 'paypal', 'carte_bancaire', 'mobile_money']],
        ]);
    }

    // GET /api/diaspora/suivi/{numeroCommande} — PUBLIC
    public function suiviPartage(Request $request, string $numeroCommande): JsonResponse
    {
        $commande = Commande::where('numero_commande', $numeroCommande)->where('type_commande', 'diaspora')->with(['livraison.suiviPositions', 'beneficiaire', 'lignes.produit'])->first();
        if (!$commande) return response()->json(['success' => false, 'message' => 'Commande introuvable.'], 404);

        $etapes = [
            ['code' => 'confirmee', 'label' => 'Commande confirmée', 'fait' => true],
            ['code' => 'achat_marche', 'label' => 'Achat au marché', 'fait' => in_array($commande->statut_commande, ['achat_marche','preparation','en_route','livree'])],
            ['code' => 'en_route', 'label' => 'Livreur en route', 'fait' => in_array($commande->statut_commande, ['en_route','livree'])],
            ['code' => 'livree', 'label' => 'Livré', 'fait' => $commande->statut_commande === 'livree'],
        ];

        return response()->json(['success' => true, 'data' => ['numero_commande' => $commande->numero_commande, 'statut' => $commande->statut_commande, 'statut_label' => $commande->statut_label, 'etapes' => $etapes, 'beneficiaire' => $commande->beneficiaire ? ['nom' => $commande->beneficiaire->nom, 'adresse' => $commande->beneficiaire->adresse_complete] : null, 'articles' => $commande->lignes->count(), 'derniere_position' => $commande->livraison?->derniere_position]]);
    }

    // POST /api/diaspora/convertir — PUBLIC
    public function convertirDevise(Request $request): JsonResponse
    {
        $validated = $request->validate(['montant' => 'required|numeric|min:0', 'devise_source' => 'required|in:USD,EUR,GBP,CAD', 'devise_cible' => 'sometimes|in:FCFA']);
        $devise_cible = $validated['devise_cible'] ?? 'FCFA';
        $taux = TauxChange::getTaux($validated['devise_source'], $devise_cible);

        if (!$taux) return response()->json(['success' => false, 'message' => "Taux non disponible."], 404);

        return response()->json(['success' => true, 'data' => ['montant_source' => $validated['montant'], 'devise_source' => $validated['devise_source'], 'montant_converti' => round($validated['montant'] * $taux->valeur_taux, 2), 'devise_cible' => $devise_cible, 'taux_applique' => $taux->valeur_taux, 'date_taux' => $taux->date_maj]]);
    }
}