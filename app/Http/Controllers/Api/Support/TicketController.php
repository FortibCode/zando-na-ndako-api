<?php

namespace App\Http\Controllers\Api\Support;

use App\Http\Controllers\Controller;
use App\Models\TicketReponse;
use App\Models\TicketSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tickets support côté utilisateur (client, vendeur ou livreur — accessible aux 3 rôles).
 * Le pendant administrateur vit dans Api\Admin\AdminController (tickets(), assignerTicket(), etc.).
 */
class TicketController extends Controller
{
    // GET /api/support/tickets
    public function index(Request $request): JsonResponse
    {
        $tickets = TicketSupport::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $tickets]);
    }

    // POST /api/support/tickets
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'categorie' => 'required|in:commande,paiement,livraison,vendeur,livreur,produit,remboursement,compte,autre',
            'commande_id' => 'nullable|uuid|exists:commandes,id',
            'sujet' => 'required|string|max:150',
            'description' => 'required|string|max:2000',
        ]);

        $ticket = TicketSupport::create($validated + [
            'numero' => TicketSupport::genererNumero(),
            'user_id' => $request->user()->id,
            'statut' => 'ouvert',
            'priorite' => 'normale',
        ]);

        return response()->json(['success' => true, 'message' => 'Ticket créé.', 'data' => $ticket], 201);
    }

    // GET /api/support/tickets/{id}
    public function show(Request $request, string $id): JsonResponse
    {
        $ticket = TicketSupport::with(['commande', 'reponses' => function ($q) {
            $q->where('est_note_interne', false)->with('auteur');
        }])
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $ticket]);
    }

    // POST /api/support/tickets/{id}/repondre
    public function repondre(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['message' => 'required|string|max:2000']);
        $ticket = TicketSupport::where('user_id', $request->user()->id)->findOrFail($id);

        TicketReponse::create([
            'ticket_id' => $ticket->id,
            'auteur_id' => $request->user()->id,
            'message' => $validated['message'],
            'est_note_interne' => false,
        ]);

        // Une réponse de l'utilisateur sur un ticket clôturé/résolu le rouvre naturellement.
        $ticket->update([
            'date_derniere_reponse' => now(),
            'statut' => in_array($ticket->statut, ['resolu', 'ferme']) ? 'ouvert' : $ticket->statut,
        ]);

        return response()->json(['success' => true, 'message' => 'Réponse envoyée.']);
    }
}
