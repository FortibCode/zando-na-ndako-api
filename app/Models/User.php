<?php

namespace App\Models;

use App\Helpers\PermissionHelper;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUuids;

    protected $table = 'users';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'nom', 'prenom', 'date_naissance', 'sexe', 'email', 'telephone',
        'mot_de_passe_hash', 'type_utilisateur', 'langue_preferee',
        'statut_compte', 'photo_profil', 'date_inscription',
        'derniere_connexion', 'google_id', 'devise_preferee',
        'pays_residence', 'ville', 'adresse', 'consentement_cgu',
    ];

    protected $hidden = [
        'mot_de_passe_hash',
        'remember_token',
    ];

    protected $casts = [
        'date_inscription'   => 'datetime',
        'derniere_connexion' => 'datetime',
        'consentement_cgu'   => 'boolean',
    ];

    // ----------------------------------------------------------------
    // Override Authenticatable pour le champ mot_de_passe_hash
    // ----------------------------------------------------------------
    public function getAuthPassword(): string
    {
        return $this->mot_de_passe_hash;
    }

    // ----------------------------------------------------------------
    // Relations
    // ----------------------------------------------------------------
    public function roles()
    {
        return $this->hasMany(RoleUser::class);
    }

    public function client()
    {
        return $this->hasOne(Client::class);
    }

    public function vendeur()
    {
        return $this->hasOne(Vendeur::class);
    }

    public function livreur()
    {
        return $this->hasOne(Livreur::class);
    }

    public function administrateur()
    {
        return $this->hasOne(Administrateur::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function jetonsAppareils()
    {
        return $this->hasMany(JetonAppareil::class);
    }

    public function codesOTP()
    {
        return $this->hasMany(CodeOTP::class);
    }

    // ----------------------------------------------------------------
    // Méthodes
    // ----------------------------------------------------------------
    public function hasRole(string $role): bool
    {
        if ($this->type_utilisateur === $role) {
            return true;
        }
        // administrateurs.role_admin est la source de vérité pour 'super_admin' — un compte créé
        // via creerAdministrateur() n'a pas forcément de ligne role_user correspondante (seul le
        // compte seedé initial en a une, ajoutée manuellement en workaround), donc s'appuyer
        // uniquement sur role_user ici a longtemps bloqué l'accès aux routes super_admin des
        // autres comptes super-admin créés depuis le back-office.
        if ($role === 'super_admin' && $this->administrateur?->isSuperAdmin()) {
            return true;
        }
        return $this->roles()->where('role', $role)->exists();
    }

    public function getNomCompletAttribute(): string
    {
        return $this->prenom . ' ' . $this->nom;
    }

    public function estActif(): bool
    {
        return $this->statut_compte === 'actif';
    }

    public function getProfileRelation()
    {
        return match ($this->type_utilisateur) {
            'client'          => $this->client,
            'vendeur'         => $this->vendeur,
            'livreur'         => $this->livreur,
            'administrateur'  => $this->administrateur,
            default           => null,
        };
    }

    // ----------------------------------------------------------------
    // Gestion des permissions (utilisé par CheckPermission middleware)
    // ----------------------------------------------------------------

    /**
     * Vérifie si l'utilisateur a une permission spécifique.
     * Utilisé par le middleware CheckPermission.
     */
    public function hasPermission(string $permission): bool
    {
        // Super admin a toutes les permissions
        if ($this->hasRole('super_admin')) {
            return true;
        }

        // Vérifier via le profil administrateur si existant
        $admin = $this->administrateur;
        if ($admin) {
            if ($admin->isSuperAdmin()) {
                return true;
            }
            if ($admin->hasPermission($permission)) {
                return true;
            }
        }

        // Vérifier via PermissionHelper pour tous les rôles de l'utilisateur
        $roles = $this->roles()->pluck('role')->toArray();
        $roles[] = $this->type_utilisateur;

        foreach ($roles as $role) {
            $perms = PermissionHelper::getPermissionsByRole($role);
            if (in_array($permission, $perms)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Récupère toutes les permissions de l'utilisateur.
     * Utilisé par le middleware CheckPermission pour les rapports d'erreur.
     */
    public function getPermissions(): array
    {
        // Super admin a toutes les permissions (wildcard)
        if ($this->hasRole('super_admin')) {
            return ['*'];
        }

        $permissions = [];

        // Ajouter les permissions du profil administrateur
        $admin = $this->administrateur;
        if ($admin && $admin->permissions) {
            $permissions = array_merge($permissions, $admin->permissions);
        }

        // Ajouter les permissions via PermissionHelper pour chaque rôle
        $roles = $this->roles()->pluck('role')->toArray();
        $roles[] = $this->type_utilisateur;

        foreach ($roles as $role) {
            $perms = PermissionHelper::getPermissionsByRole($role);
            $permissions = array_merge($permissions, $perms);
        }

        return array_unique($permissions);
    }
}