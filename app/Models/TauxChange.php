<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TauxChange extends Model
{
    use HasUuids;

    protected $table = 'taux_change';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'devise_source', 'devise_cible', 'valeur_taux',
        'date_maj', 'source_taux'
    ];

    protected $casts = [
        'valeur_taux' => 'decimal:6',
        'date_maj' => 'datetime',
    ];

    // Relations
    public function paiements()
    {
        return $this->hasMany(Paiement::class);
    }

    // Méthodes
    public static function getTaux($source, $cible)
    {
        return static::where('devise_source', $source)
            ->where('devise_cible', $cible)
            ->first();
    }

    public static function convertir($montant, $source, $cible)
    {
        $taux = static::getTaux($source, $cible);
        if ($taux) {
            return $montant * $taux->valeur_taux;
        }
        return $montant;
    }
}