<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\CodeOTP;
use App\Models\ParametrePlateforme;
use App\Services\SmsService;
use App\Support\DashboardCache;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class OtpController extends Controller
{
    // POST /api/otp/send
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credential' => 'required|string',
            'canal'      => 'required|in:sms,email',
        ]);

        $user = $this->trouverUtilisateurParCredential($validated['credential']);

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Aucun compte trouvé.'], 404);
        }

        $code = $this->genererEtSauvegarderOTP($user, $validated['canal']);

        $payload = ['success' => true, 'message' => "Code envoyé par {$validated['canal']}."];
        if (app()->environment('local')) {
            $payload['otp_dev'] = $code;
        }

        return response()->json($payload);
    }

    // POST /api/otp/verify
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credential' => 'required|string',
            'code'       => 'required|string|size:6',
        ]);

        $user = $this->trouverUtilisateurParCredential($validated['credential']);

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Information de connexion introuvable.'], 404);
        }

        $otp = CodeOTP::where('user_id', $user->id)
            ->where('code', $validated['code'])
            ->where('statut', 'valide')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$otp) {
            return response()->json(['success' => false, 'message' => 'Code invalide.', 'error_code' => 'INVALID_OTP'], 400);
        }

        if ($otp->estExpire()) {
            return response()->json(['success' => false, 'message' => 'Code expiré.', 'error_code' => 'OTP_EXPIRED'], 400);
        }

        $otp->update(['statut' => 'utilise']);

        if ($user->statut_compte === 'en_attente_validation') {
            $user->update(['statut_compte' => 'actif']);
            DashboardCache::bump();
        }

        // Générer un token Sanctum pour connexion directe après OTP
        $user->tokens()->delete();
        $token = $user->createToken('api_token', [$user->type_utilisateur])->plainTextToken;
        $user->update(['derniere_connexion' => now()]);

$user->load('client');

        return response()->json([
            'success' => true,
            'message' => 'Code vérifié. Connexion réussie.',
            'data'    => [
                'compte_verifie' => true,
                'token'          => $token,
                'token_type'     => 'Bearer',
                'user'           => [
                    'id'               => $user->id,
                    'nom_complet'      => $user->nom_complet,
                    'email'            => $user->email,
                    'telephone'        => $user->telephone,
                    'type_utilisateur' => $user->type_utilisateur,
                    'statut_compte'    => $user->statut_compte,
                    'photo_profil'     => $user->photo_profil,
                    'est_diaspora'     => $user->type_utilisateur === 'client' ? ($user->client?->est_diaspora ?? false) : null,
                ],
            ],
        ]);
    }

    // POST /api/otp/resend
    public function resend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credential' => 'required|string',
            'canal'      => 'required|in:sms,email',
        ]);

        $user = $this->trouverUtilisateurParCredential($validated['credential']);

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Aucun compte trouvé.'], 404);
        }

        $code = $this->genererEtSauvegarderOTP($user, $validated['canal']);

        $payload = ['success' => true, 'message' => "Nouveau code envoyé par {$validated['canal']}."];
        if (app()->environment('local')) {
            $payload['otp_dev'] = $code;
        }

        return response()->json($payload);
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

    private function genererEtSauvegarderOTP(User $user, string $canal): string
    {
        $dureeMinutes = (int) ParametrePlateforme::valeur('otp_duree_validite_minutes', '10');

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