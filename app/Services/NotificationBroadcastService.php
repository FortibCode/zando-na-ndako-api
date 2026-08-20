<?php

namespace App\Services;

use App\Models\CampagneNotification;
use App\Models\Notification;
use App\Models\User;

/**
 * Résout la cible d'une campagne admin (tous / clients / vendeurs / livreurs / diaspora) et
 * diffuse via PushService et/ou SmsService, réutilisé à l'identique par l'envoi immédiat
 * (AdminController) et par la commande planifiée (EnvoyerCampagnesNotificationsProgrammees).
 */
class NotificationBroadcastService
{
    public function __construct(private PushService $push, private SmsService $sms)
    {
    }

    public function envoyer(CampagneNotification $campagne): void
    {
        $users = $this->resoudreDestinataires($campagne->cible_type);
        $reussis = 0;

        foreach ($users as $user) {
            $unSucces = false;

            if ($campagne->envoyer_push) {
                $ok = $this->push->envoyerAUtilisateur($user, $campagne->titre, $campagne->message, [], $campagne->lien_action, $campagne->id);
                $unSucces = $unSucces || $ok;
            }

            if ($campagne->envoyer_sms && $user->telephone) {
                $ok = $this->sms->envoyer($user->telephone, "{$campagne->titre} : {$campagne->message}");
                Notification::create([
                    'user_id' => $user->id,
                    'campagne_id' => $campagne->id,
                    'titre' => $campagne->titre,
                    'message' => $campagne->message,
                    'type_canal' => 'sms',
                    'lien_action' => $campagne->lien_action,
                    'date_envoi' => now(),
                    'statut_envoi' => $ok ? 'envoye' : 'echoue',
                    'canal_secours_utilise' => !$ok,
                ]);
                $unSucces = $unSucces || $ok;
            }

            if ($unSucces) {
                $reussis++;
            }
        }

        $campagne->update([
            'statut' => 'envoyee',
            'nombre_destinataires' => $users->count(),
            'nombre_envois_reussis' => $reussis,
        ]);
    }

    private function resoudreDestinataires(string $cible)
    {
        return match ($cible) {
            'clients' => User::where('statut_compte', 'actif')->where('type_utilisateur', 'client')->get(),
            'vendeurs' => User::where('statut_compte', 'actif')->where('type_utilisateur', 'vendeur')->get(),
            'livreurs' => User::where('statut_compte', 'actif')->where('type_utilisateur', 'livreur')->get(),
            'diaspora' => User::where('statut_compte', 'actif')->where('type_utilisateur', 'client')
                ->whereHas('client', fn ($q) => $q->where('est_diaspora', true))->get(),
            default => User::where('statut_compte', 'actif')->whereIn('type_utilisateur', ['client', 'vendeur', 'livreur'])->get(),
        };
    }
}
