<?php

namespace App\Services;

use App\Models\JetonAppareil;
use App\Models\Notification as NotificationModel;
use App\Models\ParametrePlateforme;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envoie de vraies notifications push via le service Expo Push (https://exp.host).
 * Aucune clé API ni SDK n'est nécessaire : un simple appel HTTP POST suffit.
 *
 * Suit le même principe que SmsService : si l'utilisateur n'a aucun jeton d'appareil
 * enregistré (permission refusée, app jamais ouverte sur un appareil, etc.), on journalise
 * au lieu d'échouer — le polling existant (15-20s) côté mobile reste le filet de sécurité.
 */
class PushService
{
    private const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';

    /**
     * Envoie une notification push à tous les appareils enregistrés d'un utilisateur.
     * Journalise systématiquement l'événement dans la table `notifications` (centre de
     * notifications in-app), que le push physique ait réussi ou non — `canal_secours_utilise`
     * indique si l'utilisateur devra compter sur le polling faute de jeton/d'envoi réussi.
     */
    public function envoyerAUtilisateur(User $user, string $titre, string $message, array $data = [], ?string $lienAction = null, ?string $campagneId = null): bool
    {
        $pushActif = ParametrePlateforme::valeur('notifications_push_actives', '1') === '1';
        $tokens = JetonAppareil::where('user_id', $user->id)->pluck('token')->all();
        $envoye = $pushActif && !empty($tokens) && $this->envoyerExpo($tokens, $titre, $message, $data);

        NotificationModel::create([
            'user_id'               => $user->id,
            'campagne_id'           => $campagneId,
            'titre'                 => $titre,
            'message'               => $message,
            'type_canal'            => 'push',
            'lien_action'           => $lienAction,
            'date_envoi'            => now(),
            'statut_envoi'          => $envoye ? 'envoye' : 'echoue',
            'canal_secours_utilise' => !$envoye,
        ]);

        if (empty($tokens)) {
            Log::info("[Push non envoyé - aucun jeton enregistré] Utilisateur {$user->id} : {$titre}");
        }

        return $envoye;
    }

    /**
     * Diffuse la même notification à plusieurs utilisateurs (ex : "nouvelle mission
     * disponible" envoyée à tous les livreurs disponibles).
     */
    public function envoyerAUtilisateurs(iterable $users, string $titre, string $message, array $data = [], ?string $lienAction = null, ?string $campagneId = null): void
    {
        foreach ($users as $user) {
            if ($user instanceof User) {
                $this->envoyerAUtilisateur($user, $titre, $message, $data, $lienAction, $campagneId);
            }
        }
    }

    /**
     * Appel HTTP brut vers l'API Expo Push (endpoint public, sans authentification).
     */
    private function envoyerExpo(array $tokens, string $titre, string $message, array $data): bool
    {
        $messages = array_map(fn (string $token) => [
            'to'    => $token,
            'title' => $titre,
            'body'  => $message,
            'data'  => $data,
            'sound' => 'default',
        ], array_values(array_unique(array_filter($tokens))));

        if (empty($messages)) {
            return false;
        }

        try {
            $response = Http::timeout(10)->post(self::EXPO_PUSH_URL, $messages);
            if (!$response->successful()) {
                Log::error('[Push] Échec HTTP ' . $response->status() . ' : ' . $response->body());
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::error("[Push] Échec d'envoi : " . $e->getMessage());
            return false;
        }
    }
}
