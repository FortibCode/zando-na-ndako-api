<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    use HasUuids;

    protected $table = 'promotions';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'titre', 'description', 'type_reduction', 'valeur_reduction',
        'date_debut', 'date_fin', 'statut_actif', 'image_bandeau'
    ];

    protected $casts = [
        'valeur_reduction' => 'decimal:2',
        'date_debut' => 'datetime',
        'date_fin' => 'datetime',
        'statut_actif' => 'boolean',
    ];

    // Relations
    public function produits()
    {
        return $this->hasMany(PromotionProduit::class);
    }

    // Méthodes
    public function estActive()
    {
        return $this->statut_actif &&
            $this->date_debut <= now() &&
            $this->date_fin >= now();
    }

    public function getReductionLabelAttribute()
    {
        if ($this->type_reduction === 'pourcentage') {
            return $this->valeur_reduction . '%';
        }
        return number_format($this->valeur_reduction, 0, ',', ' ') . ' FCFA';
    }
}