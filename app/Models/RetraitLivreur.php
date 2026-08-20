<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RetraitLivreur extends Model
{
    use HasUuids;

    protected $table = 'retraits_livreurs';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'livreur_id', 'montant', 'methode_retrait', 'numero_reception',
        'statut', 'date_demande', 'date_traitement'
    ];

    protected $casts = [
        'montant'         => 'decimal:2',
        'date_demande'    => 'datetime',
        'date_traitement' => 'datetime',
    ];

    public function livreur()
    {
        return $this->belongsTo(Livreur::class);
    }
}
