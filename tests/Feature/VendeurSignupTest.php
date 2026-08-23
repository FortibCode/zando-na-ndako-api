<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendeur;
use App\Models\ZoneLivraison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VendeurSignupTest extends TestCase
{
    use RefreshDatabase;

    private function makeZone(): ZoneLivraison
    {
        return ZoneLivraison::create([
            'nom_zone' => 'Zone Test', 'ville' => 'Brazzaville', 'quartiers_couverts' => ['Test'],
            'frais_livraison_base' => 1000, 'delai_estime_min' => 30, 'delai_estime_max' => 60, 'statut_actif' => true,
        ]);
    }

    private function makeVendeurUser(): array
    {
        $user = User::create([
            'nom' => 'Vendeur', 'prenom' => 'Test', 'telephone' => '08' . random_int(10000000, 99999999),
            'email' => 'vendeur_' . Str::random(8) . '@test.zando', 'mot_de_passe_hash' => Hash::make('password123'),
            'type_utilisateur' => 'vendeur', 'statut_compte' => 'actif', 'consentement_cgu' => true,
        ]);
        $vendeur = Vendeur::create([
            'user_id' => $user->id, 'nom_commerce' => 'Boutique Test', 'categorie_principale' => 'Épicier & Produits alimentaires',
            'statut_validation' => 'valide', 'solde_disponible' => 0,
        ]);
        return [$user, $vendeur];
    }

    public function test_vendor_registration_saves_the_chosen_zone(): void
    {
        $zone = $this->makeZone();

        $response = $this->postJson('/api/register', [
            'nom' => 'Moukala', 'prenom' => 'Serge', 'telephone' => '069' . random_int(1000000, 9999999),
            'mot_de_passe' => 'password123', 'mot_de_passe_confirmation' => 'password123',
            'type_utilisateur' => 'vendeur', 'consentement_cgu' => true,
            'nom_commerce' => 'Marché Frais', 'categorie_principale' => 'Épicier & Produits alimentaires',
            'zone_id' => $zone->id,
        ]);

        $response->assertStatus(201);
        $userId = $response->json('data.user_id');
        $vendeur = Vendeur::where('user_id', $userId)->first();
        $this->assertSame($zone->id, $vendeur->zone_id);
    }

    public function test_vendor_registration_without_zone_still_succeeds(): void
    {
        $response = $this->postJson('/api/register', [
            'nom' => 'Moukala', 'prenom' => 'Serge', 'telephone' => '069' . random_int(1000000, 9999999),
            'mot_de_passe' => 'password123', 'mot_de_passe_confirmation' => 'password123',
            'type_utilisateur' => 'vendeur', 'consentement_cgu' => true,
            'nom_commerce' => 'Marché Frais', 'categorie_principale' => 'Épicier & Produits alimentaires',
        ]);

        $response->assertStatus(201);
    }

    public function test_vendor_registration_rejects_an_unknown_zone(): void
    {
        $response = $this->postJson('/api/register', [
            'nom' => 'Moukala', 'prenom' => 'Serge', 'telephone' => '069' . random_int(1000000, 9999999),
            'mot_de_passe' => 'password123', 'mot_de_passe_confirmation' => 'password123',
            'type_utilisateur' => 'vendeur', 'consentement_cgu' => true,
            'nom_commerce' => 'Marché Frais', 'categorie_principale' => 'Épicier & Produits alimentaires',
            'zone_id' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422);
    }

    public function test_vendeur_can_upload_documents(): void
    {
        Storage::fake('public');
        [$user] = $this->makeVendeurUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/vendeur/documents', [
            'photo_boutique' => UploadedFile::fake()->image('boutique.jpg'),
            'document_identite' => UploadedFile::fake()->image('cni.jpg'),
        ]);

        $response->assertStatus(200);
        $vendeur = Vendeur::where('user_id', $user->id)->first();
        $this->assertNotNull($vendeur->photo_boutique);
        $this->assertNotNull($vendeur->document_identite);
        Storage::disk('public')->assertExists($vendeur->photo_boutique);
    }

    public function test_uploading_a_document_replaces_and_deletes_the_previous_file(): void
    {
        Storage::fake('public');
        [$user] = $this->makeVendeurUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/vendeur/documents', ['photo_boutique' => UploadedFile::fake()->image('v1.jpg')])->assertStatus(200);
        $ancien = Vendeur::where('user_id', $user->id)->first()->photo_boutique;

        $this->postJson('/api/vendeur/documents', ['photo_boutique' => UploadedFile::fake()->image('v2.jpg')])->assertStatus(200);
        $nouveau = Vendeur::where('user_id', $user->id)->first()->photo_boutique;

        $this->assertNotSame($ancien, $nouveau);
        Storage::disk('public')->assertMissing($ancien);
        Storage::disk('public')->assertExists($nouveau);
    }

    public function test_uploading_no_documents_is_rejected(): void
    {
        [$user] = $this->makeVendeurUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/vendeur/documents', [])->assertStatus(422);
    }

    public function test_only_the_owning_vendeur_can_upload_their_documents(): void
    {
        Storage::fake('public');
        [, $vendeurA] = $this->makeVendeurUser();
        [$userB] = $this->makeVendeurUser();
        Sanctum::actingAs($userB);

        $this->postJson('/api/vendeur/documents', ['photo_boutique' => UploadedFile::fake()->image('x.jpg')])->assertStatus(200);

        $this->assertNull($vendeurA->fresh()->photo_boutique);
    }
}
