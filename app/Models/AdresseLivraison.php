<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AdresseLivraison extends Model
{
    use HasUuids;

    protected $table = 'adresses_livraison';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'client_id', 'label', 'nom_complet', 'telephone', 'ville',
        'quartier', 'adresse', 'coordonnees_gps', 'instructions', 'est_defaut'
    ];

    protected $casts = [
        'coordonnees_gps' => 'array',
        'est_defaut'      => 'boolean',
    ];

    // Relations
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function commandes()
    {
        return $this->hasMany(Commande::class, 'adresse_livraison_id');
    }

    // Méthodes
    public function getAdresseCompleteAttribute(): string
    {
        return trim(
            implode(', ', array_filter([
                $this->adresse,
                $this->quartier,
                $this->ville,
            ]))
        );
    }

    public function getDestinataireAttribute(): string
    {
        return $this->nom_complet ?: ($this->client?->user?->nom_complet ?? 'Client');
    }
}