<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LigneCommande extends Model
{
    use HasUuids;

    protected $table = 'lignes_commande';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'commande_id', 'produit_id', 'quantite', 'prix_unitaire', 'sous_total'
    ];

    protected $casts = [
        'prix_unitaire' => 'decimal:2',
        'sous_total' => 'decimal:2',
    ];

    // Relations
    public function commande()
    {
        return $this->belongsTo(Commande::class);
    }

    public function produit()
    {
        return $this->belongsTo(Produit::class);
    }

    // Méthodes
    public function getPrixTotalAttribute()
    {
        return $this->quantite * $this->prix_unitaire;
    }

    public function getNomProduitAttribute()
    {
        return $this->produit->nom_produit;
    }
}