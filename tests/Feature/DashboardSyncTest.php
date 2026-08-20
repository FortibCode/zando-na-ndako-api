<?php

namespace Tests\Feature;

use App\Models\Administrateur;
use App\Models\Client;
use App\Models\Commande;
use App\Models\User;
use App\Models\Vendeur;
use App\Support\DashboardCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // RefreshDatabase ne réinitialise que Postgres : le compteur de version Redis de
        // DashboardCache persiste entre les méthodes de test et fausserait les assertions
        // "avant/après" si on ne repart pas d'un état Redis propre à chaque test.
        \Illuminate\Support\Facades\Cache::store('redis')->flush();
    }

    private function makeAdmin(): User
    {
        $user = User::create([
            'nom' => 'Admin', 'prenom' => 'Test', 'telephone' => '08' . random_int(10000000, 99999999),
            'email' => 'admin_' . Str::random(8) . '@test.zando', 'mot_de_passe_hash' => Hash::make('password123'),
            'type_utilisateur' => 'administrateur', 'statut_compte' => 'actif', 'consentement_cgu' => true,
        ]);
        Administrateur::create(['user_id' => $user->id, 'role_admin' => 'super_admin', 'permissions' => ['all'], 'date_nomination' => now()]);
        return $user;
    }

    private function makeCommande(): Commande
    {
        $clientUser = User::create([
            'nom' => 'Client', 'prenom' => 'Test', 'telephone' => '08' . random_int(10000000, 99999999),
            'email' => 'client_' . Str::random(8) . '@test.zando', 'mot_de_passe_hash' => Hash::make('password123'),
            'type_utilisateur' => 'client', 'statut_compte' => 'actif', 'consentement_cgu' => true,
        ]);
        $client = Client::create(['user_id' => $clientUser->id]);

        $vendeurUser = User::create([
            'nom' => 'Vendeur', 'prenom' => 'Test', 'telephone' => '08' . random_int(10000000, 99999999),
            'email' => 'vendeur_' . Str::random(8) . '@test.zando', 'mot_de_passe_hash' => Hash::make('password123'),
            'type_utilisateur' => 'vendeur', 'statut_compte' => 'actif', 'consentement_cgu' => true,
        ]);
        $vendeur = Vendeur::create([
            'user_id' => $vendeurUser->id, 'nom_commerce' => 'Boutique Test', 'categorie_principale' => 'alimentation',
            'statut_validation' => 'valide', 'solde_disponible' => 0,
        ]);

        return Commande::create([
            'numero_commande' => 'ZN-' . strtoupper(Str::random(8)), 'client_id' => $client->id, 'vendeur_id' => $vendeur->id,
            'date_commande' => now(), 'statut_commande' => 'confirmee', 'mode_attribution' => 'auto',
            'montant_sous_total' => 5000, 'frais_livraison' => 1000, 'montant_total' => 6000,
            'adresse_livraison' => 'Test', 'type_commande' => 'locale', 'devise_paiement' => 'FCFA',
        ]);
    }

    public function test_dashboard_reflects_new_data_immediately_after_a_bump(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $avant = $this->getJson('/api/admin/dashboard')->json('data.commandes.total');

        $this->makeCommande();
        DashboardCache::bump();

        $apres = $this->getJson('/api/admin/dashboard')->json('data.commandes.total');

        $this->assertSame($avant + 1, $apres, 'Le dashboard doit refléter la nouvelle commande dès que le cache est invalidé.');
    }

    public function test_dashboard_still_caches_when_nothing_bumps_it(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $avant = $this->getJson('/api/admin/dashboard')->json('data.commandes.total');

        // Une commande créée directement en base, sans passer par un endpoint qui bump()
        // le cache : le compteur mis en cache doit rester figé (preuve que la mise en cache
        // fonctionne toujours réellement, pas juste devenue un no-op).
        $this->makeCommande();

        $apres = $this->getJson('/api/admin/dashboard')->json('data.commandes.total');

        $this->assertSame($avant, $apres, 'Sans bump(), le dashboard doit rester sur la valeur mise en cache.');
    }

    public function test_clear_cache_endpoint_also_invalidates_the_dashboard(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $avant = $this->getJson('/api/admin/dashboard')->json('data.commandes.total');
        $this->makeCommande();

        $this->postJson('/api/admin/cache/clear')->assertStatus(200);

        $apres = $this->getJson('/api/admin/dashboard')->json('data.commandes.total');
        $this->assertSame($avant + 1, $apres);
    }
}
