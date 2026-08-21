<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MtnMomoService
{
    private string $baseUrl;
    private string $environment;
    private string $targetEnvironment;
    private string $currency;

    // Collection Credentials
    private ?string $collectionUserId;
    private ?string $collectionApiKey;
    private ?string $collectionSubscriptionKey;

    // Disbursement Credentials
    private ?string $disbursementUserId;
    private ?string $disbursementApiKey;
    private ?string $disbursementSubscriptionKey;

    public function __construct()
    {
        $this->environment = config('services.mtn_momo.environment', 'live');
        $this->targetEnvironment = config('services.mtn_momo.target_environment', $this->environment === 'live' ? 'mtncongo' : 'sandbox');
        $this->currency = config('services.mtn_momo.currency', 'XAF');

        $this->baseUrl = ($this->environment === 'live' || $this->environment === 'production')
            ? 'https://proxy.momoapi.mtn.com'
            : 'https://sandbox.momodeveloper.mtn.com';

        $this->collectionUserId = config('services.mtn_momo.collection.user_id');
        $this->collectionApiKey = config('services.mtn_momo.collection.api_key');
        $this->collectionSubscriptionKey = config('services.mtn_momo.collection.subscription_key');

        $this->disbursementUserId = config('services.mtn_momo.disbursement.user_id');
        $this->disbursementApiKey = config('services.mtn_momo.disbursement.api_key');
        $this->disbursementSubscriptionKey = config('services.mtn_momo.disbursement.subscription_key');
    }

    /**
     * Vérifie si les identifiants MTN MoMo Collection sont configurés
     */
    public function isCollectionConfigured(): bool
    {
        return !empty($this->collectionUserId) &&
               !empty($this->collectionApiKey) &&
               !empty($this->collectionSubscriptionKey);
    }

    /**
     * Obtient un jeton d'accès OAuth 2.0 Bearer pour l'API Collection
     */
    public function getCollectionToken(): ?string
    {
        if (!$this->isCollectionConfigured()) {
            return null;
        }

        $credentials = base64_encode("{$this->collectionUserId}:{$this->collectionApiKey}");

        $response = Http::withHeaders([
            'Authorization' => "Basic {$credentials}",
            'Ocp-Apim-Subscription-Key' => $this->collectionSubscriptionKey,
        ])->post("{$this->baseUrl}/collection/token/");

        if (!$response->successful()) {
            Log::error('[MTN MoMo Collection Token Error] Status ' . $response->status() . ' : ' . $response->body());
            return null;
        }

        return $response->json('access_token');
    }

    /**
     * Initie un paiement Mobile Money (RequestToPay)
     *
     * @param string $telephone Numéro du client (ex: 242061234567 ou 061234567)
     * @param float $montant Montant du paiement
     * @param string $commandeNumero Numéro unique de la commande Zando
     * @param string|null $customExternalId Identifiant externe optionnel
     * @return array|null [success => bool, reference_id => string, status => string, message => string]
     */
    public function requestToPay(string $telephone, float $montant, string $commandeNumero, ?string $customExternalId = null): ?array
    {
        if (!$this->isCollectionConfigured()) {
            Log::info("[MTN MoMo] Collection non configurée — mode simulation activé pour la commande {$commandeNumero}");
            $referenceId = (string) Str::uuid();
            return [
                'success'      => true,
                'mode'         => 'simulation',
                'reference_id' => $referenceId,
                'external_id'  => $customExternalId ?? "ZANDO-CMD-{$commandeNumero}-" . time(),
                'status'       => 'PENDING',
                'message'      => 'Demande de paiement MTN MoMo simulée avec succès.',
            ];
        }

        $token = $this->getCollectionToken();
        if (!$token) {
            return [
                'success' => false,
                'message' => 'Impossible d\'obtenir le jeton d\'accès MTN MoMo.',
            ];
        }

        // Formater le téléphone au format MSISDN (242...)
        $cleanPhone = preg_replace('/[^0-9]/', '', $telephone);
        if (!str_starts_with($cleanPhone, '242') && strlen($cleanPhone) === 9) {
            $cleanPhone = "242{$cleanPhone}";
        }

        $referenceId = (string) Str::uuid();
        $externalId = $customExternalId ?? "ZANDO-CMD-{$commandeNumero}-" . time();
        $currencyToUse = ($this->environment === 'sandbox') ? 'EUR' : $this->currency;

        $payload = [
            'amount'       => (string) round($montant),
            'currency'     => $currencyToUse,
            'externalId'   => $externalId,
            'payer'        => [
                'partyIdType' => 'MSISDN',
                'partyId'     => $cleanPhone,
            ],
            'payerMessage' => "Paiement commande Zando #{$commandeNumero}",
            'payeeNote'    => "Reglement Zando na Ndako",
        ];

        $response = Http::withHeaders([
            'Authorization'             => "Bearer {$token}",
            'X-Reference-Id'            => $referenceId,
            'X-Target-Environment'      => $this->targetEnvironment,
            'Ocp-Apim-Subscription-Key' => $this->collectionSubscriptionKey,
            'Content-Type'              => 'application/json',
        ])->post("{$this->baseUrl}/collection/v1_0/requesttopay", $payload);

        // HTTP 202 Accepted indique que la demande a été transmise avec succès au téléphone de l'utilisateur
        if ($response->status() === 202 || $response->successful()) {
            Log::info("[MTN MoMo RequestToPay Transmis] Téléphone : {$cleanPhone} | Montant : {$montant} FCFA | ExternalId : {$externalId} | Ref : {$referenceId}");
            return [
                'success'      => true,
                'mode'         => $this->environment,
                'reference_id' => $referenceId,
                'external_id'  => $externalId,
                'status'       => 'PENDING',
                'message'      => 'Demande de paiement transmise au téléphone du client.',
            ];
        }

        Log::error("[MTN MoMo RequestToPay Error] Status {$response->status()}: " . $response->body());
        return [
            'success' => false,
            'message' => 'Échec lors de l\'envoi de la demande de paiement MTN MoMo : ' . $response->body(),
        ];
    }

    /**
     * Vérifie le statut d'un paiement initié via son referenceId
     */
    public function getPaymentStatus(string $referenceId): ?array
    {
        if (!$this->isCollectionConfigured()) {
            return [
                'success' => true,
                'status'  => 'SUCCESSFUL',
                'mode'    => 'simulation',
            ];
        }

        $token = $this->getCollectionToken();
        if (!$token) {
            return null;
        }

        $response = Http::withHeaders([
            'Authorization'             => "Bearer {$token}",
            'X-Target-Environment'      => $this->targetEnvironment,
            'Ocp-Apim-Subscription-Key' => $this->collectionSubscriptionKey,
        ])->get("{$this->baseUrl}/collection/v1_0/requesttopay/{$referenceId}");

        if (!$response->successful()) {
            Log::error("[MTN MoMo Status Check Error] " . $response->body());
            return null;
        }

        $data = $response->json();
        $status = $data['status'] ?? 'UNKNOWN';
        $reason = $data['reason'] ?? null;

        $friendlyMessage = match ($reason) {
            'LOW_BALANCE_OR_PAYEE_LIMIT_REACHED_OR_NOT_ALLOWED', 'NOT_ENOUGH_FUNDS' => 'Solde MTN Mobile Money insuffisant ou limite de transaction atteinte sur ce numéro.',
            'PAYER_NOT_FOUND' => 'Le numéro de téléphone saisi n\'est pas un compte MTN Mobile Money actif.',
            'APPROVAL_REJECTED' => 'Le paiement a été refusé depuis le téléphone du client.',
            'EXPIRED' => 'La demande de paiement sur le téléphone a expiré.',
            default => "Statut MTN MoMo : {$status}" . ($reason ? " ({$reason})" : ''),
        };

        return [
            'success'                  => true,
            'status'                   => $status, // SUCCESSFUL, PENDING, FAILED
            'reason'                   => $reason,
            'message'                  => $friendlyMessage,
            'financial_transaction_id' => $data['financialTransactionId'] ?? null,
            'raw'                      => $data,
        ];
    }
}
