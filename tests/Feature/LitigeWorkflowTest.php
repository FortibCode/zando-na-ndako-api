<?php

namespace Tests\Feature;

use App\Models\Administrateur;
use App\Models\Client;
use App\Models\Commande;
use App\Models\Litige;
use App\Models\Paiement;
use App\Models\User;
use App\Models\Vendeur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LitigeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $type, array $overrides = []): User
    {
        return User::create(array_merge([
            'nom' => 'Test',
            'prenom' => ucfirst($type),
            'telephone' => '08' . random_int(10000000, 99999999),
            'email' => strtolower($type) . '_' . Str::random(8) . '@test.zando',
            'mot_de_passe_hash' => Hash::make('password123'),
            'type_utilisateur' => $type,
            'statut_compte' => 'actif',
            'consentement_cgu' => true,
        ], $overrides));
    }

    private function makeClient(): array
    {
        $user = $this->makeUser('client');
        $client = Client::create(['user_id' => $user->id]);
        return [$user, $client];
    }

    private function makeVendeur(): array
    {
        $user = $this->makeUser('vendeur');
        $vendeur = Vendeur::create([
            'user_id' => $user->id,
            'nom_commerce' => 'Boutique Test',
            'categorie_principale' => 'alimentation',
            'statut_validation' => 'valide',
            'solde_disponible' => 0,
        ]);
        return [$user, $vendeur];
    }

    private function makeAdmin(string $roleAdmin = 'support'): array
    {
        $user = $this->makeUser('administrateur');
        $admin = Administrateur::create([
            'user_id' => $user->id,
            'role_admin' => $roleAdmin,
            // isSuperAdmin() court-circuite hasPermission() pour super_admin ; pour les autres
            // rôles (support, finance...), Administrateur::hasPermission() ne regarde que ce
            // tableau — pas de table de rôles séparée à peupler pour ces tests.
            'permissions' => $roleAdmin === 'super_admin' ? ['all'] : ['view_litiges', 'traiter_litiges'],
            'date_nomination' => now(),
        ]);
        return [$user, $admin];
    }

    private function makeCommande(Client $client, Vendeur $vendeur, float $montant = 10000): Commande
    {
        return Commande::create([
            'numero_commande' => 'CMD-' . strtoupper(Str::random(8)),
            'client_id' => $client->id,
            'vendeur_id' => $vendeur->id,
            'date_commande' => now(),
            'statut_commande' => 'livree',
            'mode_attribution' => 'auto',
            'montant_sous_total' => $montant,
            'frais_livraison' => 0,
            'montant_total' => $montant,
            'adresse_livraison' => 'Quartier Test, Brazzaville',
            'type_commande' => 'locale',
            'devise_paiement' => 'FCFA',
        ]);
    }

    private function makeLitige(Commande $commande, User $plaignant, string $statut = 'ouvert'): Litige
    {
        return Litige::create([
            'numero' => Litige::genererNumero(),
            'commande_id' => $commande->id,
            'utilisateur_plaignant_id' => $plaignant->id,
            'motif' => 'produit_endommage',
            'description' => 'Le produit est arrivé cassé.',
            'statut' => $statut,
            'date_ouverture' => now(),
        ]);
    }

    // ------------------------------------------------------------
    // CLIENT
    // ------------------------------------------------------------

    public function test_client_can_open_a_litige_for_own_commande(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        $commande = $this->makeCommande($client, $vendeur);

        Sanctum::actingAs($clientUser);
        $response = $this->postJson("/api/commandes/{$commande->id}/litige", [
            'motif' => 'produit_endommage',
            'description' => 'Le produit est arrivé cassé, emballage déchiré.',
        ]);

        $response->assertStatus(201)->assertJsonPath('success', true);
        $this->assertDatabaseHas('litiges', ['commande_id' => $commande->id, 'statut' => 'ouvert']);
        $this->assertNotNull(Litige::first()->numero);
    }

    public function test_client_cannot_open_two_litiges_for_same_commande(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        $commande = $this->makeCommande($client, $vendeur);
        $this->makeLitige($commande, $clientUser);

        Sanctum::actingAs($clientUser);
        $response = $this->postJson("/api/commandes/{$commande->id}/litige", [
            'motif' => 'autre',
            'description' => 'Nouvelle tentative.',
        ]);

        $response->assertStatus(409);
        $this->assertSame(1, Litige::count());
    }

    public function test_client_can_view_own_litige(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        $commande = $this->makeCommande($client, $vendeur);
        $litige = $this->makeLitige($commande, $clientUser);

        Sanctum::actingAs($clientUser);
        $response = $this->getJson("/api/client/litiges/{$litige->id}");

        $response->assertStatus(200)->assertJsonPath('data.id', $litige->id);
    }

    public function test_client_cannot_view_another_clients_litige(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [$otherClientUser, $otherClient] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        $commande = $this->makeCommande($otherClient, $vendeur);
        $litige = $this->makeLitige($commande, $otherClientUser);

        Sanctum::actingAs($clientUser);
        $response = $this->getJson("/api/client/litiges/{$litige->id}");

        $response->assertStatus(404);
    }

    public function test_client_can_send_a_message(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        $commande = $this->makeCommande($client, $vendeur);
        $litige = $this->makeLitige($commande, $clientUser);

        Sanctum::actingAs($clientUser);
        $response = $this->postJson("/api/litiges/{$litige->id}/messages", [
            'message' => 'Voici plus de détails sur le problème.',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('litige_messages', ['litige_id' => $litige->id, 'sender_type' => 'client']);
    }

    public function test_client_can_add_an_attachment(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        $commande = $this->makeCommande($client, $vendeur);
        $litige = $this->makeLitige($commande, $clientUser);

        \Illuminate\Support\Facades\Storage::fake('public');
        $file = \Illuminate\Http\UploadedFile::fake()->image('preuve.jpg', 300, 300)->size(500);

        Sanctum::actingAs($clientUser);
        $response = $this->postJson("/api/litiges/{$litige->id}/pieces-jointes", ['fichier' => $file]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('litige_pieces_jointes', ['litige_id' => $litige->id, 'uploaded_by' => $clientUser->id, 'file_type' => 'image']);
    }

    // ------------------------------------------------------------
    // VENDEUR
    // ------------------------------------------------------------

    public function test_vendeur_receives_litige_on_own_commande(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [$vendeurUser, $vendeur] = $this->makeVendeur();
        $commande = $this->makeCommande($client, $vendeur);
        $litige = $this->makeLitige($commande, $clientUser);

        Sanctum::actingAs($vendeurUser);
        $response = $this->getJson('/api/vendeur/litiges');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame($litige->id, $response->json('data.data.0.id'));
    }

    public function test_vendeur_can_respond(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [$vendeurUser, $vendeur] = $this->makeVendeur();
        $commande = $this->makeCommande($client, $vendeur);
        $litige = $this->makeLitige($commande, $clientUser);

        Sanctum::actingAs($vendeurUser);
        $response = $this->postJson("/api/litiges/{$litige->id}/messages", [
            'message' => "Voici la preuve d'expédition du colis.",
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('litige_messages', ['litige_id' => $litige->id, 'sender_type' => 'vendeur']);
    }

    public function test_vendeur_can_add_attachment(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [$vendeurUser, $vendeur] = $this->makeVendeur();
        $commande = $this->makeCommande($client, $vendeur);
        $litige = $this->makeLitige($commande, $clientUser);

        \Illuminate\Support\Facades\Storage::fake('public');
        $file = \Illuminate\Http\UploadedFile::fake()->create('bon_expedition.pdf', 100, 'application/pdf');

        Sanctum::actingAs($vendeurUser);
        $response = $this->postJson("/api/litiges/{$litige->id}/pieces-jointes", ['fichier' => $file]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('litige_pieces_jointes', ['litige_id' => $litige->id, 'uploaded_by' => $vendeurUser->id, 'file_type' => 'document']);
    }

    public function test_vendeur_cannot_access_another_vendeurs_litige(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeurA] = $this->makeVendeur();
        [$vendeurUserB, $vendeurB] = $this->makeVendeur();
        $commande = $this->makeCommande($client, $vendeurA);
        $litige = $this->makeLitige($commande, $clientUser);

        Sanctum::actingAs($vendeurUserB);

        $this->getJson("/api/vendeur/litiges/{$litige->id}")->assertStatus(404);
        $this->postJson("/api/litiges/{$litige->id}/messages", ['message' => 'Je ne devrais pas pouvoir écrire ici.'])
            ->assertStatus(403);
    }

    // ------------------------------------------------------------
    // ADMIN
    // ------------------------------------------------------------

    public function test_admin_can_view_litige_detail(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        [$adminUser] = $this->makeAdmin();
        $commande = $this->makeCommande($client, $vendeur);
        $litige = $this->makeLitige($commande, $clientUser);

        Sanctum::actingAs($adminUser);
        $response = $this->getJson("/api/admin/litiges/{$litige->id}");

        $response->assertStatus(200)->assertJsonPath('data.id', $litige->id);
    }

    public function test_admin_can_request_information_from_client(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        [$adminUser] = $this->makeAdmin();
        $commande = $this->makeCommande($client, $vendeur);
        $litige = $this->makeLitige($commande, $clientUser);

        Sanctum::actingAs($adminUser);
        $response = $this->postJson("/api/admin/litiges/{$litige->id}/demander-informations", [
            'cible' => 'client',
            'message' => 'Pouvez-vous préciser la date de réception ?',
        ]);

        $response->assertStatus(200);
        $this->assertSame('attente_client', $litige->fresh()->statut);
        $this->assertDatabaseHas('litige_messages', ['litige_id' => $litige->id, 'sender_type' => 'admin']);
    }

    public function test_admin_can_accept_and_reject_via_traiter(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        [$adminUser] = $this->makeAdmin();
        $commande = $this->makeCommande($client, $vendeur);
        $litige = $this->makeLitige($commande, $clientUser);

        Sanctum::actingAs($adminUser);
        $response = $this->postJson("/api/admin/litiges/{$litige->id}/traiter", [
            'statut' => 'rejete',
            'decision' => 'Preuve insuffisante pour justifier le litige.',
        ]);

        $response->assertStatus(200);
        $litige->refresh();
        $this->assertSame('rejete', $litige->statut);
        $this->assertNotNull($litige->date_resolution);
        $this->assertDatabaseHas('litige_decisions', ['litige_id' => $litige->id, 'decision_type' => 'rejetee']);
    }

    public function test_admin_decision_full_refund_creates_remboursement_and_caps_amount(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        [$adminUser] = $this->makeAdmin();
        $commande = $this->makeCommande($client, $vendeur, 10000);
        Paiement::create([
            'commande_id' => $commande->id, 'methode' => 'mtn_momo', 'montant' => 10000,
            'devise' => 'FCFA', 'statut' => 'valide', 'date_paiement' => now(),
        ]);
        $litige = $this->makeLitige($commande, $clientUser);

        Sanctum::actingAs($adminUser);

        // Un remboursement au-delà du montant de la commande doit être refusé.
        $this->postJson("/api/admin/litiges/{$litige->id}/decision", [
            'decision_type' => 'remboursement_total',
            'reason' => 'Produit non reçu, remboursement intégral accordé.',
            'amount' => 15000,
        ])->assertStatus(422);

        $response = $this->postJson("/api/admin/litiges/{$litige->id}/decision", [
            'decision_type' => 'remboursement_total',
            'reason' => 'Produit non reçu, remboursement intégral accordé.',
            'amount' => 10000,
        ]);

        $response->assertStatus(200);
        $litige->refresh();
        $this->assertSame('resolu', $litige->statut);
        $this->assertDatabaseHas('litige_remboursements', ['litige_id' => $litige->id, 'montant' => '10000.00']);
        // Méthode mtn_momo : aucune passerelle réversible dans cette app → reste en_attente,
        // jamais de mouvement d'argent automatique déclenché.
        $this->assertSame('en_attente', $litige->remboursements()->first()->statut);
    }

    public function test_admin_partial_refund_cannot_exceed_remaining_refundable_amount(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        [$adminUser] = $this->makeAdmin();
        $commande = $this->makeCommande($client, $vendeur, 10000);
        Paiement::create([
            'commande_id' => $commande->id, 'methode' => 'mtn_momo', 'montant' => 10000,
            'devise' => 'FCFA', 'statut' => 'valide', 'date_paiement' => now(),
        ]);
        $litige = $this->makeLitige($commande, $clientUser);

        Sanctum::actingAs($adminUser);

        // Un premier remboursement partiel de 6000 est accepté...
        $this->postJson("/api/admin/litiges/{$litige->id}/rembourser", ['montant' => 6000])
            ->assertStatus(200);

        // ...mais un second de 6000 dépasserait les 10000 FCFA de la commande (6000+6000=12000).
        $this->postJson("/api/admin/litiges/{$litige->id}/rembourser", ['montant' => 6000])
            ->assertStatus(422);

        $this->assertEqualsWithDelta(6000, (float) $litige->remboursements()->sum('montant'), 0.01);
    }

    public function test_admin_can_choose_product_replacement_without_creating_refund(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        [$adminUser] = $this->makeAdmin();
        $commande = $this->makeCommande($client, $vendeur);
        $litige = $this->makeLitige($commande, $clientUser);

        Sanctum::actingAs($adminUser);
        $response = $this->postJson("/api/admin/litiges/{$litige->id}/decision", [
            'decision_type' => 'remplacement_produit',
            'reason' => 'Le vendeur enverra un produit de remplacement.',
        ]);

        $response->assertStatus(200);
        $this->assertSame(0, $litige->remboursements()->count());
        $this->assertDatabaseHas('litige_decisions', ['litige_id' => $litige->id, 'decision_type' => 'remplacement_produit']);
    }

    public function test_admin_can_escalate_litige(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        [$adminUser] = $this->makeAdmin();
        [$superAdminUser] = $this->makeAdmin('super_admin');
        $commande = $this->makeCommande($client, $vendeur);
        $litige = $this->makeLitige($commande, $clientUser, 'en_cours');

        Sanctum::actingAs($adminUser);
        $response = $this->postJson("/api/admin/litiges/{$litige->id}/escalader", [
            'note' => 'Cas complexe, nécessite validation super-admin.',
        ]);

        $response->assertStatus(200);
        $this->assertSame('escalade', $litige->fresh()->statut);
    }

    public function test_admin_cannot_act_on_already_resolved_litige(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        [$adminUser] = $this->makeAdmin();
        $commande = $this->makeCommande($client, $vendeur);
        $litige = $this->makeLitige($commande, $clientUser, 'resolu');

        Sanctum::actingAs($adminUser);

        $this->postJson("/api/admin/litiges/{$litige->id}/decision", [
            'decision_type' => 'acceptee', 'reason' => 'Trop tard.',
        ])->assertStatus(400);

        $this->postJson("/api/admin/litiges/{$litige->id}/demander-informations", [
            'cible' => 'client', 'message' => 'Trop tard.',
        ])->assertStatus(400);
    }

    public function test_admin_history_lists_logged_actions(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        [$adminUser] = $this->makeAdmin();
        $commande = $this->makeCommande($client, $vendeur);
        $litige = $this->makeLitige($commande, $clientUser);

        Sanctum::actingAs($adminUser);
        $this->postJson("/api/admin/litiges/{$litige->id}/traiter", [
            'statut' => 'resolu', 'decision' => 'Résolu à l\'amiable.',
        ])->assertStatus(200);

        $response = $this->getJson("/api/admin/litiges/{$litige->id}/historique");
        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    // ------------------------------------------------------------
    // SÉCURITÉ
    // ------------------------------------------------------------

    public function test_unauthenticated_user_cannot_access_litiges(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        $commande = $this->makeCommande($client, $vendeur);
        $litige = $this->makeLitige($commande, $clientUser);

        $this->getJson("/api/litiges/{$litige->id}/messages")->assertStatus(401);
        $this->getJson('/api/admin/litiges')->assertStatus(401);
    }

    public function test_client_cannot_call_admin_only_endpoints(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        $commande = $this->makeCommande($client, $vendeur);
        $litige = $this->makeLitige($commande, $clientUser);

        Sanctum::actingAs($clientUser);
        $this->postJson("/api/admin/litiges/{$litige->id}/decision", [
            'decision_type' => 'acceptee', 'reason' => 'Je m\'auto-accepte.',
        ])->assertStatus(403);
    }

    // ------------------------------------------------------------
    // WORKFLOW
    // ------------------------------------------------------------

    public function test_client_message_moves_litige_out_of_waiting_state(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        $commande = $this->makeCommande($client, $vendeur);
        $litige = $this->makeLitige($commande, $clientUser, 'attente_client');

        Sanctum::actingAs($clientUser);
        $this->postJson("/api/litiges/{$litige->id}/messages", [
            'message' => 'Voici la précision demandée.',
        ])->assertStatus(201);

        $this->assertSame('en_cours', $litige->fresh()->statut);
    }

    public function test_invalid_status_transition_is_rejected(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        [$adminUser] = $this->makeAdmin();
        $commande = $this->makeCommande($client, $vendeur);
        $litige = $this->makeLitige($commande, $clientUser, 'resolu');

        Sanctum::actingAs($adminUser);
        $response = $this->postJson("/api/admin/litiges/{$litige->id}/traiter", [
            'statut' => 'en_cours', 'decision' => 'Réouverture non autorisée.',
        ]);

        $response->assertStatus(400);
        $this->assertSame('resolu', $litige->fresh()->statut);
    }

    public function test_litige_model_transition_table_blocks_illegal_moves(): void
    {
        $litige = new Litige(['statut' => 'resolu']);
        $this->assertFalse($litige->peutTransitionnerVers('attente_vendeur'));
        $this->assertFalse($litige->peutTransitionnerVers('en_cours'));

        $litige = new Litige(['statut' => 'ouvert']);
        $this->assertTrue($litige->peutTransitionnerVers('en_cours'));
        $this->assertTrue($litige->peutTransitionnerVers('escalade'));
    }
}
