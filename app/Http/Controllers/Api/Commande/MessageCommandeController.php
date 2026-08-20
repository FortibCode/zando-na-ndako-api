<?php

namespace App\Http\Controllers\Api\Commande;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\MessageCommande;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Messagerie liée à une commande précise : un seul fil, partagé par le client, le vendeur et le
 * livreur qui y participent. Chaque message porte son expediteur_user_id, ce qui permet à chaque
 * écran (client, vendeur, livreur) d'aligner ses propres messages à droite et ceux des autres
 * participants à gauche.
 */
class MessageCommandeController extends Controller
{
    // Charge la commande avec les relations nécessaires pour identifier ses trois participants
    // possibles (client, vendeur, livreur) et pour vérifier qu'un utilisateur en fait bien partie.
    private function chargerCommande(string $commandeId): Commande
    {
        return Commande::with(['client.user', 'vendeur.user', 'livreur.user'])->findOrFail($commandeId);
    }

    private function estParticipant(Commande $commande, User $user): bool
    {
        return ($commande->client && $commande->client->user_id === $user->id)
            || ($commande->vendeur && $commande->vendeur->user_id === $user->id)
            || ($commande->livreur && $commande->livreur->user_id === $user->id);
    }

    public function index(Request $request, string $commandeId): JsonResponse
    {
        $commande = $this->chargerCommande($commandeId);
        $user = $request->user();

        if (!$this->estParticipant($commande, $user)) {
            return response()->json(['success' => false, 'message' => "Vous n'êtes pas autorisé à consulter cette conversation."], 403);
        }

        $messages = MessageCommande::where('commande_id', $commande->id)
            ->with('expediteur:id,nom,prenom,photo_profil,type_utilisateur')
            ->orderBy('created_at')
            ->get();

        // Marque comme lus les messages reçus par cet utilisateur (pas les siens) dès qu'il ouvre le fil.
        MessageCommande::where('commande_id', $commande->id)
            ->where('expediteur_user_id', '!=', $user->id)
            ->where('lu', false)
            ->update(['lu' => true]);

        return response()->json(['success' => true, 'data' => $messages]);
    }

    public function store(Request $request, string $commandeId): JsonResponse
    {
        $validated = $request->validate(['contenu' => 'required|string|max:2000']);
        $commande = $this->chargerCommande($commandeId);
        $user = $request->user();

        if (!$this->estParticipant($commande, $user)) {
            return response()->json(['success' => false, 'message' => "Vous n'êtes pas autorisé à écrire dans cette conversation."], 403);
        }

        $message = MessageCommande::create([
            'commande_id' => $commande->id,
            'expediteur_user_id' => $user->id,
            'contenu' => $validated['contenu'],
            'lu' => false,
        ]);

        // Notifie les autres participants de la commande (jamais l'expéditeur lui-même).
        $destinataires = collect([$commande->client?->user, $commande->vendeur?->user, $commande->livreur?->user])
            ->filter(fn ($u) => $u && $u->id !== $user->id);

        app(PushService::class)->envoyerAUtilisateurs(
            $destinataires,
            'Nouveau message',
            $user->getNomCompletAttribute() . ' : ' . Str::limit($validated['contenu'], 100),
            ['type' => 'message_commande', 'commande_id' => $commande->id]
        );

        return response()->json([
            'success' => true,
            'message' => 'Message envoyé.',
            'data' => $message->load('expediteur:id,nom,prenom,photo_profil,type_utilisateur'),
        ], 201);
    }
}
