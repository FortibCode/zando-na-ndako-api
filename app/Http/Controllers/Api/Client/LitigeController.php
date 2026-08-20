<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Litige;
use App\Services\PushService;
use App\Support\AuditLogger;
use App\Support\DashboardCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LitigeController extends Controller
{
    // Motifs de litige — aucune liste n'existait déjà ailleurs dans le projet pour ce champ
    // (litiges.motif est un simple varchar non contraint), validée uniquement côté application.
    private const MOTIFS = [
        'produit_non_recu', 'produit_incorrect', 'produit_endommage',
        'produit_non_conforme', 'article_manquant', 'probleme_livraison',
        'probleme_paiement', 'probleme_remboursement', 'autre',
    ];

    // POST /api/commandes/{id}/litige
    public function ouvrir(Request $request, string $commandeId): JsonResponse
    {
        $validated = $request->validate([
            'motif' => 'required|string|in:' . implode(',', self::MOTIFS),
            'description' => 'required|string|max:2000',
        ]);

        $client = $request->user()->client;
        $commande = Commande::where('id', $commandeId)->where('client_id', $client->id)->firstOrFail();

        if ($commande->litige()->exists()) {
            return response()->json(['success' => false, 'message' => 'Un litige existe déjà pour cette commande.'], 409);
        }

        $litige = Litige::create([
            'numero' => Litige::genererNumero(),
            'commande_id' => $commande->id,
            'utilisateur_plaignant_id' => $request->user()->id,
            'motif' => $validated['motif'],
            'description' => $validated['description'],
            'statut' => 'ouvert',
            'date_ouverture' => now(),
        ]);

        DashboardCache::bump();
        AuditLogger::log($request->user(), 'ouvrir_litige', 'litiges', 'Litige', $litige->id, null, ['motif' => $litige->motif, 'commande_id' => $commande->id], $request);

        $commande->loadMissing('vendeur.user');
        if ($commande->vendeur?->user) {
            app(PushService::class)->envoyerAUtilisateur(
                $commande->vendeur->user,
                'Nouveau litige ouvert',
                "Un litige a été ouvert sur la commande {$commande->numero_commande}.",
                ['type' => 'litige', 'litige_id' => $litige->id, 'commande_id' => $commande->id]
            );
        }

        return response()->json(['success' => true, 'message' => 'Litige ouvert.', 'data' => $litige], 201);
    }

    // GET /api/client/litiges
    public function index(Request $request): JsonResponse
    {
        $q = Litige::where('utilisateur_plaignant_id', $request->user()->id)
            ->with(['commande', 'adminTraitant.user'])
            ->orderBy('date_ouverture', 'desc');
        if ($statut = $request->get('statut')) $q->where('statut', $statut);

        return response()->json(['success' => true, 'data' => $q->paginate(15)]);
    }

    // GET /api/client/litiges/{id}
    public function show(Request $request, string $id): JsonResponse
    {
        $litige = Litige::where('id', $id)
            ->where('utilisateur_plaignant_id', $request->user()->id)
            ->with(['commande.vendeur', 'adminTraitant.user', 'messages.user', 'piecesJointes.auteur', 'decisions', 'remboursements'])
            ->firstOrFail();

        // Les notes internes de l'admin ne sont jamais destinées au client.
        $litige->setRelation('messages', $litige->messages->where('est_note_interne', false)->values());

        return response()->json(['success' => true, 'data' => $litige]);
    }
}
