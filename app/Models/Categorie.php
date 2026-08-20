<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Categorie extends Model
{
    use HasUuids;

    protected $table = 'categories';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'nom_categorie', 'icone', 'categorie_parente_id'
    ];

    // Relations
    public function parent()
    {
        return $this->belongsTo(Categorie::class, 'categorie_parente_id');
    }

    public function sousCategories()
    {
        return $this->hasMany(Categorie::class, 'categorie_parente_id');
    }

    public function produits()
    {
        return $this->hasMany(Produit::class);
    }

    public function promotions()
    {
        return $this->hasMany(PromotionProduit::class);
    }

    // Accessors
    public function getNomCompletAttribute()
    {
        if ($this->parent) {
            return $this->parent->nom_categorie . ' > ' . $this->nom_categorie;
        }
        return $this->nom_categorie;
    }
}