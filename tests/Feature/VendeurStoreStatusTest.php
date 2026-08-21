<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\Client;
use App\Models\LignePanier;
use App\Models\Panier;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vendeur;
use App\Models\ZoneLivraison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Le statut de boutique (ouverte/pause/fermee) n'était jusqu'ici qu'un état local côté mobile : le
 * vendeur croyait qu'une boutique "en pause" arrêtait les nouvelles commandes, mais rien côté
 * serveur ne l'appliquait. Ces tests couvrent : la persistance du statut, le blocage de la création
 * de commande pour une boutique non "ouverte", et le filtrage du catalogue public pour une boutique
 * "fermée".
 */
class VendeurStoreStatusTest extends TestCase
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

    private function makeVendeur(string $statutBoutique = 'ouverte'): array
    {
        $user = $this->makeUser('vendeur');
        return [$user, Vendeur::create([
            'user_id' => $user->id, 'nom_commerce' => 'Boutique Test',
            'categorie_principale' => 'alimentation', 'statut_validation' => 'valide',
            'statut_boutique' => $statutBoutique, 'solde_disponible' => 0,
        ])];
    }

    private function makeZone(): ZoneLivraison
    {
        return ZoneLivraison::create([
            'nom_zone' => 'Zone Test', 'ville' => 'Brazzaville', 'quartiers_couverts' => ['Test'],
            'frais_livraison_base' => 1000, 'delai_estime_min' => 30, 'delai_estime_max' => 60, 'statut_actif' => true,
        ]);
    }

    private function makeProduit(Vendeur $vendeur): Produit
    {
        $categorie = Categorie::create(['nom_categorie' => 'Catégorie Test']);
        return Produit::create([
            'vendeur_id' => $vendeur->id, 'categorie_id' => $categorie->id,
            'nom_produit' => 'Produit Test', 'prix_unitaire' => 2000, 'unite_mesure' => 'kg',
            'quantite_stock' => 10, 'statut_disponibilite' => 'disponible', 'type_fraicheur' => 'frais',
        ]);
    }

    // Remplit le panier actif du client avec le produit donné.
    private function remplirPanier(Client $client, Produit $produit): void
    {
        $panier = Panier::create(['client_id' => $client->id, 'statut' => 'actif', 'date_creation' => now(), 'date_maj' => now()]);
        LignePanier::create(['panier_id' => $panier->id, 'produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_moment' => $produit->prix_unitaire]);
    }

    private function validerCommande(ZoneLivraison $zone): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/commandes/valider', [
            'adresse_livraison' => 'Quartier Test, Brazzaville',
            'zone_id' => $zone->id,
            'creneau_livraison_debut' => now()->addHours(2)->toIso8601String(),
            'creneau_livraison_fin' => now()->addHours(3)->toIso8601String(),
        ]);
    }

    public function test_vendeur_can_update_statut_boutique(): void
    {
        [$vendeurUser, $vendeur] = $this->makeVendeur();
        Sanctum::actingAs($vendeurUser);

        $response = $this->putJson('/api/vendeur/statut-boutique', ['statut_boutique' => 'pause']);

        $response->assertStatus(200)->assertJsonPath('data.statut_boutique', 'pause');
        $this->assertSame('pause', $vendeur->fresh()->statut_boutique);
    }

    public function test_statut_boutique_rejects_an_invalid_value(): void
    {
        [$vendeurUser] = $this->makeVendeur();
        Sanctum::actingAs($vendeurUser);

        $this->putJson('/api/vendeur/statut-boutique', ['statut_boutique' => 'invalide'])->assertStatus(422);
    }

    public function test_non_vendeur_cannot_update_statut_boutique(): void
    {
        [$clientUser] = $this->makeClient();
        Sanctum::actingAs($clientUser);

        $this->putJson('/api/vendeur/statut-boutique', ['statut_boutique' => 'pause'])->assertStatus(403);
    }

    public function test_dashboard_exposes_the_real_statut_boutique(): void
    {
        [$vendeurUser] = $this->makeVendeur('pause');
        Sanctum::actingAs($vendeurUser);

        $this->getJson('/api/vendeur/dashboard')->assertStatus(200)->assertJsonPath('data.statut_boutique', 'pause');
    }

    public function test_order_creation_succeeds_when_boutique_is_ouverte(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur('ouverte');
        $produit = $this->makeProduit($vendeur);
        $zone = $this->makeZone();
        $this->remplirPanier($client, $produit);

        Sanctum::actingAs($clientUser);
        $this->validerCommande($zone)->assertStatus(201);
    }

    public function test_order_creation_is_blocked_when_boutique_is_paused(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur('pause');
        $produit = $this->makeProduit($vendeur);
        $zone = $this->makeZone();
        $this->remplirPanier($client, $produit);

        Sanctum::actingAs($clientUser);
        $this->validerCommande($zone)
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'VENDEUR_INDISPONIBLE');
    }

    public function test_order_creation_is_blocked_when_boutique_is_fermee(): void
    {
        [$clientUser, $client] = $this->makeClient();
        [, $vendeur] = $this->makeVendeur('fermee');
        $produit = $this->makeProduit($vendeur);
        $zone = $this->makeZone();
        $this->remplirPanier($client, $produit);

        Sanctum::actingAs($clientUser);
        $this->validerCommande($zone)
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'VENDEUR_INDISPONIBLE');
    }

    public function test_public_catalogue_excludes_products_from_a_closed_boutique(): void
    {
        [, $vendeurOuvert] = $this->makeVendeur('ouverte');
        [, $vendeurFerme] = $this->makeVendeur('fermee');
        $produitVisible = $this->makeProduit($vendeurOuvert);
        $produitCache = $this->makeProduit($vendeurFerme);

        $response = $this->getJson('/api/produits');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($produitVisible->id));
        $this->assertFalse($ids->contains($produitCache->id));
    }

    public function test_public_catalogue_still_shows_products_from_a_paused_boutique(): void
    {
        // "En pause" ne bloque que les nouvelles commandes (CommandeController::valider), pas la
        // visibilité au catalogue — seule "fermée" retire la boutique du catalogue public.
        [, $vendeurPause] = $this->makeVendeur('pause');
        $produit = $this->makeProduit($vendeurPause);

        $response = $this->getJson('/api/produits');

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($produit->id));
    }
}
