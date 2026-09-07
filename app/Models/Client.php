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

    protected $appends = [
        'commandes_count',
        'total_depense',
        'code_fidelite',
        'badge_label',
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

    // Méthodes & Accesseurs de Fidélité
    public function getPanierActifAttribute()
    {
        return $this->panier()->firstOrCreate(['statut' => 'actif']);
    }

    public function getCommandesCountAttribute(): int
    {
        return $this->commandes()->count();
    }

    public function getTotalDepenseAttribute(): float
    {
        return (float) $this->commandes()->where('statut_commande', 'livree')->sum('montant_total');
    }

    public function getCodeFideliteAttribute(): string
    {
        $livrees = $this->commandes()->where('statut_commande', 'livree')->count();
        $total = $this->commandes_count;
        $depense = $this->total_depense;

        if ($livrees >= 10 || $depense >= 100000) {
            return 'vip';
        }
        if ($livrees >= 5) {
            return 'fidele';
        }
        if ($total >= 1) {
            return 'regulier';
        }
        return 'nouveau';
    }

    public function getBadgeLabelAttribute(): string
    {
        return match ($this->code_fidelite) {
            'vip' => 'Client VIP',
            'fidele' => 'Client Fidèle',
            'regulier' => 'Client Régulier',
            default => 'Nouveau Client',
        };
    }
}