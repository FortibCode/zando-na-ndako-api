<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Vendeur extends Model
{
    use HasUuids;

    protected $table = 'vendeurs';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'nom_commerce', 'categorie_principale', 'zone_id', 'arrondissement',
        'quartier', 'quartier_custom', 'coordonnees_gps',
        'note_moyenne', 'statut_validation', 'statut_boutique', 'message_boutique', 'solde_disponible',
        'photo_boutique', 'document_identite', 'registre_commerce',
        'numero_mobile_money_reception', 'horaires_ouverture',
        'delai_moyen_preparation',
        'formule_abonnement', 'date_expiration_abonnement', 'statut_abonnement'
    ];

    protected $casts = [
        'coordonnees_gps' => 'array',
        'date_expiration_abonnement' => 'datetime',
    ];

    protected $appends = [
        'formule_abonnement',
        'statut_abonnement',
        'est_abonnement_actif',
        'badge_vendeur',
        'limite_produits',
    ];

    // Relations
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function zone()
    {
        return $this->belongsTo(ZoneLivraison::class);
    }

    public function produits()
    {
        return $this->hasMany(Produit::class);
    }

    public function commandes()
    {
        return $this->hasMany(Commande::class);
    }

    public function messages()
    {
        return $this->hasMany(MessagerieVendeurAdmin::class);
    }

    public function retraits()
    {
        return $this->hasMany(RetraitVendeur::class);
    }

    // Promotions créées par ce vendeur lui-même (self-service) — voir PromotionVendeur.
    public function promotionsVendeur()
    {
        return $this->hasMany(PromotionVendeur::class);
    }

    // Accessors
    public function getNoteMoyenneAttribute($value)
    {
        return number_format($value, 2);
    }

    public function getFormuleAbonnementAttribute($value): string
    {
        return $value ?: 'gratuit';
    }

    public function getStatutAbonnementAttribute($value): string
    {
        if (($this->attributes['formule_abonnement'] ?? 'gratuit') === 'gratuit') {
            return 'gratuit';
        }
        if (!empty($this->attributes['date_expiration_abonnement'])) {
            $exp = \Carbon\Carbon::parse($this->attributes['date_expiration_abonnement']);
            if (now()->gt($exp)) {
                return 'expire';
            }
        }
        return $value ?: 'actif';
    }

    public function getEstAbonnementActifAttribute(): bool
    {
        if ($this->formule_abonnement === 'gratuit') {
            return true;
        }
        return $this->statut_abonnement === 'actif';
    }

    public function getBadgeVendeurAttribute(): ?string
    {
        if (!$this->est_abonnement_actif) {
            return null;
        }
        return match ($this->formule_abonnement) {
            'vip' => 'VIP Gold',
            'pro' => 'Boutique Pro Verified',
            default => null,
        };
    }

    public function getLimiteProduitsAttribute(): int
    {
        if ($this->est_abonnement_actif && in_array($this->formule_abonnement, ['pro', 'vip'], true)) {
            return 999999;
        }
        return 10;
    }
}