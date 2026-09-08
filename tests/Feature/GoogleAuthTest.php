<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_login_new_account_creation_instant_1_click(): void
    {
        $response = $this->postJson('/api/auth/google', [
            'id_token' => 'mock_google_token_instantnewuser@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Connexion réussie.',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'instantnewuser@example.com',
            'type_utilisateur' => 'client',
            'statut_compte' => 'actif',
        ]);
    }

    public function test_google_login_existing_user(): void
    {
        $user = User::create([
            'nom' => 'Dupont',
            'prenom' => 'Jean',
            'email' => 'existing@example.com',
            'telephone' => '+242060000000',
            'mot_de_passe_hash' => Hash::make('Secret123!'),
            'type_utilisateur' => 'client',
            'statut_compte' => 'actif',
            'consentement_cgu' => true,
        ]);

        $response = $this->postJson('/api/auth/google', [
            'id_token' => 'mock_google_token_existing@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'email' => 'existing@example.com',
                    ],
                ],
            ]);
    }
}
