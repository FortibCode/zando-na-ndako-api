<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\CodeOTP;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OtpController extends Controller
{
    /**
     * POST /api/otp/send
     * Envoie un code OTP par SMS ou Email.
     */
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credential' => 'required|string', // email ou téléphone
            'canal'      => 'required|in:sms,email',
        ]);

        // Trouver l'utilisateur par email ou téléphone
        $user = User::where('email', $validated['credential'])
            ->orWhere('telephone', $validated['credential'])
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun compte trouvé avec cette information de connexion.',
            ], 404);
        }

        // Générer et sauvegarder l'OTP
        $code = $this->genererEtSauvegarderOTP($user, $validated['canal']);

        // En production : envoyer le SMS/Email ici
        // SmsService::send($user->telephone, "Votre code de vérification : {$code}");
        // MailService::send($user->email, "Votre code de vérification : {$code}");

        return response()->json([
            'success' => true,
            'message' => "Code de vérification envoyé par {$validated['canal']}.",
            'otp_dev' => $code, // RETIRER EN PRODUCTION
        ]);
    }

    /**
     * POST /api/otp/verify
     * Vérifie un code OTP.
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credential' => 'required|string',
            'code'       => 'required|string|size:6',
        ]);

        $user = User::where('email', $validated['credential'])
            ->orWhere('telephone', $validated['credential'])
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Information de connexion introuvable.',
            ], 404);
        }

        $otp = CodeOTP::where('user_id', $user->id)
            ->where('code', $validated['code'])
            ->where('statut', 'valide')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$otp) {
            return response()->json([
                'success'    => false,
                'message'    => 'Code OTP invalide.',
                'error_code' => 'INVALID_OTP',
            ], 400);
        }

        if ($otp->estExpire()) {
            return response()->json([
                'success'    => false,
                'message'    => 'Code OTP expiré. Demandez un nouveau code.',
                'error_code' => 'OTP_EXPIRED',
            ], 400);
        }

        // Marquer l'OTP comme utilisé
        $otp->update(['statut' => 'utilise']);

        // Si le compte était en attente, l'activer
        if ($user->statut_compte === 'en_attente_validation') {
            $user->update(['statut_compte' => 'actif']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Code OTP vérifié avec succès.',
            'data'    => [
                'user_id' => $user->id,
                'compte_verifie' => $user->statut_compte === 'actif',
            ],
        ]);
    }

    /**
     * POST /api/otp/resend
     * Renvoie un nouveau code OTP.
     */
    public function resend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credential' => 'required|string',
            'canal'      => 'required|in:sms,email',
        ]);

        $user = User::where('email', $validated['credential'])
            ->orWhere('telephone', $validated['credential'])
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun compte trouvé.',
            ], 404);
        }

        // Invalider les anciens OTPs et en générer un nouveau
        $code = $this->genererEtSauvegarderOTP($user, $validated['canal']);

        return response()->json([
            'success' => true,
            'message' => "Nouveau code de vérification envoyé par {$validated['canal']}.",
            'otp_dev' => $code, // RETIRER EN PRODUCTION
        ]);
    }

    /**
     * Génère et sauvegarde un code OTP.
     */
    private function genererEtSauvegarderOTP(User $user, string $canal): string
    {
        // Invalider les anciens OTPs valides
        CodeOTP::where('user_id', $user->id)
            ->where('statut', 'valide')
            ->update(['statut' => 'expire']);

        $code = CodeOTP::genererCode();

        CodeOTP::create([
            'user_id'          => $user->id,
            'code'             => $code,
            'canal'            => $canal,
            'date_generation'  => now(),
            'date_expiration'  => now()->addMinutes(10),
            'statut'           => 'valide',
            'nombre_tentatives'=> 0,
            'adresse_ip_demande' => request()->ip(),
        ]);

        return $code;
    }
}