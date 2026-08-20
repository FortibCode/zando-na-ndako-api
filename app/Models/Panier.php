<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Panier extends Model
{
    use HasUuids;

    protected $table = 'paniers';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'client_id', 'statut', 'date_creation', 'date_maj',
        'beneficiaire_id', 'lien_partage_panier'
    ];

    protected $casts = [
        'date_creation' => 'datetime',
        'date_maj' => 'datetime',
    ];

    // Relations
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function beneficiaire()
    {
        return $this->belongsTo(Beneficiaire::class);
    }

    public function lignes()
    {
        return $this->hasMany(LignePanier::class);
    }

    // Méthodes
    public function getSousTotalAttribute()
    {
        return $this->lignes->sum(function ($ligne) {
            return $ligne->quantite * $ligne->prix_unitaire_moment;
        });
    }

    public function getNombreArticlesAttribute()
    {
        return $this->lignes->sum('quantite');
    }

    public function estVide()
    {
        return $this->lignes->isEmpty();
    }

    public function vider()
    {
        return $this->lignes()->delete();
    }

    public function genererLienPartage()
    {
        $this->update([
            'lien_partage_panier' => \Illuminate\Support\Str::random(32)
        ]);
        return $this->lien_partage_panier;
    }
}