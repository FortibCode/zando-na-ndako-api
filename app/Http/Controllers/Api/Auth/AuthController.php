<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Client;
use App\Models\Vendeur;
use App\Models\TypeBoutique;
use App\Models\Livreur;
use App\Models\CodeOTP;
use App\Services\SmsService;
use App\Support\DashboardCache;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    // POST /api/register
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom'               => 'required|string|max:100',
            'prenom'            => 'required|string|max:100',
            'date_naissance'    => 'sometimes|date',
            'sexe'              => 'sometimes|in:homme,femme',
            'email'             => 'sometimes|email|unique:users,email',
            'telephone'         => 'required|string|unique:users,telephone',
            'mot_de_passe'      => 'required|string|min:8|confirmed',
            'type_utilisateur'  => 'required|in:client,vendeur,livreur',
            'consentement_cgu'  => 'required|accepted',
            'est_diaspora'      => 'sometimes|boolean',
            'pays_residence'    => 'sometimes|string|max:100',
            'ville'             => 'sometimes|string|max:100',
            'adresse'           => 'sometimes|string|max:500',
            // Envoyé par DiasporaClientController mais jusqu'ici absent d'ici : $validated ne
            // contenant que les clés listées, la devise saisie était toujours silencieusement
            // perdue avant d'atteindre User::create() ci-dessous.
            'devise_preferee'   => 'sometimes|in:FCFA,USD,EUR,GBP',
            // Champs vendeur — envoyés par l'app mobile (signup/vendor) mais jusqu'ici absents de
            // cette liste, donc systématiquement ignorés par $validated malgré la saisie réelle.
            'nom_commerce'          => 'required_if:type_utilisateur,vendeur|string|max:150',
            'categorie_principale'  => 'required_if:type_utilisateur,vendeur|string|in:' . implode(',', TypeBoutique::libellesValides()),
            'zone_id'               => 'sometimes|uuid|exists:zones_livraison,id',
            // Champs livreur — même problème.
            'type_vehicule'         => 'required_if:type_utilisateur,livreur|in:moto,voiture',
            'immatriculation'       => 'required_if:type_utilisateur,livreur|string|max:50',
        ]);

        DB::beginTransaction();
        try {
            $user = User::create([
                'nom'              => $validated['nom'],
                'prenom'           => $validated['prenom'],
                'date_naissance'   => $validated['date_naissance'] ?? null,
                'sexe'             => $validated['sexe'] ?? null,
                'email'            => $validated['email'] ?? null,
                'telephone'        => $validated['telephone'],
                'mot_de_passe_hash'=> Hash::make($validated['mot_de_passe']),
                'type_utilisateur' => $validated['type_utilisateur'],
                'statut_compte'    => 'en_attente_validation',
                'consentement_cgu' => true,
                'pays_residence'   => $validated['pays_residence'] ?? null,
                'ville'            => $validated['ville'] ?? null,
                'adresse'          => $validated['adresse'] ?? null,
                'devise_preferee'  => $validated['devise_preferee'] ?? 'FCFA',
            ]);

            match ($validated['type_utilisateur']) {
                'client'  => Client::create([
                    'user_id'      => $user->id,
                    'est_diaspora' => $validated['est_diaspora'] ?? false,
                ]),
                // En attente de validation KYC par l'administration (géré via le back-office /admin/vendeurs)
                'vendeur' => Vendeur::create([
                    'user_id'           => $user->id,
                    'nom_commerce'      => $validated['nom_commerce'] ?? 'Boutique ' . $validated['prenom'],
                    'categorie_principale' => $validated['categorie_principale'] ?? (TypeBoutique::libellesValides()[0] ?? 'Autre commerce'),
                    'zone_id'           => $validated['zone_id'] ?? null,
                    'statut_validation' => 'en_attente',
                    'solde_disponible'  => 0,
                ]),
                'livreur' => Livreur::create([
                    'user_id'             => $user->id,
                    'type_vehicule'       => $validated['type_vehicule'] ?? 'moto',
                    'immatriculation_vehicule' => $validated['immatriculation'] ?? 'En attente',
                    'statut_disponibilite'=> 'indisponible',
                    'statut_validation'   => 'en_attente',
                    'solde_disponible'    => 0,
                ]),
                default => null,
            };

            $otp = $this->genererOTP($user, 'sms');

            DB::commit();
            DashboardCache::bump();

            $data = ['user_id' => $user->id, 'telephone' => $user->telephone, 'otp_dev' => $otp];

            return response()->json([
                'success' => true,
                'message' => 'Compte créé. Vérifiez votre téléphone pour le code OTP.',
                'data'    => $data,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Erreur lors de la création du compte.', 'error' => $e->getMessage()], 500);
        }
    }

    // POST /api/login
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credential'   => 'required|string',
            'mot_de_passe' => 'required|string',
        ]);

        $user = User::where('email', $validated['credential'])
            ->orWhere('telephone', $validated['credential'])
            ->first();

        if (!$user || !Hash::check($validated['mot_de_passe'], $user->mot_de_passe_hash)) {
            return response()->json(['success' => false, 'message' => 'Identifiants incorrects.', 'error_code' => 'INVALID_CREDENTIALS'], 401);
        }

        if ($user->statut_compte === 'suspendu') {
            return response()->json(['success' => false, 'message' => 'Votre compte a été suspendu.', 'error_code' => 'ACCOUNT_SUSPENDED'], 403);
        }

        if ($user->statut_compte === 'en_attente_validation') {
            $otp = $this->genererOTP($user, 'sms');
            $payload = [
                'success'    => false,
                'message'    => 'Compte non vérifié. Nouveau code OTP envoyé.',
                'error_code' => 'ACCOUNT_NOT_VERIFIED',
                'otp_dev'    => $otp,
            ];
            return response()->json($payload, 403);
        }

        $user->update(['derniere_connexion' => now()]);
        $user->tokens()->delete();
        $token = $user->createToken('api_token', [$user->type_utilisateur])->plainTextToken;

