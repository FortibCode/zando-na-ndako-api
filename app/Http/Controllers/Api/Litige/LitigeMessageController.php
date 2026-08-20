<?php

namespace App\Http\Controllers\Api\Litige;

use App\Http\Controllers\Controller;
use App\Models\Administrateur;
use App\Models\Litige;
use App\Models\LitigeMessage;
use App\Models\User;
use App\Services\PushService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Conversation d'un litige, partagée par le client (plaignant), le vendeur de la commande
 * concernée, et les administrateurs — même principe que MessageCommandeController (fil unique,
 * expéditeur identifié), avec un troisième type de participant (admin) et une distinction
 * note-interne héritée de TicketReponse::est_note_interne.
 */
class LitigeMessageController extends Controller
{
    private function chargerLitige(string $id): Litige
    {
        return Litige::with(['commande.vendeur.user', 'plaignant'])->findOrFail($id);
    }

    // Retourne le type d'expéditeur du user authentifié pour ce litige précis, ou null s'il n'y a
    // aucun droit d'accès (ni plaignant, ni vendeur de la commande, ni administrateur).
    private function resolveSenderType(Litige $litige, User $user): ?string
    {
        if ($user->hasRole('administrateur')) return 'admin';
        if ($litige->utilisateur_plaignant_id === $user->id) return 'client';
        if ($litige->commande?->vendeur && $litige->commande->vendeur->user_id === $user->id) return 'vendeur';
        return null;
    }

    public function index(Request $request, string $id): JsonResponse
    {
        $litige = $this->chargerLitige($id);
        $senderType = $this->resolveSenderType($litige, $request->user());

        if (!$senderType) {
            return response()->json(['success' => false, 'message' => "Vous n'êtes pas autorisé à consulter ce litige."], 403);
        }

        $q = LitigeMessage::where('litige_id', $litige->id)
            ->with(['user:id,nom,prenom,photo_profil,type_utilisateur', 'piecesJointes'])
            ->orderBy('created_at');

        // Les notes internes ne sont jamais visibles par le client ou le vendeur.
        if ($senderType !== 'admin') {
            $q->where('est_note_interne', false);
        }

        return response()->json(['success' => true, 'data' => $q->get()]);
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $litige = $this->chargerLitige($id);
        $user = $request->user();
        $senderType = $this->resolveSenderType($litige, $user);

        if (!$senderType) {
            return response()->json(['success' => false, 'message' => "Vous n'êtes pas autorisé à écrire dans ce litige."], 403);
        }
        if ($litige->estResolu()) {
            return response()->json(['success' => false, 'message' => "Ce litige est clôturé, il n'est plus possible d'y répondre."], 400);
        }

        $rules = ['message' => 'required|string|max:2000'];
        if ($senderType === 'admin') {
            $rules['est_note_interne'] = 'sometimes|boolean';
        }
        $validated = $request->validate($rules);
        $estNoteInterne = $senderType === 'admin' && ($validated['est_note_interne'] ?? false);

        $message = LitigeMessage::create([
            'litige_id' => $litige->id,
            'user_id' => $user->id,
            'sender_type' => $senderType,
            'message' => $validated['message'],
            'est_note_interne' => $estNoteInterne,
        ]);

        AuditLogger::log($user, 'message_litige', 'litiges', 'Litige', $litige->id, null, ['sender_type' => $senderType], $request);

        if (!$estNoteInterne) {
            $this->notifierAutresParties($litige, $user, $senderType, $validated['message']);

            // Un message d'une partie sort automatiquement le litige de son état d'attente
            // (attente_client/attente_vendeur) — signale à l'admin qu'il y a du nouveau à examiner.
            if (in_array($litige->statut, ['attente_client', 'attente_vendeur'], true)) {
                $litige->update(['statut' => 'en_cours']);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Message envoyé.',
            'data' => $message->load('user:id,nom,prenom,photo_profil,type_utilisateur'),
        ], 201);
    }

    private function notifierAutresParties(Litige $litige, User $sender, string $senderType, string $contenu): void
    {
        $destinataires = collect();

        if ($senderType !== 'client' && $litige->plaignant) {
            $destinataires->push($litige->plaignant);
        }
        if ($senderType !== 'vendeur' && $litige->commande?->vendeur?->user) {
            $destinataires->push($litige->commande->vendeur->user);
        }
        if ($senderType !== 'admin') {
            $destinataires = $destinataires->merge(Administrateur::with('user')->get()->pluck('user')->filter());
        }

        $destinataires = $destinataires->filter(fn ($u) => $u && $u->id !== $sender->id)->unique('id');

        app(PushService::class)->envoyerAUtilisateurs(
            $destinataires,
            "Litige {$litige->numero}",
            Str::limit($contenu, 100),
            ['type' => 'litige_message', 'litige_id' => $litige->id]
        );
    }
}
