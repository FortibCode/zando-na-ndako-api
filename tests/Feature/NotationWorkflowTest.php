<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Commande;
use App\Models\Livreur;
use App\Models\User;
use App\Models\Vendeur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $type): User
    {
        return User::create([
            'nom' => 'Test',
            'prenom' => ucfirst($type),
            'telephone' => '08' . random_int(10000000, 99999999),
            'email' => strtolower($type) . '_' . Str::random(8) . '@test.zando',
            'mot_de_passe_hash' => Hash::make('password123'),
            'type_utilisateur' => $type,
            'statut_compte' => 'actif',
            'consentement_cgu' => true,
        ]);
    }

    private function makeClient(): array
    {
        $user = $this->makeUser('client');
        return [$user, Client::create(['user_id' => $user->id])];
    }

    private function makeVendeur(): array
    {
        $user = $this->makeUser('vendeur');
        return [$user, Vendeur::create([
            'user_id' => $user->id, 'nom_commerce' => 'Boutique Test',
            'categorie_principale' => 'alimentation', 'statut_validation' => 'valide', 'solde_disponible' => 0,
        ])];
    }

    private function makeLivreur(): array
    {
        $user = $this->makeUser('livreur');
        return [$user, Livreur::create([
            'user_id' => $user->id, 'type_vehicule' => 'moto', 'immatriculation_vehicule' => 'ABC-123',
            'statut_disponibilite' => 'disponible', 'statut_validation' => 'valide',
        ])];
    }

    private function makeCommande(Client $client, Vendeur $vendeur, ?Livreur $livreur = null, string $statut = 'livree'): Commande
    {
        return Commande::create([
            'numero_commande' => 'CMD-' . strtoupper(Str::random(8)),
            'client_id' => $client->id,
            'vendeur_id' => $vendeur->id,
            'livreur_id' => $livreur?->id,
            'date_commande' => now(),
            'statut_commande' => $statut,
            'mode_attribution' => 'auto',
            'montant_sous_total' => 10000,
            'frais_livraison' => 0,
            'montant_total' => 10000,
            'adresse_livraison' => 'Quartier Test, Brazzaville',
            'type_commande' => 'locale',
            'devise_paiement' => 'FCFA',
        ]);
    }

    public function test_client_can_rate_vendeur_and_livreur_and_average_updates(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        [, $livreur] = $this->makeLivreur();
        $commande = $this->makeCommande($client, $vendeur, $livreur);

        Sanctum::actingAs($clientUser);

        $r1 = $this->postJson("/api/commandes/{$commande->id}/notation", ['note' => 5, 'commentaire' => 'Excellent vendeur', 'cible' => 'vendeur']);
        $r1->assertStatus(201);

        $r2 = $this->postJson("/api/commandes/{$commande->id}/notation", ['note' => 4, 'cible' => 'livreur']);
        $r2->assertStatus(201);

        $vendeur->refresh();
        $livreur->refresh();
        $this->assertEquals(5.00, (float) $vendeur->note_moyenne);
        $this->assertEquals(4.00, (float) $livreur->note_moyenne);
    }

    public function test_vendeur_and_livreur_can_rate_client(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [$vendeurUser, $vendeur] = $this->makeVendeur();
        [$livreurUser, $livreur] = $this->makeLivreur();
        $commande = $this->makeCommande($client, $vendeur, $livreur);

        Sanctum::actingAs($vendeurUser);
        $this->postJson("/api/commandes/{$commande->id}/notation", ['note' => 3, 'commentaire' => 'Client correct'])
            ->assertStatus(201);

        Sanctum::actingAs($livreurUser);
        $this->postJson("/api/commandes/{$commande->id}/notation", ['note' => 5])
            ->assertStatus(201);

        $client->refresh();
        $this->assertEquals(4.00, (float) $client->note_moyenne);
    }

    public function test_client_must_specify_which_target_to_rate(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        $commande = $this->makeCommande($client, $vendeur);

        Sanctum::actingAs($clientUser);
        $this->postJson("/api/commandes/{$commande->id}/notation", ['note' => 5])
            ->assertStatus(422);
    }

    public function test_cannot_rate_the_same_direction_twice(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        $commande = $this->makeCommande($client, $vendeur);

        Sanctum::actingAs($clientUser);
        $this->postJson("/api/commandes/{$commande->id}/notation", ['note' => 5, 'cible' => 'vendeur'])->assertStatus(201);
        $this->postJson("/api/commandes/{$commande->id}/notation", ['note' => 2, 'cible' => 'vendeur'])->assertStatus(409);
    }

    public function test_cannot_rate_a_commande_that_is_not_delivered_yet(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        $commande = $this->makeCommande($client, $vendeur, null, 'confirmee');

        Sanctum::actingAs($clientUser);
        $this->postJson("/api/commandes/{$commande->id}/notation", ['note' => 5, 'cible' => 'vendeur'])
            ->assertStatus(400);
    }

    public function test_non_participant_cannot_rate_the_commande(): void
    {
        [, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        $commande = $this->makeCommande($client, $vendeur);

        [$strangerUser] = $this->makeClient();
        Sanctum::actingAs($strangerUser);
        $this->postJson("/api/commandes/{$commande->id}/notation", ['note' => 5, 'cible' => 'vendeur'])
            ->assertStatus(403);
    }

    public function test_vendeur_can_list_own_avis(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [$vendeurUser, $vendeur] = $this->makeVendeur();
        $commande = $this->makeCommande($client, $vendeur);

        Sanctum::actingAs($clientUser);
        $this->postJson("/api/commandes/{$commande->id}/notation", ['note' => 5, 'commentaire' => 'Top', 'cible' => 'vendeur'])
            ->assertStatus(201);

        Sanctum::actingAs($vendeurUser);
        $response = $this->getJson('/api/vendeur/avis');
        $response->assertStatus(200)
            ->assertJsonPath('data.note_moyenne', 5)
            ->assertJsonPath('data.nombre_avis', 1)
            ->assertJsonPath('data.avis.0.commentaire', 'Top');
    }

    public function test_public_can_view_vendeur_avis(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur();
        $commande = $this->makeCommande($client, $vendeur);

        Sanctum::actingAs($clientUser);
        $this->postJson("/api/commandes/{$commande->id}/notation", ['note' => 4, 'commentaire' => 'Bien', 'cible' => 'vendeur'])
            ->assertStatus(201);

        $response = $this->getJson("/api/vendeurs/{$vendeur->id}/avis");
        $response->assertStatus(200)->assertJsonPath('data.note_moyenne', 4);
    }
}
