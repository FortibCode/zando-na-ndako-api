<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Beneficiaire extends Model
{
    use HasUuids;

    protected $table = 'beneficiaires';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'client_id', 'nom', 'telephone', 'ville', 'adresse', 'quartier',
        'coordonnees_gps', 'instructions', 'date_ajout', 'relation', 'est_defaut'
    ];

    protected $casts = [
        'coordonnees_gps' => 'array',
        'date_ajout' => 'datetime',
        'est_defaut' => 'boolean',
    ];

    // Relations
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function commandes()
    {
        return $this->hasMany(Commande::class);
    }

    public function paniers()
    {
        return $this->hasMany(Panier::class);
    }

    // Méthodes
    public function getNomCompletAttribute()
    {
        return $this->nom;
    }

    public function getAdresseCompleteAttribute()
    {
        return $this->adresse . ' (' . $this->quartier . ')';
    }
}