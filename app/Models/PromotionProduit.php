<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PromotionProduit extends Model
{
    use HasUuids;

    protected $table = 'promotion_produits';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'promotion_id',
        'produit_id',
        'prix_promo',
    ];

    protected $casts = [
        'prix_promo' => 'decimal:2',
    ];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function produit()
    {
        return $this->belongsTo(Produit::class);
    }

    // Utilisé par CatalogueController::promotions() (GET /api/produits/promotions) : sans ce scope,
    // la route plantait avec un BadMethodCallException dès qu'elle était appelée.
    public function scopeActive($query)
    {
        return $query->whereHas('promotion', function ($q) {
            $q->where('statut_actif', true)
                ->where('date_debut', '<=', now())
                ->where('date_fin', '>=', now());
        });
    }
}
