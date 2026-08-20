<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Produit extends Model
{
    use HasUuids;

    protected $table = 'produits';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'vendeur_id', 'categorie_id', 'nom_produit', 'description',
        'prix_unitaire', 'unite_mesure', 'quantite_stock',
        'statut_disponibilite', 'photo_produit', 'type_fraicheur',
        'date_maj_prix'
    ];

    protected $casts = [
        'date_maj_prix' => 'datetime',
        'prix_unitaire' => 'decimal:2',
    ];

    // Relations
    public function vendeur()
    {
        return $this->belongsTo(Vendeur::class);
    }

    public function categorie()
    {
        return $this->belongsTo(Categorie::class);
    }

    public function lignesPanier()
    {
        return $this->hasMany(LignePanier::class);
    }

    public function lignesCommande()
    {
        return $this->hasMany(LigneCommande::class);
    }

    public function promotions()
    {
        return $this->hasMany(PromotionProduit::class);
    }

    // Méthodes
    public function estDisponible()
    {
        return $this->statut_disponibilite === 'disponible' && $this->quantite_stock > 0;
    }

    public function getPrixAfficheAttribute()
    {
        return number_format($this->prix_unitaire, 0, ',', ' ') . ' FCFA';
    }

    public function getPrixAvecPromotionAttribute()
    {
        $promo = $this->promotions()
            ->whereHas('promotion', function($query) {
                $query->where('statut_actif', true)
                    ->where('date_debut', '<=', now())
                    ->where('date_fin', '>=', now());
            })
            ->first();

        if ($promo) {
            return $promo->prix_promo;
        }

        return $this->prix_unitaire;
    }

    public function getEstEnPromotionAttribute()
    {
        return $this->promotions()
            ->whereHas('promotion', function($query) {
                $query->where('statut_actif', true)
                    ->where('date_debut', '<=', now())
                    ->where('date_fin', '>=', now());
            })
            ->exists();
    }
}