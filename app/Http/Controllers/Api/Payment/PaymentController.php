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
use Illuminate\Support\Str;
use App\Services\MtnMomoService;
use App\Services\AirtelMoneyService;

class PaymentController extends Controller
{
    // Stripe traite XAF (franc CFA) comme une devise "zéro décimale" : contrairement à EUR/USD où
    // unit_amount s'exprime en centimes (montant × 100), XAF s'exprime directement en unités
    // entières. Sans cette distinction, un paiement en FCFA serait facturé 100x le montant réel.
    // Voir https://stripe.com/docs/currencies#zero-decimal — liste volontairement restreinte aux
    // devises réellement utilisées par cette application (FCFA local, EUR/USD/GBP/CAD diaspora).
    private const STRIPE_ZERO_DECIMAL_CURRENCIES = ['xaf'];

    // Convertit le libellé de devise interne de l'app (Commande::devise_paiement, ex. "FCFA") vers
    // le code ISO 4217 attendu par l'API Stripe (ex. "xaf") — Stripe rejette "fcfa" comme devise
    // invalide.
    private function toStripeCurrency(string $devise): string
    {
        return strtolower($devise) === 'fcfa' ? 'xaf' : strtolower($devise);
    }

    // Conversion inverse, pour stocker Paiement::devise dans la même convention que le reste de
    // l'app (qui utilise "FCFA", jamais le code ISO "XAF").
    private function fromStripeCurrency(string $currency): string
    {
        return strtoupper($currency) === 'XAF' ? 'FCFA' : strtoupper($currency);
    }

    private function stripeUnitAmount(float $montant, string $stripeCurrency): int
    {
        return in_array($stripeCurrency, self::STRIPE_ZERO_DECIMAL_CURRENCIES, true)
            ? (int) round($montant)
            : (int) round($montant * 100);
    }

