<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LocalClientController extends Controller
{
    /**
     * Inscription spécifique pour un client local (Congo).
     * POST /api/client/local/register
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom'               => 'required|string|max:100',
            'prenom'            => 'required|string|max:100',
            'date_naissance'    => 'required|date',
            'sexe'              => 'required|in:homme,femme',
            'email'             => 'sometimes|email|unique:users,email',
            'telephone'         => 'required|string|unique:users,telephone',
            'mot_de_passe'      => 'required|string|min:8|confirmed',
            'consentement_cgu'  => 'required|accepted',
            'ville'             => 'required|string|max:100',
            'adresse'           => 'required|string|max:500',
        ]);

        $validated['type_utilisateur'] = 'client';
        $validated['est_diaspora'] = false;
        $validated['pays_residence'] = 'Congo-Brazzaville';

        return app(\App\Http\Controllers\Api\Auth\AuthController::class)->register($request);
    }

    /**
     * Récupère le profil du client local.
     * GET /api/client/local/profile
     */
    public function profile(Request $request): JsonResponse
    {
        $client = $request->user()->client;
        if (!$client || $client->est_diaspora) {
            return response()->json(['success' => false, 'message' => 'Profil non trouvé ou type incorrect.'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $client->load(['user', 'panier', 'commandes' => fn($q) => $q->latest()->take(5)]),
        ]);
    }
}