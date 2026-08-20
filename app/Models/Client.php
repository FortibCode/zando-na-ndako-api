<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasUuids;

    protected $table = 'clients';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'adresse_principale', 'est_diaspora', 'coordonnees_gps', 'note_moyenne'
    ];

    protected $casts = [
        'coordonnees_gps' => 'array',
        'est_diaspora' => 'boolean',
    ];

    // Relations
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function beneficiaires()
    {
        return $this->hasMany(Beneficiaire::class);
    }

    public function panier()
    {
        return $this->hasOne(Panier::class)->where('statut', 'actif');
    }

    public function adressesLivraison()
    {
        return $this->hasMany(AdresseLivraison::class)->orderBy('est_defaut', 'desc');
    }

    public function commandes()
    {
        return $this->hasMany(Commande::class);
    }

    public function notifications()
    {
        return $this->hasManyThrough(Notification::class, User::class);
    }

    // Méthodes
    public function getPanierActifAttribute()
    {
        return $this->panier()->firstOrCreate(['statut' => 'actif']);
    }
}