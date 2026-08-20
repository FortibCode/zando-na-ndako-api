<?php

namespace App\Http\Controllers\Api\Commande;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\NotationAvis;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Notation/avis liés à une commande livrée : le client peut noter le vendeur et/ou le livreur, et
 * symétriquement le vendeur ou le livreur peuvent noter le client. Une seule notation par direction
 * et par commande (contrainte notations_avis_direction_unique).
 */
class NotationController extends Controller
{
    private function chargerCommande(string $commandeId): Commande
    {
        return Commande::with(['client.user', 'vendeur.user', 'livreur.user'])->findOrFail($commandeId);
    }

    // Détermine le rôle (type_notateur) et l'id métier (notateur_id) de l'utilisateur connecté
    // au sein de cette commande précise, ou null s'il n'y participe pas.
    private function roleDansCommande(Commande $commande, User $user): ?array
    {
        if ($commande->client && $commande->client->user_id === $user->id) {
            return ['type' => 'client', 'id' => $commande->client_id];
        }
        if ($commande->vendeur && $commande->vendeur->user_id === $user->id) {
            return ['type' => 'vendeur', 'id' => $commande->vendeur_id];
        }
        if ($commande->livreur && $commande->livreur->user_id === $user->id) {
            return ['type' => 'livreur', 'id' => $commande->livreur_id];
        }
        return null;
    }

    // GET /api/commandes/{id}/notations — notations déjà soumises pour cette commande, pour que
    // l'écran sache lesquelles restent à faire (un participant ne voit que les commandes où il figure).
    public function index(Request $request, string $commandeId): JsonResponse
    {
        $commande = $this->chargerCommande($commandeId);
        $role = $this->roleDansCommande($commande, $request->user());

        if (!$role) {
            return response()->json(['success' => false, 'message' => "Vous ne participez pas à cette commande."], 403);
        }

        $notations = NotationAvis::where('commande_id', $commande->id)->get();

        return response()->json(['success' => true, 'data' => $notations]);
    }

    // POST /api/commandes/{id}/notation
    public function store(Request $request, string $commandeId): JsonResponse
    {
        $commande = $this->chargerCommande($commandeId);
        $role = $this->roleDansCommande($commande, $request->user());

        if (!$role) {
            return response()->json(['success' => false, 'message' => "Vous ne participez pas à cette commande."], 403);
        }

        if ($commande->statut_commande !== 'livree') {
            return response()->json(['success' => false, 'message' => "La commande doit être livrée avant de pouvoir être notée."], 400);
        }

        $validated = $request->validate([
            'note' => 'required|integer|between:1,5',
            'commentaire' => 'nullable|string|max:1000',
            // Uniquement pertinent quand c'est le client qui note : il doit choisir sa cible
            // (vendeur ou livreur) — validé manuellement ci-dessous, ça dépend du rôle du notateur.
            'cible' => 'nullable|in:vendeur,livreur',
        ]);

        if ($role['type'] === 'client') {
            $cibleType = $request->input('cible');
            if (!in_array($cibleType, ['vendeur', 'livreur'], true)) {
                return response()->json(['success' => false, 'message' => "Précisez si vous notez le vendeur ou le livreur (paramètre cible)."], 422);
            }
            $cibleId = $cibleType === 'vendeur' ? $commande->vendeur_id : $commande->livreur_id;
        } else {
            // Le vendeur ou le livreur ne peuvent noter que le client de la commande.
            $cibleType = 'client';
            $cibleId = $commande->client_id;
        }

        if (!$cibleId) {
            return response()->json(['success' => false, 'message' => "Aucun " . $cibleType . " n'est associé à cette commande."], 422);
        }

        $dejaNote = NotationAvis::where('commande_id', $commande->id)
            ->where('type_notateur', $role['type'])
            ->where('type_cible', $cibleType)
            ->exists();

        if ($dejaNote) {
            return response()->json(['success' => false, 'message' => "Vous avez déjà noté cette commande."], 409);
        }

        $notation = DB::transaction(function () use ($commande, $role, $cibleType, $cibleId, $validated) {
            $notation = NotationAvis::create([
                'commande_id' => $commande->id,
                'notateur_id' => $role['id'],
                'type_notateur' => $role['type'],
                'cible_id' => $cibleId,
                'type_cible' => $cibleType,
                'note' => $validated['note'],
                'commentaire' => $validated['commentaire'] ?? null,
                'date_notation' => now(),
            ]);

            NotationAvis::recalculerMoyenne($cibleId, $cibleType);

            return $notation;
        });

        return response()->json(['success' => true, 'message' => 'Merci pour votre avis !', 'data' => $notation], 201);
    }
}
