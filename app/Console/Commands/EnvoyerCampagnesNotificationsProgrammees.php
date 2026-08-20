<?php

namespace App\Console\Commands;

use App\Models\CampagneNotification;
use App\Services\NotificationBroadcastService;
use Illuminate\Console\Command;

class EnvoyerCampagnesNotificationsProgrammees extends Command
{
    protected $signature = 'notifications:envoyer-programmees';

    protected $description = "Envoie les campagnes de notifications admin dont la date d'envoi programmée est atteinte.";

    public function handle(NotificationBroadcastService $broadcast): int
    {
        $campagnes = CampagneNotification::where('statut', 'programmee')
            ->where(function ($q) {
                $q->whereNull('date_envoi')->orWhere('date_envoi', '<=', now());
            })
            ->get();

        foreach ($campagnes as $campagne) {
            try {
                $broadcast->envoyer($campagne);
                $this->info("Campagne envoyée : {$campagne->titre} ({$campagne->id})");
            } catch (\Throwable $e) {
                $campagne->update(['statut' => 'echouee']);
                $this->error("Échec de la campagne {$campagne->id} : " . $e->getMessage());
            }
        }

        if ($campagnes->isEmpty()) {
            $this->info('Aucune campagne programmée due.');
        }

        return self::SUCCESS;
    }
}
