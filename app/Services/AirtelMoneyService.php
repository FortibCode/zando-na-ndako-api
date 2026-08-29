<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Même architecture que MtnMomoService : appelle la vraie API Airtel Money Open API (Collections)
// dès que des identifiants marchands sont configurés, sinon simule proprement (comme le fait déjà
// Stripe/PayPal/MTN dans ce projet) plutôt que d'accepter aveuglément une référence fournie par le
// client — c'est ce dernier comportement (confirmerAirtelMoney historique) qui permettait à
// n'importe quel client de marquer sa propre commande comme payée sans jamais contacter Airtel.
//
// Les noms de champs/codes de statut ci-dessous suivent la documentation publique de l'Airtel
// Africa Open API (Collections) ; comme aucun identifiant marchand réel n'était disponible au
// moment d'écrire ce service, ils sont à vérifier contre un vrai environnement sandbox Airtel avant
// mise en production (voir les commentaires marqués "à vérifier").
class AirtelMoneyService
{
    private string $baseUrl;
    private string $environment;
    private string $country;
    private string $currency;
    private ?string $clientId;
    private ?string $clientSecret;

    public function __construct()
    {
        $this->environment = config('services.airtel_money.environment', 'sandbox');
        $this->country = config('services.airtel_money.country', 'CG');
        $this->currency = config('services.airtel_money.currency', 'XAF');
        $this->clientId = config('services.airtel_money.client_id');
        $this->clientSecret = config('services.airtel_money.client_secret');

        $this->baseUrl = ($this->environment === 'live' || $this->environment === 'production')
            ? 'https://openapi.airtel.africa'
            : 'https://openapiuat.airtel.africa';
    }

    /**
     * Vérifie si les identifiants marchands Airtel Money sont configurés
     */
    public function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }

    /**
     * Obtient un jeton d'accès OAuth 2.0 Bearer
     */
    public function getAccessToken(): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post("{$this->baseUrl}/auth/oauth2/token", [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type'    => 'client_credentials',
            ]);

        if (!$response->successful()) {
            Log::error('[Airtel Money Token Error] Status ' . $response->status() . ' : ' . $response->body());
            return null;
        }

        return $response->json('access_token');
    }

    /**
     * Initie un paiement Mobile Money (push USSD)
     *
     * @param string $telephone Numéro du client (ex: 242061234567 ou 061234567)
     * @param float $montant Montant du paiement
     * @param string $commandeNumero Numéro unique de la commande Zando
     * @return array|null [success => bool, reference_id => string, status => string, message => string]
     */
    public function requestToPay(string $telephone, float $montant, string $commandeNumero): ?array
    {
        if (!$this->isConfigured()) {
            Log::info("[Airtel Money] Identifiants non configurés — mode simulation activé pour la commande {$commandeNumero}");
            return [
                'success'      => true,
                'mode'         => 'simulation',
                'reference_id' => (string) Str::uuid(),
                'status'       => 'PENDING',
                'message'      => 'Demande de paiement Airtel Money simulée avec succès.',
            ];
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return [
                'success' => false,
                'message' => "Impossible d'obtenir le jeton d'accès Airtel Money.",
            ];
        }

        // À vérifier : le MSISDN attendu par l'API Airtel est le numéro local (sans indicatif pays,
        // celui-ci étant déjà porté par X-Country/subscriber.country) — comportement à confirmer
        // contre un vrai environnement sandbox.
        $cleanPhone = preg_replace('/[^0-9]/', '', $telephone);
        if (str_starts_with($cleanPhone, '242') && strlen($cleanPhone) > 9) {
            $cleanPhone = substr($cleanPhone, 3);
        }

        $transactionId = (string) Str::uuid();

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type'  => 'application/json',
            'Accept'        => '*/*',
            'X-Country'     => $this->country,
            'X-Currency'    => $this->currency,
        ])->post("{$this->baseUrl}/merchant/v1/payments/", [
            'reference' => "Commande Zando #{$commandeNumero}",
            'subscriber' => [
                'country'  => $this->country,
                'currency' => $this->currency,
                'msisdn'   => $cleanPhone,
            ],
            'transaction' => [
                'amount'   => (int) round($montant),
                'country'  => $this->country,
                'currency' => $this->currency,
                'id'       => $transactionId,
            ],
        ]);

        // À vérifier : status.success est le champ documenté par Airtel pour indiquer que la
        // demande a bien été transmise au téléphone du client (par opposition au succès final du
        // paiement, confirmé uniquement via getPaymentStatus).
        if ($response->successful() && $response->json('status.success') === true) {
            Log::info("[Airtel Money RequestToPay Transmis] Téléphone : {$cleanPhone} | Montant : {$montant} FCFA | Transaction : {$transactionId}");
            return [
                'success'      => true,
                'mode'         => $this->environment,
                'reference_id' => $response->json('data.transaction.id') ?? $transactionId,
                'status'       => 'PENDING',
                'message'      => 'Demande de paiement transmise au téléphone du client.',
            ];
        }

        Log::error("[Airtel Money RequestToPay Error] Status {$response->status()}: " . $response->body());
        return [
            'success' => false,
            'message' => 'Échec lors de l\'envoi de la demande de paiement Airtel Money : ' . ($response->json('status.message') ?? $response->body()),
        ];
    }

    /**
     * Vérifie le statut d'un paiement initié via son identifiant de transaction
     */
    public function getPaymentStatus(string $transactionId): ?array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => true,
                'status'  => 'SUCCESSFUL',
                'mode'    => 'simulation',
            ];
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return null;
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => '*/*',
            'X-Country'     => $this->country,
            'X-Currency'    => $this->currency,
        ])->get("{$this->baseUrl}/standard/v1/payments/{$transactionId}");

        if (!$response->successful()) {
            Log::error("[Airtel Money Status Check Error] " . $response->body());
            return null;
        }

        // À vérifier : codes de statut documentés par Airtel — TS (Transaction Successful),
        // TF (Transaction Failed), tout le reste (TIP/TA/inconnu) traité comme en attente plutôt
        // que de risquer de valider ou d'échouer un paiement encore en cours.
        $rawStatus = $response->json('data.transaction.status');
        $status = match ($rawStatus) {
            'TS' => 'SUCCESSFUL',
            'TF' => 'FAILED',
            default => 'PENDING',
        };

        return [
            'success'                  => true,
            'status'                   => $status,
            'message'                  => $response->json('data.transaction.message') ?? "Statut Airtel Money : {$rawStatus}",
            'financial_transaction_id' => $response->json('data.transaction.airtel_money_id') ?? $transactionId,
            'raw'                      => $response->json(),
        ];
    }
}
