<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// Promotion créée par un vendeur (self-service) sur toute sa boutique (produit_id NULL) ou un
// produit précis — distincte de App\Models\Promotion, qui reste la bannière marketing gérée par
// l'administrateur (voir AdminController::promotions() / CatalogueController::promotions()).
class PromotionVendeur extends Model
{
    use HasUuids;

    protected $table = 'promotions_vendeurs';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'vendeur_id', 'produit_id', 'titre', 'description',
        'type_reduction', 'valeur_reduction', 'date_debut', 'date_fin', 'actif',
    ];

    protected $casts = [
        'valeur_reduction' => 'decimal:2',
        'date_debut' => 'datetime',
        'date_fin' => 'datetime',
        'actif' => 'boolean',
    ];

    public function vendeur()
    {
        return $this->belongsTo(Vendeur::class);
    }

    public function produit()
    {
        return $this->belongsTo(Produit::class);
    }

    public function estActive(): bool
    {
        if (!$this->actif) return false;
        if ($this->date_debut && $this->date_debut->isFuture()) return false;
        if ($this->date_fin && $this->date_fin->isPast()) return false;
        return true;
    }

    // Utilisée par CatalogueController::promotions() et Produit::promotionVendeurActive() pour ne
    // retenir que les promotions réellement en cours (actif=true et dans la fenêtre de dates).
    public function scopeActive($query)
    {
        return $query->where('actif', true)
            ->where('date_debut', '<=', now())
            ->where(function ($q) {
                $q->whereNull('date_fin')->orWhere('date_fin', '>=', now());
            });
    }
}
