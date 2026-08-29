<?php

use Illuminate\Support\Facades\Route;

// --- NOUVEAUX CONTROLLERS API (dossier Api/) ---
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\OtpController;
use App\Http\Controllers\Api\Client\PanierController;
use App\Http\Controllers\Api\Client\CommandeController;
use App\Http\Controllers\Api\Client\DiasporaController;
use App\Http\Controllers\Api\Client\LocalClientController;
use App\Http\Controllers\Api\Client\DiasporaClientController;
use App\Http\Controllers\Api\Client\AdresseLivraisonController;
use App\Http\Controllers\Api\Client\LitigeController as ClientLitigeController;
use App\Http\Controllers\Api\Vendeur\VendeurController;
use App\Http\Controllers\Api\Livreur\LivreurController;
use App\Http\Controllers\Api\Commande\MessageCommandeController;
use App\Http\Controllers\Api\Commande\NotationController;
use App\Http\Controllers\Api\Litige\LitigeMessageController;
use App\Http\Controllers\Api\Litige\LitigePieceJointeController;
use App\Http\Controllers\Api\Support\TicketController;
use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\Admin\DashboardStatsController;
use App\Http\Controllers\Api\Payment\PaymentController;
use App\Http\Controllers\Api\Payment\WebhookController;
use App\Http\Controllers\Api\Catalogue\CatalogueController;
use App\Http\Controllers\Api\User\UserController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ============================================
// ROUTES PUBLIQUES (Sans authentification)
// ============================================

