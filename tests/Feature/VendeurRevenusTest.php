<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Commande;
use App\Models\User;
use App\Models\Vendeur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `panierMoyen` était figé côté mobile (DEFAULT_STATS.panierMoyen = 3242, jamais recalculé) alors
 * que les autres statistiques viennent déjà de /vendeur/revenus. Ce test couvre le calcul réel
 * ajouté à VendeurController::revenus() : moyenne du sous-total des commandes livrées du mois.
 */
class VendeurRevenusTest extends TestCase
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

    private function makeClient(): Client
    {
        return Client::create(['user_id' => $this->makeUser('client')->id]);
    }

    private function makeVendeur(): array
    {
        $user = $this->makeUser('vendeur');
        return [$user, Vendeur::create([
            'user_id' => $user->id, 'nom_commerce' => 'Boutique Test',
            'categorie_principale' => 'alimentation', 'statut_validation' => 'valide', 'solde_disponible' => 0,
        ])];
    }

    private function makeCommandeLivree(Client $client, Vendeur $vendeur, float $sousTotal): Commande
    {
        return Commande::create([
            'numero_commande' => 'CMD-' . strtoupper(Str::random(8)),
            'client_id' => $client->id, 'vendeur_id' => $vendeur->id,
            'date_commande' => now(), 'statut_commande' => 'livree', 'mode_attribution' => 'auto',
            'montant_sous_total' => $sousTotal, 'frais_livraison' => 0, 'montant_total' => $sousTotal,
            'adresse_livraison' => 'Quartier Test, Brazzaville', 'type_commande' => 'locale', 'devise_paiement' => 'FCFA',
        ]);
    }

    public function test_revenus_endpoint_returns_the_real_average_basket(): void
    {
        [$vendeurUser, $vendeur] = $this->makeVendeur();
        $client = $this->makeClient();
        $this->makeCommandeLivree($client, $vendeur, 4000);
        $this->makeCommandeLivree($client, $vendeur, 6000);

        Sanctum::actingAs($vendeurUser);
        $this->getJson('/api/vendeur/revenus')
            ->assertStatus(200)
            ->assertJsonPath('data.panier_moyen', 5000);
    }

    public function test_revenus_endpoint_returns_zero_average_basket_when_no_delivered_orders(): void
    {
        [$vendeurUser] = $this->makeVendeur();

        Sanctum::actingAs($vendeurUser);
        $this->getJson('/api/vendeur/revenus')
            ->assertStatus(200)
            ->assertJsonPath('data.panier_moyen', 0);
    }
}
