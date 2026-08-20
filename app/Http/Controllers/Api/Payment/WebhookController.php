<?php

namespace App\Http\Controllers\Api\Payment;

use App\Http\Controllers\Controller;
use App\Models\Paiement;
use App\Models\Commande;
use App\Support\DashboardCache;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class WebhookController extends Controller
{
    // POST /api/webhooks/stripe
    //
    // Sans vérification de signature, n'importe qui connaissant cette URL peut poster un faux
    // événement "payment_intent.succeeded" et faire valider une commande jamais payée. Stripe
    // signe chaque webhook avec le secret propre à l'endpoint (distinct de la clé API) — on le
    // vérifie dès qu'il est configuré.
    public function handleStripe(Request $request): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');

        if ($secret) {
            try {
                $event = Webhook::constructEvent(
                    $request->getContent(),
                    $request->header('Stripe-Signature', ''),
                    $secret
                );
            } catch (UnexpectedValueException|SignatureVerificationException $e) {
                Log::warning("[Webhook Stripe] Signature invalide : {$e->getMessage()}");
                return response()->json(['success' => false, 'message' => 'Signature invalide.'], 400);
            }
            $eventType = $event->type;
            $paymentIntentId = $event->data->object->id ?? '';
        } else {
            // STRIPE_WEBHOOK_SECRET non configuré (ex: environnement de dev sans compte Stripe
            // réel) : on journalise et on traite la requête sans vérification, comme SmsService
            // le fait déjà quand Twilio n'est pas configuré.
            Log::info('[Webhook Stripe] STRIPE_WEBHOOK_SECRET non configuré — signature non vérifiée.');
            $payload = $request->all();
            $eventType = $payload['type'] ?? '';
            $paymentIntentId = $payload['data']['object']['id'] ?? '';
        }

        match ($eventType) {
            'payment_intent.succeeded' => $this->handlePaymentSucceeded($paymentIntentId),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($paymentIntentId),
            default => null,
        };

        return response()->json(['success' => true, 'message' => 'Webhook reçu.']);
    }

    private function handlePaymentSucceeded(string $paymentIntentId): void
    {
        $paiement = Paiement::where('reference_transaction_externe', $paymentIntentId)->first();
        if ($paiement) {
            $paiement->update(['statut' => 'valide', 'date_paiement' => now()]);
            DashboardCache::bump();
        }
    }

    private function handlePaymentFailed(string $paymentIntentId): void
    {
        $paiement = Paiement::where('reference_transaction_externe', $paymentIntentId)->first();
        if ($paiement) {
            $paiement->update(['statut' => 'echoue']);
        }
    }
}