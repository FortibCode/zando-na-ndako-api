<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SuiviPositionLivraison extends Model
{
    use HasUuids;

    protected $table = 'suivi_position_livraisons';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'livraison_id', 'latitude', 'longitude', 'horodatage'
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'horodatage' => 'datetime',
    ];

    // Relations
    public function livraison()
    {
        return $this->belongsTo(Livraison::class);
    }

    // Méthodes
    public function getPositionAttribute()
    {
        return [
            'lat' => (float) $this->latitude,
            'lng' => (float) $this->longitude,
        ];
    }
}