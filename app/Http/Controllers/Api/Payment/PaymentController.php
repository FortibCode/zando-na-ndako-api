<?php

namespace App\Http\Controllers\Api\Payment;

use App\Http\Controllers\Controller;
use App\Models\Paiement;
use App\Models\Commande;
use App\Support\DashboardCache;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class PaymentController extends Controller
{
    // POST /api/payment/stripe/init
    //
    // Crée un vrai PaymentIntent Stripe dès que STRIPE_SECRET est configurée. Le montant de la
    // commande (montant_total) est déjà exprimé dans la devise de paiement du client
    // (devise_paiement — EUR/USD pour la diaspora), donc utilisé tel quel, converti en la plus
    // petite unité monétaire attendue par Stripe (centimes).
    public function initierStripe(Request $request): JsonResponse
    {
        $validated = $request->validate(['commande_id'=>'required|uuid|exists:commandes,id']);
        $commande = Commande::findOrFail($validated['commande_id']);

        $secret = config('services.stripe.secret');
        if (!$secret) {
            Log::info('[Stripe] STRIPE_SECRET non configurée — simulation (mode dev).');
            return response()->json(['success'=>true,'message'=>'Redirection vers Stripe (simulation, clé non configurée).','data'=>['url'=>'https://stripe.com/pay/...','client_secret'=>null]]);
        }

        try {
            $intent = (new StripeClient($secret))->paymentIntents->create([
                'amount'   => (int) round((float) $commande->montant_total * 100),
                'currency' => strtolower($commande->devise_paiement),
                'metadata' => ['commande_id' => $commande->id, 'numero_commande' => $commande->numero_commande],
            ]);
        } catch (ApiErrorException $e) {
            Log::error("[Stripe] Échec création PaymentIntent : {$e->getMessage()}");
            return response()->json(['success'=>false,'message'=>"Impossible d'initier le paiement Stripe."], 502);
        }

        return response()->json(['success'=>true,'message'=>'Paiement Stripe initié.','data'=>['client_secret'=>$intent->client_secret,'payment_intent_id'=>$intent->id]]);
    }

    // POST /api/payment/stripe/confirm
    //
    // Ne fait plus confiance à un payment_intent_id fourni par le client : on va vérifier auprès
    // de Stripe que ce PaymentIntent existe réellement et a le statut "succeeded" avant de marquer
    // le paiement comme validé — avant ce correctif, n'importe quel id de forme plausible suffisait
    // à valider une commande jamais payée.
    public function confirmerStripe(Request $request): JsonResponse
    {
        $validated = $request->validate(['commande_id'=>'required|uuid|exists:commandes,id','payment_intent_id'=>'required|string']);
        $commande = Commande::findOrFail($validated['commande_id']);

        $secret = config('services.stripe.secret');
        if (!$secret) {
            Log::info('[Stripe] STRIPE_SECRET non configurée — confirmation acceptée sans vérification (mode dev).');
            $paiement = Paiement::create(['commande_id'=>$commande->id,'methode'=>'stripe','montant'=>$commande->montant_total,'devise'=>$commande->devise_paiement,'statut'=>'valide','date_paiement'=>now(),'reference_transaction_externe'=>$validated['payment_intent_id']]);
            DashboardCache::bump();
            return response()->json(['success'=>true,'message'=>'Paiement confirmé (simulation).','data'=>$paiement]);
        }

        try {
            $intent = (new StripeClient($secret))->paymentIntents->retrieve($validated['payment_intent_id']);
        } catch (ApiErrorException $e) {
            Log::warning("[Stripe] PaymentIntent introuvable : {$e->getMessage()}");
            return response()->json(['success'=>false,'message'=>'Paiement Stripe introuvable.'], 400);
        }

        if ($intent->status !== 'succeeded') {
            return response()->json(['success'=>false,'message'=>"Le paiement Stripe n'est pas encore confirmé (statut : {$intent->status})."], 400);
        }

        $paiement = Paiement::create([
            'commande_id' => $commande->id,
            'methode'     => 'stripe',
            'montant'     => $intent->amount / 100,
            'devise'      => strtoupper($intent->currency),
            'statut'      => 'valide',
            'date_paiement' => now(),
            'reference_transaction_externe' => $intent->id,
        ]);
        DashboardCache::bump();
        return response()->json(['success'=>true,'message'=>'Paiement confirmé.','data'=>$paiement]);
    }

    // POST /api/payment/paypal/init
    //
    // Crée une vraie commande PayPal (Orders API v2) dès que PAYPAL_CLIENT_ID/SECRET sont
    // configurés. PayPal n'a pas de SDK officiel maintenu pour Laravel — on appelle directement
    // son API REST via le client Http de Laravel, ce qui évite une dépendance supplémentaire.
    public function initierPayPal(Request $request): JsonResponse
    {
        $validated = $request->validate(['commande_id'=>'required|uuid|exists:commandes,id']);
        $commande = Commande::findOrFail($validated['commande_id']);

        if (!config('services.paypal.client_id')) {
            Log::info('[PayPal] PAYPAL_CLIENT_ID non configurée — simulation (mode dev).');
            return response()->json(['success'=>true,'message'=>'Redirection vers PayPal (simulation, clé non configurée).','data'=>['url'=>'https://paypal.com/pay/...','order_id'=>null]]);
        }

        $token = $this->paypalAccessToken();
        if (!$token) {
            return response()->json(['success'=>false,'message'=>"Impossible de contacter PayPal."], 502);
        }

        $response = Http::withToken($token)->post($this->paypalBaseUrl().'/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $commande->numero_commande,
                'amount' => [
                    'currency_code' => strtoupper($commande->devise_paiement),
                    'value' => number_format((float) $commande->montant_total, 2, '.', ''),
                ],
            ]],
        ]);

        if (!$response->successful()) {
            Log::error('[PayPal] Échec création commande : ' . $response->body());
            return response()->json(['success'=>false,'message'=>"Impossible d'initier le paiement PayPal."], 502);
        }

        return response()->json(['success'=>true,'message'=>'Paiement PayPal initié.','data'=>['order_id'=>$response->json('id')]]);
    }

    // POST /api/payment/paypal/confirm
    //
    // Capture réellement la commande PayPal auprès de leur API (au lieu de valider aveuglément un
    // paypal_order_id fourni par le client) et vérifie que la capture a bien le statut "COMPLETED".
    public function confirmerPayPal(Request $request): JsonResponse
    {
        $validated = $request->validate(['commande_id'=>'required|uuid|exists:commandes,id','paypal_order_id'=>'required|string']);
        $commande = Commande::findOrFail($validated['commande_id']);

        if (!config('services.paypal.client_id')) {
            Log::info('[PayPal] PAYPAL_CLIENT_ID non configurée — confirmation acceptée sans vérification (mode dev).');
            $paiement = Paiement::create(['commande_id'=>$commande->id,'methode'=>'paypal','montant'=>$commande->montant_total,'devise'=>$commande->devise_paiement,'statut'=>'valide','date_paiement'=>now(),'reference_transaction_externe'=>$validated['paypal_order_id']]);
            DashboardCache::bump();
            return response()->json(['success'=>true,'message'=>'Paiement PayPal confirmé (simulation).','data'=>$paiement]);
        }

        $token = $this->paypalAccessToken();
        if (!$token) {
            return response()->json(['success'=>false,'message'=>"Impossible de contacter PayPal."], 502);
        }

        $capture = Http::withToken($token)->post($this->paypalBaseUrl()."/v2/checkout/orders/{$validated['paypal_order_id']}/capture");

        if (!$capture->successful() || $capture->json('status') !== 'COMPLETED') {
            Log::warning('[PayPal] Capture non complétée : ' . $capture->body());
            return response()->json(['success'=>false,'message'=>"Le paiement PayPal n'a pas pu être confirmé."], 400);
        }

        $montantCapture = $capture->json('purchase_units.0.payments.captures.0.amount.value') ?? $commande->montant_total;
        $deviseCapture  = $capture->json('purchase_units.0.payments.captures.0.amount.currency_code') ?? $commande->devise_paiement;

        $paiement = Paiement::create([
            'commande_id' => $commande->id,
            'methode'     => 'paypal',
            'montant'     => $montantCapture,
            'devise'      => $deviseCapture,
            'statut'      => 'valide',
            'date_paiement' => now(),
            'reference_transaction_externe' => $validated['paypal_order_id'],
        ]);
        DashboardCache::bump();
        return response()->json(['success'=>true,'message'=>'Paiement PayPal confirmé.','data'=>$paiement]);
    }

    private function paypalBaseUrl(): string
    {
        return config('services.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private function paypalAccessToken(): ?string
    {
        $response = Http::asForm()
            ->withBasicAuth(config('services.paypal.client_id'), config('services.paypal.secret'))
            ->post($this->paypalBaseUrl().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        if (!$response->successful()) {
            Log::error('[PayPal] Échec authentification : ' . $response->body());
            return null;
        }

        return $response->json('access_token');
    }

    // POST /api/payment/carte-locale/init
    public function initierCarteLocale(Request $request): JsonResponse
    {
        $validated = $request->validate(['commande_id'=>'required|uuid|exists:commandes,id']);
        $commande = Commande::findOrFail($validated['commande_id']);
        $paiement = Paiement::create(['commande_id'=>$commande->id,'methode'=>'carte_locale','montant'=>$commande->montant_total,'devise'=>'FCFA','statut'=>'en_attente']);
        DashboardCache::bump();
        return response()->json(['success'=>true,'message'=>'Paiement par carte locale initié.','data'=>$paiement]);
    }

    // POST /api/payment/carte-locale/confirm
    public function confirmerCarteLocale(Request $request): JsonResponse
    {
        $validated = $request->validate(['paiement_id'=>'required|uuid|exists:paiements,id','reference'=>'required|string']);
        Paiement::findOrFail($validated['paiement_id'])->update(['statut'=>'valide','date_paiement'=>now(),'reference_transaction_externe'=>$validated['reference']]);
        DashboardCache::bump();
        return response()->json(['success'=>true,'message'=>'Paiement par carte confirmé.']);
    }

    // POST /api/payment/mtn-momo/init
    public function initierMtnMoMo(Request $request): JsonResponse
    {
        $validated = $request->validate(['commande_id'=>'required|uuid|exists:commandes,id']);
        $commande = Commande::findOrFail($validated['commande_id']);
        $paiement = Paiement::create(['commande_id'=>$commande->id,'methode'=>'mtn_momo','montant'=>$commande->montant_total,'devise'=>'FCFA','statut'=>'en_attente']);
        DashboardCache::bump();
        return response()->json(['success'=>true,'message'=>'Demande de paiement MTN MoMo envoyée.','data'=>$paiement]);
    }

    // POST /api/payment/mtn-momo/confirm
    public function confirmerMtnMoMo(Request $request): JsonResponse
    {
        $validated = $request->validate(['paiement_id'=>'required|uuid|exists:paiements,id','reference'=>'required|string']);
        Paiement::findOrFail($validated['paiement_id'])->update(['statut'=>'valide','date_paiement'=>now(),'reference_transaction_externe'=>$validated['reference']]);
        DashboardCache::bump();
        return response()->json(['success'=>true,'message'=>'Paiement MTN MoMo confirmé.']);
    }

    // POST /api/payment/airtel-money/init
    public function initierAirtelMoney(Request $request): JsonResponse
    {
        $validated = $request->validate(['commande_id'=>'required|uuid|exists:commandes,id']);
        $commande = Commande::findOrFail($validated['commande_id']);
        $paiement = Paiement::create(['commande_id'=>$commande->id,'methode'=>'airtel_money','montant'=>$commande->montant_total,'devise'=>'FCFA','statut'=>'en_attente']);
        DashboardCache::bump();
        return response()->json(['success'=>true,'message'=>'Demande de paiement Airtel Money envoyée.','data'=>$paiement]);
    }

    // POST /api/payment/airtel-money/confirm
    public function confirmerAirtelMoney(Request $request): JsonResponse
    {
        $validated = $request->validate(['paiement_id'=>'required|uuid|exists:paiements,id','reference'=>'required|string']);
        Paiement::findOrFail($validated['paiement_id'])->update(['statut'=>'valide','date_paiement'=>now(),'reference_transaction_externe'=>$validated['reference']]);
        DashboardCache::bump();
        return response()->json(['success'=>true,'message'=>'Paiement Airtel Money confirmé.']);
    }

    // POST /api/payment/livraison/confirm
    public function confirmerPaiementLivraison(Request $request): JsonResponse
    {
        $validated = $request->validate(['commande_id'=>'required|uuid|exists:commandes,id']);
        $commande = Commande::findOrFail($validated['commande_id']);
        $paiement = Paiement::create(['commande_id'=>$commande->id,'methode'=>'paiement_livraison','montant'=>$commande->montant_total,'devise'=>'FCFA','statut'=>'en_attente']);
        DashboardCache::bump();
        return response()->json(['success'=>true,'message'=>'Paiement à la livraison enregistré.','data'=>$paiement]);
    }

    // GET /api/payment/{id}/statut
    public function statut(Request $request, string $id): JsonResponse
    {
        $paiement = Paiement::findOrFail($id);
        return response()->json(['success'=>true,'data'=>['id'=>$paiement->id,'methode'=>$paiement->methode,'montant'=>$paiement->montant,'devise'=>$paiement->devise,'statut'=>$paiement->statut,'date_paiement'=>$paiement->date_paiement]]);
    }
}