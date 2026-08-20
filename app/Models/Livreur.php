<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Livreur extends Model
{
    use HasUuids;

    protected $table = 'livreurs';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'type_vehicule', 'immatriculation_vehicule',
        'statut_disponibilite', 'statut_validation', 'note_moyenne',
        'solde_disponible', 'document_identite', 'permis_conduire',
        'zone_intervention', 'numero_mobile_money_reception',
    ];

    protected $casts = [
        'zone_intervention' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function livraisons()
    {
        return $this->hasMany(Livraison::class);
    }

    public function retraits()
    {
        return $this->hasMany(RetraitLivreur::class);
    }
}
