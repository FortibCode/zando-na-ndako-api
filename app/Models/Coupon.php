<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasUuids;

    protected $table = 'coupons';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code', 'description', 'type_reduction', 'valeur_reduction',
        'montant_minimum_commande', 'montant_maximum_reduction',
        'date_debut', 'date_fin',
        'limite_utilisation_totale', 'limite_utilisation_par_client',
        'statut_actif',
    ];

    protected $casts = [
        'valeur_reduction' => 'decimal:2',
        'montant_minimum_commande' => 'decimal:2',
        'montant_maximum_reduction' => 'decimal:2',
        'date_debut' => 'datetime',
        'date_fin' => 'datetime',
        'statut_actif' => 'boolean',
    ];

    public function commandes()
    {
        return $this->hasMany(Commande::class);
    }

    public function estActif(): bool
    {
        return $this->statut_actif
            && $this->date_debut <= now()
            && $this->date_fin >= now();
    }

    /**
     * Calcule la réduction applicable pour un sous-total donné, plafonnée à
     * montant_maximum_reduction si défini et jamais supérieure au sous-total lui-même.
     */
    public function calculerReduction(float $sousTotal): float
    {
        $reduction = $this->type_reduction === 'pourcentage'
            ? $sousTotal * ((float) $this->valeur_reduction / 100)
            : (float) $this->valeur_reduction;

        if ($this->montant_maximum_reduction !== null) {
            $reduction = min($reduction, (float) $this->montant_maximum_reduction);
        }

        return round(min($reduction, $sousTotal), 2);
    }
}