// === AUTHENTIFICATION ===
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/auth/google', [AuthController::class, 'loginWithGoogle']);
Route::post('/logout', [AuthController::class, 'logout']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// === OTP (Code de vérification) — API dédiée ===
Route::prefix('otp')->group(function () {
    Route::post('/send', [OtpController::class, 'send']);
    Route::post('/verify', [OtpController::class, 'verify']);
    Route::post('/resend', [OtpController::class, 'resend']);
});

// === INSCRIPTIONS SPÉCIFIQUES PAR TYPE ===
Route::post('/client/local/register', [LocalClientController::class, 'register']);
Route::post('/client/diaspora/register', [DiasporaClientController::class, 'register']);

// === CATALOGUE PUBLIC ===
Route::get('/categories', [CatalogueController::class, 'categories']);
Route::get('/zones', [CatalogueController::class, 'zones']);
Route::get('/produits', [CatalogueController::class, 'produits']);
Route::get('/produits/populaires', [CatalogueController::class, 'populaires']);
Route::get('/produits/recents', [CatalogueController::class, 'recents']);
Route::get('/produits/promotions', [CatalogueController::class, 'promotions']);
Route::get('/produits/search', [CatalogueController::class, 'search']);
Route::get('/produits/{id}', [CatalogueController::class, 'produitDetail']);
Route::get('/vendeurs/top', [CatalogueController::class, 'vendeursTop']);
Route::get('/vendeurs/types', [CatalogueController::class, 'vendeurTypes']);
Route::get('/vendeurs/types-disponibles', [CatalogueController::class, 'vendeurTypesDisponibles']);
Route::get('/vendeurs/types-logos', [CatalogueController::class, 'vendeurTypesAvecLogos']);
Route::get('/vendeurs', [CatalogueController::class, 'vendeurs']);
Route::get('/vendeurs/{id}/avis', [CatalogueController::class, 'avisVendeur']);
Route::get('/vendeurs/{id}', [CatalogueController::class, 'vendeurDetail']);
Route::get('/vendeur/{vendeurId}/produits', [CatalogueController::class, 'produitsVendeur']);
Route::get('/avis/publics', [CatalogueController::class, 'avisPublics']);

// === DIASPORA - SUIVI PARTAGÉ & CONVERSION DEVISE (Public) ===
Route::get('/diaspora/suivi/{numeroCommande}', [DiasporaController::class, 'suiviPartage']);
Route::post('/diaspora/convertir', [DiasporaController::class, 'convertirDevise']);

// === PANIER - CONSULTATION D'UN LIEN PARTAGÉ (Public) ===
Route::get('/panier/partage/{lien}', [PanierController::class, 'voirPartage']);

// === WEBHOOKS ===
Route::post('/webhooks/stripe', [WebhookController::class, 'handleStripe']);


// ============================================
// ROUTES PROTÉGÉES (Authentification requise)
// ============================================
Route::middleware(['auth:sanctum'])->group(function () {

    // === UTILISATEUR (Profil) ===
    Route::prefix('user')->group(function () {
        Route::get('/me', [UserController::class, 'me']);
        Route::put('/profile', [UserController::class, 'update']);
        Route::post('/change-password', [UserController::class, 'changePassword']);
        Route::post('/upload-photo', [UserController::class, 'uploadPhoto']);
        Route::delete('/photo', [UserController::class, 'deletePhoto']);
        Route::delete('/delete', [UserController::class, 'delete']);

        // Notifications
        Route::get('/notifications', [UserController::class, 'notifications']);
        Route::post('/notifications/{id}/lire', [UserController::class, 'marquerNotificationLue']);
        Route::post('/notifications/lire-tout', [UserController::class, 'marquerToutesNotificationsLues']);

        // Jeton Expo Push de cet appareil (client, vendeur ou livreur — aucun rôle requis)
        Route::post('/push-token', [UserController::class, 'enregistrerPushToken']);
    });

    // === PROFILS SPÉCIFIQUES ===
    Route::get('/client/local/profile', [LocalClientController::class, 'profile'])->middleware('role:client');
    Route::get('/client/diaspora/profile', [DiasporaClientController::class, 'profile'])->middleware('role:client');

    // === ADRESSES DE LIVRAISON (CLIENT) ===
    Route::middleware(['role:client'])->prefix('client/adresses')->group(function () {
        Route::get('/', [AdresseLivraisonController::class, 'index']);
        Route::post('/', [AdresseLivraisonController::class, 'store']);
        Route::get('/{id}', [AdresseLivraisonController::class, 'show']);
        Route::put('/{id}', [AdresseLivraisonController::class, 'update']);
        Route::delete('/{id}', [AdresseLivraisonController::class, 'destroy']);
        Route::post('/{id}/default', [AdresseLivraisonController::class, 'setDefault']);
    });

    // ============================================
    // ROUTES CLIENT (Panier, Commandes, Diaspora, Paiements)
    // ============================================
    Route::middleware(['role:client'])->group(function () {

        // === PANIER ===
        Route::prefix('panier')->group(function () {
            Route::get('/', [PanierController::class, 'index']);
            Route::post('/ajouter', [PanierController::class, 'ajouter']);
            Route::put('/modifier/{ligneId}', [PanierController::class, 'modifier']);
            Route::delete('/supprimer/{ligneId}', [PanierController::class, 'supprimer']);
            Route::delete('/vider', [PanierController::class, 'vider']);
            Route::post('/partager', [PanierController::class, 'partager']);
            Route::post('/beneficiaire/{beneficiaireId}', [PanierController::class, 'assignerBeneficiaire']);
            Route::delete('/beneficiaire', [PanierController::class, 'retirerBeneficiaire']);
        });

        // === COMMANDES ===
        Route::prefix('commandes')->group(function () {
            Route::post('/valider', [CommandeController::class, 'valider']);
            Route::get('/', [CommandeController::class, 'index']);
            Route::get('/{id}', [CommandeController::class, 'show']);
            Route::get('/{id}/suivi', [CommandeController::class, 'suivi']);
            Route::post('/{id}/annuler', [CommandeController::class, 'annuler']);
            Route::post('/{id}/litige', [ClientLitigeController::class, 'ouvrir']);
        });

        // Motifs de litige valides — pas spécifique au client, sert aussi au vendeur (voir
        // admin_web/src/app/vendeur/litiges) quand il ouvre lui-même un litige.
        Route::get('/litiges/motifs', [ClientLitigeController::class, 'motifs']);

        // === LITIGES (mes litiges, en tant que client) ===
        Route::prefix('client/litiges')->group(function () {
            Route::get('/', [ClientLitigeController::class, 'index']);
            Route::get('/{id}', [ClientLitigeController::class, 'show']);
        });

        // === DIASPORA ===
        Route::prefix('diaspora')->group(function () {
            Route::get('/beneficiaires', [DiasporaController::class, 'beneficiaires']);
            Route::post('/beneficiaires', [DiasporaController::class, 'ajouterBeneficiaire']);
            Route::put('/beneficiaires/{id}', [DiasporaController::class, 'modifierBeneficiaire']);
            Route::delete('/beneficiaires/{id}', [DiasporaController::class, 'supprimerBeneficiaire']);
            Route::post('/commander', [DiasporaController::class, 'commanderPourProche']);
            Route::get('/historique', [DiasporaController::class, 'historique']);
            Route::post('/paiement/confirmer', [DiasporaController::class, 'confirmerPaiement']);
        });

        // === PAIEMENTS ===
        Route::prefix('payment')->group(function () {
            Route::post('/stripe/init', [PaymentController::class, 'initierStripe']);
            Route::post('/stripe/confirm', [PaymentController::class, 'confirmerStripe']);
            Route::post('/paypal/init', [PaymentController::class, 'initierPayPal']);
            Route::post('/paypal/confirm', [PaymentController::class, 'confirmerPayPal']);
            Route::post('/mtn-momo/init', [PaymentController::class, 'initierMtnMoMo']);
            Route::post('/mtn-momo/confirm', [PaymentController::class, 'confirmerMtnMoMo']);
            Route::post('/airtel-money/init', [PaymentController::class, 'initierAirtelMoney']);
            Route::post('/airtel-money/confirm', [PaymentController::class, 'confirmerAirtelMoney']);
            Route::post('/livraison/confirm', [PaymentController::class, 'confirmerPaiementLivraison']);
            Route::get('/{id}/statut', [PaymentController::class, 'statut']);
        });
    });


    // ============================================
    // ROUTES VENDEUR
    // ============================================
    Route::middleware(['role:vendeur'])->prefix('vendeur')->group(function () {
        Route::get('/dashboard', [VendeurController::class, 'dashboard']);
        Route::put('/profil', [VendeurController::class, 'mettreAJourProfil']);
        Route::put('/statut-boutique', [VendeurController::class, 'mettreAJourStatutBoutique']);
        Route::post('/documents', [VendeurController::class, 'televerserDocuments']);
        Route::get('/commandes', [VendeurController::class, 'commandes']);
        Route::get('/commandes/{id}', [VendeurController::class, 'commandeDetail']);
        Route::post('/commandes/{id}/accepter', [VendeurController::class, 'accepterCommande']);
        Route::post('/commandes/{id}/refuser', [VendeurController::class, 'refuserCommande']);
        // Commande prise par téléphone / en personne pour un client sans compte — voir le
        // commentaire détaillé sur VendeurController::creerCommandeManuelle().
        Route::post('/commandes/manuelle', [VendeurController::class, 'creerCommandeManuelle']);
        Route::get('/produits', [VendeurController::class, 'produits']);
        Route::post('/produits', [VendeurController::class, 'ajouterProduit']);
        Route::put('/produits/{id}', [VendeurController::class, 'modifierProduit']);
        Route::delete('/produits/{id}', [VendeurController::class, 'supprimerProduit']);
        Route::post('/produits/{id}/stock', [VendeurController::class, 'gererStock']);
        Route::post('/produits/{id}/rupture', [VendeurController::class, 'signalerRupture']);
        Route::get('/revenus', [VendeurController::class, 'revenus']);
        Route::post('/retraits', [VendeurController::class, 'demanderRetrait']);
        Route::get('/retraits', [VendeurController::class, 'historiqueRetraits']);
        Route::get('/messages', [VendeurController::class, 'messages']);
        Route::post('/messages', [VendeurController::class, 'envoyerMessage']);
        Route::get('/messages/{id}', [VendeurController::class, 'messageDetail']);
        Route::post('/messages/{id}/lu', [VendeurController::class, 'marquerMessageLu']);
        Route::post('/messages/{id}/repondre', [VendeurController::class, 'repondreMessage']);
        Route::get('/litiges', [VendeurController::class, 'litiges']);
        Route::get('/litiges/{id}', [VendeurController::class, 'litigeDetail']);
        Route::get('/avis', [VendeurController::class, 'avis']);

        // Promotions self-service du vendeur (distinct de /admin/promotions, la bannière gérée
        // par l'administrateur).
        Route::get('/promotions', [VendeurController::class, 'promotionsVendeur']);
        Route::post('/promotions', [VendeurController::class, 'creerPromotionVendeur']);
        Route::patch('/promotions/{id}', [VendeurController::class, 'modifierPromotionVendeur']);
        Route::delete('/promotions/{id}', [VendeurController::class, 'supprimerPromotionVendeur']);
    });


    // ============================================
    // MESSAGERIE COMMANDE (client, vendeur, livreur — pour les participants d'une commande précise)
    // ============================================
    Route::middleware(['role:client,vendeur,livreur'])->prefix('commandes/{commandeId}/messages')->group(function () {
        Route::get('/', [MessageCommandeController::class, 'index']);
        Route::post('/', [MessageCommandeController::class, 'store']);
    });

    // ============================================
    // NOTATIONS — client note vendeur/livreur, vendeur ou livreur note le client (commande livrée)
    // ============================================
    Route::middleware(['role:client,vendeur,livreur'])->prefix('commandes/{commandeId}')->group(function () {
        Route::get('/notations', [NotationController::class, 'index']);
        Route::post('/notation', [NotationController::class, 'store']);
    });

    // ============================================
    // LITIGES — CONVERSATION & PREUVES (client, vendeur, administrateur — participants d'un
    // litige précis ; l'appartenance exacte est vérifiée dans le contrôleur, pas seulement le rôle)
    // ============================================
    Route::middleware(['role:client,vendeur,administrateur'])->prefix('litiges/{id}')->group(function () {
        Route::get('/messages', [LitigeMessageController::class, 'index']);
        Route::post('/messages', [LitigeMessageController::class, 'store']);
        Route::post('/pieces-jointes', [LitigePieceJointeController::class, 'store']);
    });

    // ============================================
    // SUPPORT — TICKETS (client, vendeur, livreur)
    // ============================================
    Route::middleware(['role:client,vendeur,livreur'])->prefix('support/tickets')->group(function () {
        Route::get('/', [TicketController::class, 'index']);
        Route::post('/', [TicketController::class, 'store']);
        Route::get('/{id}', [TicketController::class, 'show']);
        Route::post('/{id}/repondre', [TicketController::class, 'repondre']);
    });


    // ============================================
    // ROUTES LIVREUR
    // ============================================
    Route::middleware(['role:livreur'])->prefix('livreur')->group(function () {
        Route::get('/dashboard', [LivreurController::class, 'dashboard']);
        Route::get('/missions/disponibles', [LivreurController::class, 'missionsDisponibles']);
        Route::post('/missions/{id}/accepter', [LivreurController::class, 'accepterMission']);
        Route::post('/missions/{id}/refuser', [LivreurController::class, 'refuserMission']);
        Route::get('/navigation/{id}', [LivreurController::class, 'demarrerNavigation']);
        Route::post('/position', [LivreurController::class, 'actualiserPosition']);
Route::post('/livraisons/{id}/collecte', [LivreurController::class, 'confirmerCollecte']);
        Route::post('/livraisons/{id}/depart', [LivreurController::class, 'confirmerDepart']);
        Route::post('/livraisons/{id}/probleme', [LivreurController::class, 'signalerProbleme']);
        Route::post('/livraisons/{id}/livrer', [LivreurController::class, 'confirmerLivraison']);
        Route::post('/disponibilite', [LivreurController::class, 'basculerDisponibilite']);
        Route::get('/revenus', [LivreurController::class, 'revenus']);
        Route::post('/retraits', [LivreurController::class, 'demanderRetrait']);
        Route::get('/retraits', [LivreurController::class, 'historiqueRetraits']);
        Route::get('/historique', [LivreurController::class, 'historiqueMissions']);
        Route::get('/avis', [LivreurController::class, 'avis']);
    });


    // ============================================
    // ROUTES ADMINISTRATEUR
    // ============================================
    Route::middleware(['role:administrateur'])->prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/statistiques', [AdminController::class, 'statistiques']);

        // === STATISTIQUES & ANALYTICS (période filtrable, comparaison période précédente) ===
        Route::prefix('dashboard')->group(function () {
            Route::get('/overview', [DashboardStatsController::class, 'overview']);
            Route::get('/sales', [DashboardStatsController::class, 'sales']);
            Route::get('/orders', [DashboardStatsController::class, 'orders']);
            Route::get('/products', [DashboardStatsController::class, 'products']);
            Route::get('/payments', [DashboardStatsController::class, 'payments']);
            Route::get('/customers', [DashboardStatsController::class, 'customers']);
            Route::get('/segments', [DashboardStatsController::class, 'segments']);
            Route::get('/vendors', [DashboardStatsController::class, 'vendors']);
            Route::get('/deliveries', [DashboardStatsController::class, 'deliveries']);
            Route::get('/activity', [DashboardStatsController::class, 'activity']);
            Route::get('/alerts', [DashboardStatsController::class, 'alerts']);
        });

        Route::prefix('utilisateurs')->group(function () {
            Route::get('/', [AdminController::class, 'utilisateurs'])->middleware('permission:view_users');
            Route::post('/', [AdminController::class, 'creerUtilisateur'])->middleware('permission:manage_users_status');
            Route::get('/{id}', [AdminController::class, 'utilisateurDetail'])->middleware('permission:view_users');
            Route::put('/{id}', [AdminController::class, 'modifierUtilisateur'])->middleware('permission:manage_users_status');
            Route::post('/{id}/activer', [AdminController::class, 'activerUtilisateur'])->middleware('permission:manage_users_status');
            Route::post('/{id}/suspendre', [AdminController::class, 'suspendreUtilisateur'])->middleware('permission:manage_users_status');
            Route::post('/{id}/reset-password', [AdminController::class, 'reinitialiserMotDePasse'])->middleware('permission:manage_users_status');
            Route::delete('/{id}', [AdminController::class, 'supprimerUtilisateur'])->middleware('permission:delete_users');
        });

        Route::prefix('vendeurs')->group(function () {
            Route::get('/', [AdminController::class, 'vendeurs'])->middleware('permission:view_vendeurs');
            Route::get('/{id}', [AdminController::class, 'vendeurDetail'])->middleware('permission:view_vendeurs');
            Route::put('/{id}', [AdminController::class, 'modifierVendeur'])->middleware('permission:edit_vendeurs');
            Route::post('/{id}/valider', [AdminController::class, 'validerVendeur'])->middleware('permission:validate_vendeurs');
            Route::post('/{id}/suspendre', [AdminController::class, 'suspendreVendeur'])->middleware('permission:suspendre_vendeurs');
        });

        Route::prefix('livreurs')->group(function () {
            Route::get('/', [AdminController::class, 'livreurs'])->middleware('permission:view_livreurs');
            Route::get('/{id}', [AdminController::class, 'livreurDetail'])->middleware('permission:view_livreurs');
            Route::post('/{id}/valider', [AdminController::class, 'validerLivreur'])->middleware('permission:validate_livreurs');
            Route::post('/{id}/suspendre', [AdminController::class, 'suspendreLivreur'])->middleware('permission:suspendre_livreurs');
        });

        Route::prefix('commandes')->group(function () {
            Route::get('/', [AdminController::class, 'commandes'])->middleware('permission:view_all_commandes');
            Route::get('/{id}', [AdminController::class, 'commandeDetail'])->middleware('permission:view_all_commandes');
            Route::post('/{id}/attribuer', [AdminController::class, 'attribuerCommande'])->middleware('permission:assign_commandes');
            Route::post('/{id}/attribuer-vendeur', [AdminController::class, 'attribuerVendeurCommande'])->middleware('permission:assign_commandes');
            Route::put('/{id}/statut', [AdminController::class, 'modifierStatutCommande'])->middleware('permission:edit_commande_status');
            Route::post('/{id}/rembourser', [AdminController::class, 'rembourserCommande'])->middleware('permission:refund_commandes');
            Route::delete('/{id}', [AdminController::class, 'supprimerCommande'])->middleware('permission:delete_commandes');
        });

        Route::prefix('livraisons')->group(function () {
            Route::get('/', [AdminController::class, 'livraisons'])->middleware('permission:view_livraisons');
            Route::get('/positions', [AdminController::class, 'livraisonsPositions'])->middleware('permission:view_livraisons');
            Route::get('/{id}', [AdminController::class, 'livraisonDetail'])->middleware('permission:view_livraisons');
            Route::post('/{id}/reattribuer', [AdminController::class, 'reattribuerLivraison'])->middleware('permission:reassign_livraisons');
        });

        Route::prefix('attribution')->group(function () {
            Route::get('/file-attente', [AdminController::class, 'attributionFileAttente'])->middleware('permission:assign_commandes');
            Route::get('/livreurs', [AdminController::class, 'attributionLivreursDisponibles'])->middleware('permission:assign_commandes');
        });

        Route::prefix('categories')->group(function () {
            Route::post('/', [AdminController::class, 'ajouterCategorie'])->middleware('permission:create_categories');
            Route::put('/{id}', [AdminController::class, 'modifierCategorie'])->middleware('permission:edit_categories');
            Route::delete('/{id}', [AdminController::class, 'supprimerCategorie'])->middleware('permission:delete_categories');
        });

        Route::prefix('types-boutique')->group(function () {
            Route::get('/', [AdminController::class, 'typesBoutique'])->middleware('permission:view_types_boutique');
            Route::post('/', [AdminController::class, 'ajouterTypeBoutique'])->middleware('permission:create_types_boutique');
            Route::put('/{id}', [AdminController::class, 'modifierTypeBoutique'])->middleware('permission:edit_types_boutique');
            Route::delete('/{id}', [AdminController::class, 'supprimerTypeBoutique'])->middleware('permission:delete_types_boutique');
        });

        Route::prefix('zones')->group(function () {
            Route::get('/', [AdminController::class, 'zones'])->middleware('permission:view_zones');
            Route::post('/', [AdminController::class, 'ajouterZone'])->middleware('permission:create_zones');
            Route::put('/{id}', [AdminController::class, 'modifierZone'])->middleware('permission:edit_zones');
            Route::delete('/{id}', [AdminController::class, 'supprimerZone'])->middleware('permission:delete_zones');
        });

        Route::prefix('produits')->group(function () {
            Route::get('/', [AdminController::class, 'produits'])->middleware('permission:view_all_produits');
            Route::put('/{id}', [AdminController::class, 'modifierProduit'])->middleware('permission:edit_produits');
            Route::post('/{id}/activer', [AdminController::class, 'activerProduit'])->middleware('permission:edit_produits');
            Route::post('/{id}/desactiver', [AdminController::class, 'desactiverProduit'])->middleware('permission:edit_produits');
            Route::delete('/{id}', [AdminController::class, 'supprimerProduit'])->middleware('permission:delete_produits');
        });

        Route::prefix('finances')->group(function () {
            Route::get('/', [AdminController::class, 'finances'])->middleware('permission:view_finances');
        });

        Route::prefix('avis')->group(function () {
            Route::get('/', [AdminController::class, 'avis'])->middleware('permission:view_avis');
            Route::delete('/{id}', [AdminController::class, 'supprimerAvis'])->middleware('permission:delete_avis');
        });

        Route::prefix('taux-change')->group(function () {
            Route::get('/', [AdminController::class, 'tauxChange'])->middleware('permission:view_taux_change');
            Route::post('/', [AdminController::class, 'ajouterTauxChange'])->middleware('permission:create_taux_change');
            Route::put('/{id}', [AdminController::class, 'modifierTauxChange'])->middleware('permission:edit_taux_change');
            Route::delete('/{id}', [AdminController::class, 'supprimerTauxChange'])->middleware('permission:delete_taux_change');
        });

        Route::get('/rapports/export', [AdminController::class, 'exporterRapport'])->middleware('permission:generate_reports');

        Route::prefix('transactions')->group(function () {
            Route::get('/', [AdminController::class, 'transactions'])->middleware('permission:view_finances');
        });

        Route::prefix('commissions')->group(function () {
            Route::get('/', [AdminController::class, 'commissions'])->middleware('permission:view_finances');
            Route::post('/{id}/valider', [AdminController::class, 'validerCommission'])->middleware('permission:manage_commissions');
        });

        Route::prefix('retraits')->group(function () {
            Route::get('/vendeurs', [AdminController::class, 'retraitsVendeurs'])->middleware('permission:manage_retraits');
            Route::post('/vendeurs/{id}/valider', [AdminController::class, 'validerRetraitVendeur'])->middleware('permission:manage_retraits');
            Route::post('/vendeurs/{id}/rejeter', [AdminController::class, 'rejeterRetraitVendeur'])->middleware('permission:manage_retraits');
            Route::get('/livreurs', [AdminController::class, 'retraitsLivreurs'])->middleware('permission:manage_retraits');
            Route::post('/livreurs/{id}/valider', [AdminController::class, 'validerRetraitLivreur'])->middleware('permission:manage_retraits');
            Route::post('/livreurs/{id}/rejeter', [AdminController::class, 'rejeterRetraitLivreur'])->middleware('permission:manage_retraits');
        });

        Route::prefix('litiges')->group(function () {
            Route::get('/', [AdminController::class, 'litiges'])->middleware('permission:view_litiges');
            Route::get('/{id}', [AdminController::class, 'litigeDetail'])->middleware('permission:view_litiges');
            Route::get('/{id}/historique', [AdminController::class, 'historiqueLitige'])->middleware('permission:view_litiges');
            Route::post('/{id}/traiter', [AdminController::class, 'traiterLitige'])->middleware('permission:traiter_litiges');
            Route::post('/{id}/demander-informations', [AdminController::class, 'demanderInformationsLitige'])->middleware('permission:traiter_litiges');
            Route::post('/{id}/decision', [AdminController::class, 'decisionLitige'])->middleware('permission:traiter_litiges');
            Route::post('/{id}/rembourser', [AdminController::class, 'rembourserLitige'])->middleware('permission:traiter_litiges');
            Route::post('/{id}/escalader', [AdminController::class, 'escaladerLitige'])->middleware('permission:traiter_litiges');
        });

        Route::prefix('litige-motifs')->group(function () {
            Route::get('/', [AdminController::class, 'litigeMotifs'])->middleware('permission:view_litige_motifs');
            Route::post('/', [AdminController::class, 'ajouterLitigeMotif'])->middleware('permission:create_litige_motifs');
            Route::put('/{id}', [AdminController::class, 'modifierLitigeMotif'])->middleware('permission:edit_litige_motifs');
            Route::delete('/{id}', [AdminController::class, 'supprimerLitigeMotif'])->middleware('permission:delete_litige_motifs');
        });

        Route::prefix('tickets')->group(function () {
            Route::get('/', [AdminController::class, 'tickets'])->middleware('permission:view_tickets');
            Route::get('/{id}', [AdminController::class, 'ticketDetail'])->middleware('permission:view_tickets');
            Route::post('/{id}/repondre', [AdminController::class, 'repondreTicket'])->middleware('permission:manage_tickets');
            Route::post('/{id}/assigner', [AdminController::class, 'assignerTicket'])->middleware('permission:manage_tickets');
            Route::put('/{id}/statut', [AdminController::class, 'modifierStatutTicket'])->middleware('permission:manage_tickets');
            Route::put('/{id}/priorite', [AdminController::class, 'modifierPrioriteTicket'])->middleware('permission:manage_tickets');
        });

        Route::get('/administrateurs', [AdminController::class, 'listAdministrateurs'])->middleware('permission:manage_tickets');

        Route::prefix('promotions')->group(function () {
            Route::get('/', [AdminController::class, 'promotions'])->middleware('permission:view_promotions');
            Route::post('/', [AdminController::class, 'creerPromotion'])->middleware('permission:manage_promotions');
            Route::put('/{id}', [AdminController::class, 'modifierPromotion'])->middleware('permission:manage_promotions');
            Route::delete('/{id}', [AdminController::class, 'supprimerPromotion'])->middleware('permission:manage_promotions');
            Route::post('/{id}/produits', [AdminController::class, 'ajouterProduitPromotion'])->middleware('permission:manage_promotions');
            Route::delete('/{id}/produits/{produitId}', [AdminController::class, 'retirerProduitPromotion'])->middleware('permission:manage_promotions');
        });

        Route::prefix('coupons')->group(function () {
            Route::get('/', [AdminController::class, 'coupons'])->middleware('permission:view_coupons');
            Route::post('/', [AdminController::class, 'creerCoupon'])->middleware('permission:manage_coupons');
            Route::put('/{id}', [AdminController::class, 'modifierCoupon'])->middleware('permission:manage_coupons');
            Route::delete('/{id}', [AdminController::class, 'supprimerCoupon'])->middleware('permission:manage_coupons');
        });

        Route::prefix('notifications')->group(function () {
            Route::get('/', [AdminController::class, 'campagnesNotifications'])->middleware('permission:view_notifications');
            Route::post('/', [AdminController::class, 'creerCampagneNotification'])->middleware('permission:send_notifications');
            Route::delete('/{id}', [AdminController::class, 'supprimerCampagneNotification'])->middleware('permission:send_notifications');
        });

        Route::prefix('parametres')->group(function () {
            Route::get('/', [AdminController::class, 'parametres'])->middleware('permission:view_parametres');
            Route::put('/{id}', [AdminController::class, 'modifierParametre'])->middleware('permission:edit_parametres');
        });

        Route::post('/cache/clear', [AdminController::class, 'clearCache'])->middleware('permission:edit_parametres');
    });


    // ============================================
    // ROUTES SUPER ADMIN
    // ============================================
    Route::middleware(['role:super_admin'])->prefix('super-admin')->group(function () {
        Route::get('/roles', [AdminController::class, 'listRoles']);
        Route::post('/users/{id}/role', [AdminController::class, 'assignRole']);
        Route::delete('/users/{id}/role', [AdminController::class, 'removeRole']);
        Route::get('/logs', [AdminController::class, 'auditLogs']);

        Route::prefix('administrateurs')->group(function () {
            Route::post('/', [AdminController::class, 'creerAdministrateur']);
            Route::put('/{id}', [AdminController::class, 'modifierAdministrateur']);
            Route::post('/{id}/suspendre', [AdminController::class, 'suspendreAdministrateur']);
            Route::post('/{id}/activer', [AdminController::class, 'activerAdministrateur']);
        });
        Route::get('/permissions-catalogue', [AdminController::class, 'permissionsCatalogue']);
    });
});


// ============================================
// ROUTE DE TEST
// ============================================
Route::get('/health', function () {
    return response()->json([
        'success'   => true,
        'message'   => 'API Zando na Ndako est opérationnelle',
        'version'   => '1.0.0',
        'timestamp' => now()->toIso8601String(),
    ]);
});