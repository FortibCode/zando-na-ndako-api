<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LignePanier extends Model
{
    use HasUuids;

    protected $table = 'lignes_panier';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'panier_id', 'produit_id', 'quantite', 'prix_unitaire_moment'
    ];

    protected $casts = [
        'prix_unitaire_moment' => 'decimal:2',
    ];

    // Relations
    public function panier()
    {
        return $this->belongsTo(Panier::class);
    }

    public function produit()
    {
        return $this->belongsTo(Produit::class);
    }

    // Méthodes
    public function getSousTotalAttribute()
    {
        return $this->quantite * $this->prix_unitaire_moment;
    }
}