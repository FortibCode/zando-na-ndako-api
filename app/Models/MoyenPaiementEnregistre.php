<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MoyenPaiementEnregistre extends Model
{
    use HasUuids;

    protected $table = 'moyens_paiement_enregistres';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'type_moyen', 'token_paiement', 'numero_masque',
        'est_par_defaut', 'devise', 'statut', 'nom_titulaire',
        'date_expiration_carte', 'pays_emission'
    ];

    protected $casts = [
        'est_par_defaut' => 'boolean',
    ];

    // Relations
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function paiements()
    {
        return $this->hasMany(Paiement::class);
    }

    // Méthodes
    public function getTypeLabelAttribute()
    {
        return [
            'airtel_money' => 'Airtel Money',
            'mtn_momo' => 'MTN Mobile Money',
            'carte_bancaire' => 'Carte Bancaire',
            'paypal' => 'PayPal',
            'stripe' => 'Stripe',
        ][$this->type_moyen] ?? $this->type_moyen;
    }
}