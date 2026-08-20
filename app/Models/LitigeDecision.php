<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LitigeDecision extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'litige_decisions';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'litige_id', 'admin_id', 'decision_type', 'reason', 'amount', 'currency', 'created_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public const DECLENCHE_REMBOURSEMENT = ['remboursement_total', 'remboursement_partiel'];

    public function litige()
    {
        return $this->belongsTo(Litige::class);
    }

    public function admin()
    {
        return $this->belongsTo(Administrateur::class, 'admin_id');
    }

    public function remboursement()
    {
        return $this->hasOne(LitigeRemboursement::class, 'decision_id');
    }
}
