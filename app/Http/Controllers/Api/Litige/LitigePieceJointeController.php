<?php

namespace App\Http\Controllers\Api\Litige;

use App\Http\Controllers\Controller;
use App\Models\Litige;
use App\Models\LitigeMessage;
use App\Models\LitigePieceJointe;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pièces jointes / preuves d'un litige — même convention de stockage que le reste de l'app
 * (disque 'public', dossier dédié) — voir VendeurController::ajouterProduit() pour le précédent
 * exact. max:10240 (10 Mo) et mimes élargis (pdf, vidéo) car une preuve peut être une capture
 * d'écran, un document, ou une courte vidéo — plus permissif que les 3072/jpeg,png,jpg,webp
 * utilisés pour les photos produit.
 */
class LitigePieceJointeController extends Controller
{
    private function chargerLitige(string $id): Litige
    {
        return Litige::with(['commande.vendeur.user', 'plaignant'])->findOrFail($id);
    }

    private function resolveSenderType(Litige $litige, User $user): ?string
    {
        if ($user->hasRole('administrateur')) return 'admin';
        if ($litige->utilisateur_plaignant_id === $user->id) return 'client';
        if ($litige->commande?->vendeur && $litige->commande->vendeur->user_id === $user->id) return 'vendeur';
        return null;
    }

    // POST /api/litiges/{id}/pieces-jointes
    public function store(Request $request, string $id): JsonResponse
    {
        $litige = $this->chargerLitige($id);
        $user = $request->user();

        if (!$this->resolveSenderType($litige, $user)) {
            return response()->json(['success' => false, 'message' => "Vous n'êtes pas autorisé à ajouter une preuve à ce litige."], 403);
        }
        if ($litige->estResolu()) {
            return response()->json(['success' => false, 'message' => "Ce litige est clôturé, il n'est plus possible d'y ajouter une preuve."], 400);
        }

        $validated = $request->validate([
            'fichier' => 'required|file|mimes:jpeg,png,jpg,webp,pdf,mp4,mov|max:10240',
            'message_id' => 'nullable|uuid|exists:litige_messages,id',
        ]);

        if (!empty($validated['message_id'])) {
            $appartient = LitigeMessage::where('id', $validated['message_id'])->where('litige_id', $litige->id)->exists();
            if (!$appartient) {
                return response()->json(['success' => false, 'message' => 'Message invalide pour ce litige.'], 422);
            }
        }

        $fichier = $request->file('fichier');
        $mime = $fichier->getMimeType() ?? '';
        $path = $fichier->store('preuves/litiges', 'public');

        $piece = LitigePieceJointe::create([
            'litige_id' => $litige->id,
            'message_id' => $validated['message_id'] ?? null,
            'uploaded_by' => $user->id,
            'file_name' => $fichier->getClientOriginalName(),
            'file_path' => $path,
            'file_type' => str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'video/') ? 'video' : 'document'),
            'mime_type' => $mime,
            'file_size' => $fichier->getSize(),
            'created_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Preuve ajoutée.', 'data' => $piece], 201);
    }
}
