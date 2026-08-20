<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

class SmsService
{
    private ?Client $client;
    private ?string $from;

    public function __construct()
    {
        $sid   = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $this->from = config('services.twilio.from');

        $this->client = ($sid && $token) ? new Client($sid, $token) : null;
    }

    /**
     * Envoie un SMS. Retourne true si envoyé (ou simulé en absence de configuration Twilio).
     */
    public function envoyer(string $telephone, string $message): bool
    {
        if (!$this->client || !$this->from) {
            // Twilio non configuré (ex: environnement de dev) : on journalise au lieu d'échouer.
            Log::info("[SMS non envoyé - Twilio non configuré] À {$telephone} : {$message}");
            return false;
        }

        try {
            $this->client->messages->create($telephone, [
                'from' => $this->from,
                'body' => $message,
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::error("[SMS] Échec d'envoi à {$telephone} : " . $e->getMessage());
            return false;
        }
    }
}
