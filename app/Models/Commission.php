<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
    use HasUuids;

    protected $table = 'commissions';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'commande_id', 'taux_commission', 'montant_commission',
        'statut', 'date_calcul'
    ];

    protected $casts = [
        'taux_commission'    => 'decimal:2',
        'montant_commission' => 'decimal:2',
        'date_calcul'        => 'datetime',
    ];

    public function commande()
    {
        return $this->belongsTo(Commande::class);
    }
}