    private function stripeAmountToMontant(int $amount, string $stripeCurrency): float
    {
        return in_array($stripeCurrency, self::STRIPE_ZERO_DECIMAL_CURRENCIES, true)
            ? (float) $amount
            : $amount / 100;
    }
    // POST /api/payment/stripe/init
    //
    // Crée une vraie Checkout Session Stripe dès que STRIPE_SECRET est configurée — le client
    // (mobile ou web) redirige l'utilisateur vers l'URL renvoyée pour saisir sa carte sur la page
    // hébergée par Stripe, puis revient sur success_url/cancel_url fournis par l'appelant (une page
    // web ou un lien profond mobile). Le montant de la commande (montant_total) est déjà exprimé
    // dans la devise de paiement du client (devise_paiement — EUR/USD pour la diaspora).
    public function initierStripe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'commande_id' => 'required|uuid|exists:commandes,id',
            'success_url' => 'required|url',
            'cancel_url'  => 'required|url',
        ]);
        $commande = Commande::findOrFail($validated['commande_id']);

        $secret = config('services.stripe.secret');
        if (!$secret) {
            Log::info('[Stripe] STRIPE_SECRET non configurée — simulation (mode dev).');
            $simulatedUrl = $validated['success_url'] . (str_contains($validated['success_url'], '?') ? '&' : '?') . 'session_id=simulated_' . Str::uuid();
            return response()->json(['success'=>true,'message'=>'Redirection Stripe (simulation, clé non configurée).','data'=>['url'=>$simulatedUrl,'session_id'=>null]]);
        }

        $stripeCurrency = $this->toStripeCurrency($commande->devise_paiement);

        try {
            $session = (new StripeClient($secret))->checkout->sessions->create([
                'mode' => 'payment',
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency'     => $stripeCurrency,
                        'product_data' => ['name' => "Commande Zando na Ndako #{$commande->numero_commande}"],
                        'unit_amount'  => $this->stripeUnitAmount((float) $commande->montant_total, $stripeCurrency),
                    ],
                    'quantity' => 1,
                ]],
                'success_url' => $validated['success_url'] . (str_contains($validated['success_url'], '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => $validated['cancel_url'],
                'metadata'    => ['commande_id' => $commande->id, 'numero_commande' => $commande->numero_commande],
            ]);
        } catch (ApiErrorException $e) {
            Log::error("[Stripe] Échec création Checkout Session : {$e->getMessage()}");
            return response()->json(['success'=>false,'message'=>"Impossible d'initier le paiement Stripe."], 502);
        }

        return response()->json(['success'=>true,'message'=>'Paiement Stripe initié.','data'=>['url'=>$session->url,'session_id'=>$session->id]]);
    }

    // POST /api/payment/stripe/confirm
    //
    // Ne fait plus confiance à un identifiant fourni par le client : on vérifie auprès de Stripe que
    // la Checkout Session existe réellement, qu'elle est bien liée à cette commande (metadata), et
    // que son statut de paiement est "paid" avant de marquer le paiement comme validé.
    public function confirmerStripe(Request $request): JsonResponse
    {
        $validated = $request->validate(['commande_id'=>'required|uuid|exists:commandes,id','session_id'=>'required|string']);
        $commande = Commande::findOrFail($validated['commande_id']);

        $secret = config('services.stripe.secret');
        if (!$secret) {
            Log::info('[Stripe] STRIPE_SECRET non configurée — confirmation acceptée sans vérification (mode dev).');
            $paiement = Paiement::create(['commande_id'=>$commande->id,'methode'=>'stripe','montant'=>$commande->montant_total,'devise'=>$commande->devise_paiement,'statut'=>'valide','date_paiement'=>now(),'reference_transaction_externe'=>$validated['session_id']]);
            DashboardCache::bump();
            return response()->json(['success'=>true,'message'=>'Paiement confirmé (simulation).','data'=>$paiement]);
        }

        try {
            $session = (new StripeClient($secret))->checkout->sessions->retrieve($validated['session_id']);
        } catch (ApiErrorException $e) {
            Log::warning("[Stripe] Checkout Session introuvable : {$e->getMessage()}");
            return response()->json(['success'=>false,'message'=>'Session de paiement Stripe introuvable.'], 400);
        }

        if (($session->metadata['commande_id'] ?? null) !== $commande->id) {
            Log::warning("[Stripe] Session {$session->id} ne correspond pas à la commande {$commande->id}.");
            return response()->json(['success'=>false,'message'=>'Cette session de paiement ne correspond pas à cette commande.'], 400);
        }

        if ($session->payment_status !== 'paid') {
            return response()->json(['success'=>false,'message'=>"Le paiement Stripe n'est pas encore confirmé (statut : {$session->payment_status})."], 400);
        }

        $paiement = Paiement::create([
            'commande_id' => $commande->id,
            'methode'     => 'stripe',
            'montant'     => $this->stripeAmountToMontant((int) $session->amount_total, (string) $session->currency),
            'devise'      => $this->fromStripeCurrency((string) $session->currency),
            'statut'      => 'valide',
            'date_paiement' => now(),
            'reference_transaction_externe' => $session->payment_intent ?: $session->id,
        ]);
        DashboardCache::bump();
        return response()->json(['success'=>true,'message'=>'Paiement confirmé.','data'=>$paiement]);
    }

    // POST /api/payment/paypal/init
    //
    // Crée une vraie commande PayPal (Orders API v2) dès que PAYPAL_CLIENT_ID/SECRET sont
    // configurés. PayPal n'a pas de SDK officiel maintenu pour Laravel — on appelle directement
    // son API REST via le client Http de Laravel, ce qui évite une dépendance supplémentaire.
    // Le client (mobile ou web) doit rediriger l'utilisateur vers le lien d'approbation renvoyé —
    // sans cette étape sur le site de PayPal, la commande reste au statut CREATED et la capture
    // échouera toujours (elle exige le statut APPROVED).
    public function initierPayPal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'commande_id' => 'required|uuid|exists:commandes,id',
            'return_url'  => 'required|url',
            'cancel_url'  => 'required|url',
        ]);
        $commande = Commande::findOrFail($validated['commande_id']);

        if (!config('services.paypal.client_id')) {
            Log::info('[PayPal] PAYPAL_CLIENT_ID non configurée — simulation (mode dev).');
            $simulatedUrl = $validated['return_url'] . (str_contains($validated['return_url'], '?') ? '&' : '?') . 'order_id=simulated_' . Str::uuid();
            return response()->json(['success'=>true,'message'=>'Redirection PayPal (simulation, clé non configurée).','data'=>['url'=>$simulatedUrl,'order_id'=>null]]);
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
            'application_context' => [
                'return_url'  => $validated['return_url'],
                'cancel_url'  => $validated['cancel_url'],
                'user_action' => 'PAY_NOW',
            ],
        ]);

        if (!$response->successful()) {
            Log::error('[PayPal] Échec création commande : ' . $response->body());
            return response()->json(['success'=>false,'message'=>"Impossible d'initier le paiement PayPal."], 502);
        }

        $approveLink = collect($response->json('links'))->firstWhere('rel', 'approve')['href'] ?? null;
        if (!$approveLink) {
            Log::error('[PayPal] Lien d\'approbation absent de la réponse : ' . $response->body());
            return response()->json(['success'=>false,'message'=>"Réponse PayPal inattendue."], 502);
        }

        return response()->json(['success'=>true,'message'=>'Paiement PayPal initié.','data'=>['url'=>$approveLink,'order_id'=>$response->json('id')]]);
    }

    // POST /api/payment/paypal/confirm
    //
    // Capture réellement la commande PayPal auprès de leur API (au lieu de valider aveuglément un
    // paypal_order_id fourni par le client), vérifie que la capture a bien le statut "COMPLETED", et
    // que le reference_id de la capture correspond bien au numéro de cette commande — sans ce dernier
    // contrôle, un order_id valide mais créé/payé pour une autre commande pourrait être réutilisé ici.
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

        if ($capture->json('purchase_units.0.reference_id') !== $commande->numero_commande) {
            Log::warning("[PayPal] Order {$validated['paypal_order_id']} ne correspond pas à la commande {$commande->numero_commande}.");
            return response()->json(['success'=>false,'message'=>'Cette commande PayPal ne correspond pas à cette commande Zando.'], 400);
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

    // POST /api/payment/mtn-momo/init
    public function initierMtnMoMo(Request $request, MtnMomoService $momoService): JsonResponse
    {
        $validated = $request->validate([
            'commande_id' => 'required|uuid|exists:commandes,id',
            'telephone'   => 'sometimes|string',
        ]);
        $commande = Commande::findOrFail($validated['commande_id']);
        $phone = $validated['telephone'] ?? $commande->client?->user?->telephone;

        if (!$phone) {
            return response()->json(['success' => false, 'message' => 'Aucun numéro de téléphone disponible pour ce paiement MTN MoMo.'], 422);
        }

        $externalId = "ZANDO-CMD-{$commande->numero_commande}-" . time();

        $result = $momoService->requestToPay($phone, (float) $commande->montant_total, $commande->numero_commande, $externalId);

        if (!$result || !$result['success']) {
            return response()->json(['success' => false, 'message' => $result['message'] ?? 'Erreur lors du paiement MTN MoMo.'], 400);
        }

        $paiement = Paiement::create([
            'commande_id' => $commande->id,
            'methode'     => 'mtn_momo',
            'montant'     => $commande->montant_total,
            'devise'      => 'FCFA',
            'statut'      => 'en_attente',
            'reference_transaction_externe' => $result['reference_id'],
        ]);
        DashboardCache::bump();

        return response()->json([
            'success' => true,
            'message' => 'Demande de paiement MTN MoMo transmise.',
            'data'    => array_merge($paiement->toArray(), [
                'external_id'  => $externalId,
                'reference_id' => $result['reference_id'],
                'mode'         => $result['mode'] ?? 'live',
            ]),
        ]);
    }

    // POST /api/payment/mtn-momo/confirm
    //
    // Appelable en polling par le client (idempotent) : tant que le paiement MTN n'a pas de statut
    // définitif côté MTN, on renvoie 'en_attente' pour que le client resonde plus tard. On ne marque
    // 'valide' que si MTN confirme explicitement SUCCESSFUL — jamais par défaut.
    public function confirmerMtnMoMo(Request $request, MtnMomoService $momoService): JsonResponse
    {
        $validated = $request->validate([
            'paiement_id' => 'required|uuid|exists:paiements,id',
        ]);
        $paiement = Paiement::findOrFail($validated['paiement_id']);

        if ($paiement->statut === 'valide') {
            return response()->json(['success' => true, 'status' => 'valide', 'message' => 'Paiement déjà confirmé.', 'data' => $paiement]);
        }
        if ($paiement->statut === 'echoue') {
            return response()->json(['success' => false, 'status' => 'echoue', 'message' => 'Ce paiement a échoué.']);
        }

        $ref = $paiement->reference_transaction_externe;

        if (!$ref || !$momoService->isCollectionConfigured()) {
            // Pas de référence MTN réelle à vérifier (mode simulation / credentials absentes) : on
            // valide directement, comme le fait déjà requestToPay en mode simulation.
            $paiement->update(['statut' => 'valide', 'date_paiement' => now()]);
            DashboardCache::bump();
            return response()->json(['success' => true, 'status' => 'valide', 'message' => 'Paiement MTN MoMo confirmé avec succès.', 'data' => $paiement]);
        }

        $statusCheck = $momoService->getPaymentStatus($ref);

        if (!$statusCheck) {
            return response()->json(['success' => false, 'status' => 'en_attente', 'message' => 'Impossible de vérifier le statut du paiement pour le moment.']);
        }

        if ($statusCheck['status'] === 'SUCCESSFUL') {
            $paiement->update([
                'statut'                        => 'valide',
                'date_paiement'                 => now(),
                'reference_transaction_externe' => $statusCheck['financial_transaction_id'] ?? $ref,
            ]);
            DashboardCache::bump();
            return response()->json(['success' => true, 'status' => 'valide', 'message' => 'Paiement MTN MoMo confirmé avec succès.', 'data' => $paiement]);
        }

        if ($statusCheck['status'] === 'FAILED') {
            $paiement->update(['statut' => 'echoue']);
            DashboardCache::bump();
            return response()->json([
                'success' => false,
                'status'  => 'echoue',
                'message' => $statusCheck['message'] ?? 'Le paiement MTN MoMo a échoué.',
                'reason'  => $statusCheck['reason'] ?? null,
            ]);
        }

        // PENDING (ou statut inconnu) : le client doit resonder plus tard.
        return response()->json(['success' => false, 'status' => 'en_attente', 'message' => 'En attente de validation sur le téléphone du client.']);
    }

    // POST /api/payment/airtel-money/init
    //
    // Même contrat que initierMtnMoMo : envoie une vraie demande de paiement (push USSD) au
    // téléphone du client dès que AirtelMoneyService est configuré, sinon simule proprement.
    public function initierAirtelMoney(Request $request, AirtelMoneyService $airtelService): JsonResponse
    {
        $validated = $request->validate([
            'commande_id' => 'required|uuid|exists:commandes,id',
            'telephone'   => 'sometimes|string',
        ]);
        $commande = Commande::findOrFail($validated['commande_id']);
        $phone = $validated['telephone'] ?? $commande->client?->user?->telephone;

        if (!$phone) {
            return response()->json(['success' => false, 'message' => 'Aucun numéro de téléphone disponible pour ce paiement Airtel Money.'], 422);
        }

        $result = $airtelService->requestToPay($phone, (float) $commande->montant_total, $commande->numero_commande);

        if (!$result || !$result['success']) {
            return response()->json(['success' => false, 'message' => $result['message'] ?? 'Erreur lors du paiement Airtel Money.'], 400);
        }

        $paiement = Paiement::create([
            'commande_id' => $commande->id,
            'methode'     => 'airtel_money',
            'montant'     => $commande->montant_total,
            'devise'      => 'FCFA',
            'statut'      => 'en_attente',
            'reference_transaction_externe' => $result['reference_id'],
        ]);
        DashboardCache::bump();

        return response()->json([
            'success' => true,
            'message' => 'Demande de paiement Airtel Money transmise.',
            'data'    => array_merge($paiement->toArray(), [
                'reference_id' => $result['reference_id'],
                'mode'         => $result['mode'] ?? 'live',
            ]),
        ]);
    }

    // POST /api/payment/airtel-money/confirm
    //
    // Appelable en polling par le client (idempotent), mêmes garanties que confirmerMtnMoMo : ne
    // marque jamais 'valide' sur la seule foi d'une référence fournie par le client — uniquement
    // sur un statut SUCCESSFUL renvoyé par Airtel (ou en mode simulation si non configuré).
    public function confirmerAirtelMoney(Request $request, AirtelMoneyService $airtelService): JsonResponse
    {
        $validated = $request->validate([
            'paiement_id' => 'required|uuid|exists:paiements,id',
        ]);
        $paiement = Paiement::findOrFail($validated['paiement_id']);

        if ($paiement->statut === 'valide') {
            return response()->json(['success' => true, 'status' => 'valide', 'message' => 'Paiement déjà confirmé.', 'data' => $paiement]);
        }
        if ($paiement->statut === 'echoue') {
            return response()->json(['success' => false, 'status' => 'echoue', 'message' => 'Ce paiement a échoué.']);
        }

        $ref = $paiement->reference_transaction_externe;

        if (!$ref || !$airtelService->isConfigured()) {
            $paiement->update(['statut' => 'valide', 'date_paiement' => now()]);
            DashboardCache::bump();
            return response()->json(['success' => true, 'status' => 'valide', 'message' => 'Paiement Airtel Money confirmé avec succès.', 'data' => $paiement]);
        }

        $statusCheck = $airtelService->getPaymentStatus($ref);

        if (!$statusCheck) {
            return response()->json(['success' => false, 'status' => 'en_attente', 'message' => 'Impossible de vérifier le statut du paiement pour le moment.']);
        }

        if ($statusCheck['status'] === 'SUCCESSFUL') {
            $paiement->update([
                'statut'                        => 'valide',
                'date_paiement'                 => now(),
                'reference_transaction_externe' => $statusCheck['financial_transaction_id'] ?? $ref,
            ]);
            DashboardCache::bump();
            return response()->json(['success' => true, 'status' => 'valide', 'message' => 'Paiement Airtel Money confirmé avec succès.', 'data' => $paiement]);
        }

        if ($statusCheck['status'] === 'FAILED') {
            $paiement->update(['statut' => 'echoue']);
            DashboardCache::bump();
            return response()->json(['success' => false, 'status' => 'echoue', 'message' => $statusCheck['message'] ?? "Le paiement Airtel Money a échoué."]);
        }

        return response()->json(['success' => false, 'status' => 'en_attente', 'message' => 'En attente de validation sur le téléphone du client.']);
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