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
        'user_id', 'nom_commerce', 'categorie_principale', 'zone_id', 'coordonnees_gps',
        'note_moyenne', 'statut_validation', 'statut_boutique', 'message_boutique', 'solde_disponible',
        'photo_boutique', 'document_identite', 'registre_commerce',
        'numero_mobile_money_reception', 'horaires_ouverture',
        'delai_moyen_preparation'
    ];

    protected $casts = [
        'coordonnees_gps' => 'array',
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
}