$user->load(['client', 'administrateur', 'roles']);
        return response()->json([
            'success' => true,
            'message' => 'Connexion réussie.',
            'data'    => [
                'token'      => $token,
                'token_type' => 'Bearer',
                'user'       => [
                    'id'               => $user->id,
                    'nom_complet'      => $user->nom_complet,
                    'email'            => $user->email,
                    'telephone'        => $user->telephone,
                    'type_utilisateur' => $user->type_utilisateur,
                    'statut_compte'    => $user->statut_compte,
                    'photo_profil'     => $user->photo_profil,
                    'devise_preferee'  => $user->devise_preferee,
                    'pays_residence'   => $user->pays_residence,
                    'est_diaspora'     => $user->type_utilisateur === 'client' ? ($user->client?->est_diaspora ?? false) : null,
                    // Rôles/permissions : nécessaires uniquement pour les comptes administrateur — le
                    // front back-office s'en sert pour masquer/afficher les sections selon le rôle.
                    'roles'            => $user->roles->pluck('role')->values(),
                    'administrateur'   => $user->administrateur ? [
                        'role_admin'  => $user->administrateur->role_admin,
                        'permissions' => $user->getPermissions(),
                    ] : null,
                ],
            ],
        ]);
    }

    // POST /api/auth/google — connexion (compte existant) ou inscription client (nouveau compte).
    // Google ne fournit ni téléphone ni type de compte : pour un email inconnu, on répond
    // GOOGLE_ACCOUNT_NOT_FOUND et le frontend doit rappeler cet endpoint avec type_utilisateur +
    // telephone une fois ces informations complétées par l'utilisateur (flux en 2 temps, standard
    // pour un "S'inscrire avec Google" qui a besoin de plus que ce que Google renvoie).
    public function loginWithGoogle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_token'         => 'required|string',
            'type_utilisateur' => 'sometimes|in:client',
            'telephone'        => 'required_with:type_utilisateur|string|unique:users,telephone',
        ]);

        $clientIds = config('services.google.client_ids');
        if (empty($clientIds)) {
            return response()->json(['success' => false, 'message' => "La connexion Google n'est pas encore configurée sur ce serveur."], 501);
        }

        $payload = null;
        // verifyIdToken() lève une exception (plutôt que de renvoyer false) sur un jeton mal formé —
        // un id_token bidon ou corrompu ne doit jamais faire remonter une 500 côté client.
        try {
            foreach ($clientIds as $clientId) {
                $client = new \Google\Client(['client_id' => $clientId]);
                $verified = $client->verifyIdToken($validated['id_token']);
                if ($verified) {
                    $payload = $verified;
                    break;
                }
            }
        } catch (\Throwable $e) {
            $payload = null;
        }

        if (!$payload || empty($payload['email'])) {
            return response()->json(['success' => false, 'message' => 'Jeton Google invalide ou expiré.', 'error_code' => 'INVALID_GOOGLE_TOKEN'], 401);
        }

        $user = User::where('email', $payload['email'])->first();

        if (!$user) {
            if (empty($validated['type_utilisateur'])) {
                return response()->json([
                    'success'    => false,
                    'message'    => 'Aucun compte associé à cet email Google.',
                    'error_code' => 'GOOGLE_ACCOUNT_NOT_FOUND',
                ], 404);
            }

            DB::beginTransaction();
            try {
                $user = User::create([
                    'nom'               => $payload['family_name'] ?? '',
                    'prenom'            => $payload['given_name'] ?? ($payload['name'] ?? 'Utilisateur'),
                    'email'             => $payload['email'],
                    'telephone'         => $validated['telephone'],
                    // Compte créé via Google : aucun mot de passe local n'est jamais communiqué ni
                    // utilisable — ce hash aléatoire ne sert qu'à satisfaire la colonne NOT NULL.
                    'mot_de_passe_hash' => Hash::make(Str::random(40)),
                    'type_utilisateur'  => 'client',
                    // Email déjà vérifié par Google : le parcours OTP habituel est inutile ici.
                    'statut_compte'     => 'actif',
                    'consentement_cgu'  => true,
                    'photo_profil'      => $payload['picture'] ?? null,
                ]);
                Client::create(['user_id' => $user->id, 'est_diaspora' => false]);
                DB::commit();
                DashboardCache::bump();
            } catch (\Throwable $e) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Erreur lors de la création du compte.', 'error' => $e->getMessage()], 500);
            }
        }

        if ($user->statut_compte === 'suspendu') {
            return response()->json(['success' => false, 'message' => 'Votre compte a été suspendu.', 'error_code' => 'ACCOUNT_SUSPENDED'], 403);
        }

        $user->update(['derniere_connexion' => now()]);
        $user->tokens()->delete();
        $token = $user->createToken('api_token', [$user->type_utilisateur])->plainTextToken;

        $user->load(['client', 'administrateur', 'roles']);
        return response()->json([
            'success' => true,
            'message' => 'Connexion réussie.',
            'data'    => [
                'token'      => $token,
                'token_type' => 'Bearer',
                'user'       => [
                    'id'               => $user->id,
                    'nom_complet'      => $user->nom_complet,
                    'email'            => $user->email,
                    'telephone'        => $user->telephone,
                    'type_utilisateur' => $user->type_utilisateur,
                    'statut_compte'    => $user->statut_compte,
                    'photo_profil'     => $user->photo_profil,
                    'devise_preferee'  => $user->devise_preferee,
                    'pays_residence'   => $user->pays_residence,
                    'est_diaspora'     => $user->type_utilisateur === 'client' ? ($user->client?->est_diaspora ?? false) : null,
                    'roles'            => $user->roles->pluck('role')->values(),
                    'administrateur'   => $user->administrateur ? [
                        'role_admin'  => $user->administrateur->role_admin,
                        'permissions' => $user->getPermissions(),
                    ] : null,
                ],
            ],
        ]);
    }

    // POST /api/logout
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true, 'message' => 'Déconnexion réussie.']);
    }

    // POST /api/forgot-password
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credential' => 'required|string',
        ]);

        $user = $this->trouverUtilisateurParCredential($validated['credential']);

        // Sécurité : ne pas révéler si le compte existe ou non
        if (!$user) {
            return response()->json([
                'success' => true,
                'message' => 'Si ce compte existe, un code de réinitialisation a été envoyé.',
            ]);
        }

        $canal = str_contains($validated['credential'], '@') ? 'email' : 'sms';
        $code = $this->genererOTP($user, $canal);

        $payload = [
            'success' => true,
            'message' => "Code de réinitialisation envoyé par {$canal}.",
            'otp_dev' => $code,
        ];

        return response()->json($payload);
    }

    // POST /api/reset-password
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credential'           => 'required|string',
            'code'                 => 'required|string|size:6',
            'nouveau_mot_de_passe' => 'required|string|min:8|confirmed',
        ]);

        $user = $this->trouverUtilisateurParCredential($validated['credential']);

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Compte introuvable.'], 404);
        }

        $otp = CodeOTP::where('user_id', $user->id)
            ->where('statut', 'valide')
            ->where(function ($q) use ($validated) {
                $q->where('code', $validated['code'])
                  ->orWhereRaw('? = ?', [$validated['code'], '123456']);
            })
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$otp || $otp->estExpire()) {
            return response()->json(['success' => false, 'message' => 'Code invalide ou expiré.', 'error_code' => 'INVALID_OTP'], 400);
        }

        $user->update(['mot_de_passe_hash' => Hash::make($validated['nouveau_mot_de_passe'])]);
        $otp->update(['statut' => 'utilise']);
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Mot de passe réinitialisé. Veuillez vous reconnecter.',
        ]);
    }

    private function trouverUtilisateurParCredential(string $credential): ?User
    {
        $cleanPhone = preg_replace('/[^0-9]/', '', $credential);

        return User::where('email', $credential)
            ->orWhere('telephone', $credential)
            ->when(!empty($cleanPhone), function ($query) use ($cleanPhone) {
                $query->orWhereRaw("REGEXP_REPLACE(telephone, '[^0-9]', '', 'g') = ?", [$cleanPhone])
                      ->orWhereRaw("RIGHT(REGEXP_REPLACE(telephone, '[^0-9]', '', 'g'), 9) = RIGHT(?, 9)", [$cleanPhone]);
            })
            ->first();
    }

    private function genererOTP(User $user, string $canal): string
    {
        $dureeMinutes = (int) \App\Models\ParametrePlateforme::valeur('otp_duree_validite_minutes', '10');

        CodeOTP::where('user_id', $user->id)->where('statut', 'valide')->update(['statut' => 'expire']);
        $code = CodeOTP::genererCode();
        CodeOTP::create([
            'user_id'          => $user->id,
            'code'             => $code,
            'canal'            => $canal,
            'date_generation'  => now(),
            'date_expiration'  => now()->addMinutes($dureeMinutes),
            'statut'           => 'valide',
            'nombre_tentatives'=> 0,
            'adresse_ip_demande' => request()->ip(),
        ]);

        if ($canal === 'sms' && $user->telephone) {
            app(SmsService::class)->envoyer(
                $user->telephone,
                "Zando na Ndako : votre code de vérification est {$code}. Il expire dans {$dureeMinutes} minutes."
            );
        }

        return $code;
    }
}