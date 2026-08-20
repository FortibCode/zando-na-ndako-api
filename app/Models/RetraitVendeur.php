<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RetraitVendeur extends Model
{
    use HasUuids;

    protected $table = 'retraits_vendeurs';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'vendeur_id', 'montant', 'methode_retrait', 'numero_reception',
        'statut', 'date_demande', 'date_traitement'
    ];

    protected $casts = [
        'montant'         => 'decimal:2',
        'date_demande'    => 'datetime',
        'date_traitement' => 'datetime',
    ];

    public function vendeur()
    {
        return $this->belongsTo(Vendeur::class);
    }
}
