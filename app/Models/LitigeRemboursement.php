<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LitigeRemboursement extends Model
{
    use HasUuids;

    protected $table = 'litige_remboursements';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'litige_id', 'decision_id', 'montant', 'devise', 'statut', 'methode_prevue', 'reference_externe',
    ];

    protected $casts = [
        'montant' => 'decimal:2',
    ];

    public function litige()
    {
        return $this->belongsTo(Litige::class);
    }

    public function decision()
    {
        return $this->belongsTo(LitigeDecision::class, 'decision_id');
    }
}
