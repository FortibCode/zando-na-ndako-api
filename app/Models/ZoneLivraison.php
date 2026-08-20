<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ZoneLivraison extends Model
{
    use HasUuids;

    protected $table = 'zones_livraison';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'nom_zone', 'ville', 'quartiers_couverts', 'frais_livraison_base',
        'delai_estime_min', 'delai_estime_max', 'statut_actif'
    ];

    protected $casts = [
        'quartiers_couverts' => 'array',
        'frais_livraison_base' => 'decimal:2',
        'statut_actif' => 'boolean',
    ];

    // Relations
    public function vendeurs()
    {
        return $this->hasMany(Vendeur::class);
    }

    public function commandes()
    {
        return $this->hasMany(Commande::class);
    }

    // Méthodes
    public function couvreQuartier($quartier)
    {
        return in_array($quartier, $this->quartiers_couverts);
    }

    public function getFraisLivraisonAttribute()
    {
        // Peut être personnalisé selon le quartier
        return $this->frais_livraison_base;
    }
}