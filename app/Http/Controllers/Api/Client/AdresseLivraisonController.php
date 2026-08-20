<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\AdresseLivraison;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdresseLivraisonController extends Controller
{
    /**
     * Liste toutes les adresses de livraison du client connecté.
     * GET /api/client/adresses
     */
    public function index(Request $request): JsonResponse
    {
        $client = $request->user()->client;

        if (!$client) {
            return response()->json(['success' => false, 'message' => 'Profil client introuvable.'], 404);
        }

        $adresses = AdresseLivraison::where('client_id', $client->id)
            ->orderByRaw('est_defaut DESC, created_at DESC')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $adresses,
        ]);
    }

    /**
     * Crée une nouvelle adresse de livraison.
     * POST /api/client/adresses
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label'            => 'required|string|max:50',
            'nom_complet'      => 'nullable|string|max:150',
            'telephone'        => 'nullable|string|max:20',
            'ville'            => 'nullable|string|max:100',
            'quartier'         => 'nullable|string|max:100',
            'adresse'          => 'required|string|max:500',
            'coordonnees_gps'  => 'nullable|array',
            'coordonnees_gps.lat' => 'nullable|numeric',
            'coordonnees_gps.lng' => 'nullable|numeric',
            'instructions'     => 'nullable|string|max:500',
            'est_defaut'       => 'sometimes|boolean',
        ]);

        $client = $request->user()->client;

        if (!$client) {
            return response()->json(['success' => false, 'message' => 'Profil client introuvable.'], 404);
        }

        // Si c'est la première adresse, elle devient par défaut automatiquement
        $hasAddresses = AdresseLivraison::where('client_id', $client->id)->exists();
        $estDefaut    = (bool) ($validated['est_defaut'] ?? false) || !$hasAddresses;

        DB::transaction(function () use ($client, $validated, $estDefaut) {
            if ($estDefaut) {
                AdresseLivraison::where('client_id', $client->id)->update(['est_defaut' => false]);
            }

            AdresseLivraison::create([
                'client_id'       => $client->id,
                'label'           => $validated['label'],
                'nom_complet'     => $validated['nom_complet'] ?? null,
                'telephone'       => $validated['telephone'] ?? null,
                'ville'           => $validated['ville'] ?? 'Brazzaville',
                'quartier'        => $validated['quartier'] ?? null,
                'adresse'         => $validated['adresse'],
                'coordonnees_gps' => $validated['coordonnees_gps'] ?? null,
                'instructions'    => $validated['instructions'] ?? null,
                'est_defaut'      => $estDefaut,
            ]);
        });

        $adresse = AdresseLivraison::where('client_id', $client->id)
            ->orderByRaw('est_defaut DESC, created_at DESC')
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Adresse de livraison ajoutée.',
            'data'    => $adresse,
        ], 201);
    }

    /**
     * Détail d'une adresse de livraison.
     * GET /api/client/adresses/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $client  = $request->user()->client;
        $adresse = AdresseLivraison::where('id', $id)->where('client_id', $client->id)->first();

        if (!$adresse) {
            return response()->json(['success' => false, 'message' => 'Adresse introuvable.'], 404);
        }

        return response()->json(['success' => true, 'data' => $adresse]);
    }

    /**
     * Modifie une adresse de livraison existante.
     * PUT /api/client/adresses/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'label'            => 'sometimes|string|max:50',
            'nom_complet'      => 'nullable|string|max:150',
            'telephone'        => 'nullable|string|max:20',
            'ville'            => 'nullable|string|max:100',
            'quartier'         => 'nullable|string|max:100',
            'adresse'          => 'sometimes|string|max:500',
            'coordonnees_gps'  => 'nullable|array',
            'coordonnees_gps.lat' => 'nullable|numeric',
            'coordonnees_gps.lng' => 'nullable|numeric',
            'instructions'     => 'nullable|string|max:500',
            'est_defaut'       => 'sometimes|boolean',
        ]);

        $client  = $request->user()->client;
        $adresse = AdresseLivraison::where('id', $id)->where('client_id', $client->id)->first();

        if (!$adresse) {
            return response()->json(['success' => false, 'message' => 'Adresse introuvable.'], 404);
        }

        DB::transaction(function () use ($client, $adresse, $validated) {
            // Si l'adresse est marquée par défaut, retirer le statut aux autres
            if (!empty($validated['est_defaut'])) {
                AdresseLivraison::where('client_id', $client->id)
                    ->where('id', '!=', $adresse->id)
                    ->update(['est_defaut' => false]);
            }

            $adresse->update($validated);
        });

        return response()->json([
            'success' => true,
            'message' => 'Adresse de livraison mise à jour.',
            'data'    => $adresse->fresh(),
        ]);
    }

    /**
     * Supprime une adresse de livraison.
     * DELETE /api/client/adresses/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $client  = $request->user()->client;
        $adresse = AdresseLivraison::where('id', $id)->where('client_id', $client->id)->first();

        if (!$adresse) {
            return response()->json(['success' => false, 'message' => 'Adresse introuvable.'], 404);
        }

        $etaitDefaut = $adresse->est_defaut;
        $adresse->delete();

        // Si c'était l'adresse par défaut, définir la plus récente comme nouvelle défaut
        if ($etaitDefaut) {
            $nouvelle = AdresseLivraison::where('client_id', $client->id)->orderByDesc('created_at')->first();
            if ($nouvelle) {
                $nouvelle->update(['est_defaut' => true]);
            }
        }

        return response()->json(['success' => true, 'message' => 'Adresse de livraison supprimée.']);
    }

    /**
     * Définit une adresse comme adresse par défaut.
     * POST /api/client/adresses/{id}/default
     */
    public function setDefault(Request $request, string $id): JsonResponse
    {
        $client  = $request->user()->client;
        $adresse = AdresseLivraison::where('id', $id)->where('client_id', $client->id)->first();

        if (!$adresse) {
            return response()->json(['success' => false, 'message' => 'Adresse introuvable.'], 404);
        }

        DB::transaction(function () use ($client, $adresse) {
            AdresseLivraison::where('client_id', $client->id)->update(['est_defaut' => false]);
            $adresse->update(['est_defaut' => true]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Adresse par défaut mise à jour.',
            'data'    => $adresse->fresh(),
        ]);
    }
}