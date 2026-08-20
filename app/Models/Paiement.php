<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Paiement extends Model
{
    use HasUuids;

    protected $table = 'paiements';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'commande_id', 'moyen_paiement_id', 'methode', 'montant',
        'devise', 'statut', 'date_paiement', 'reference_transaction_externe',
        'taux_conversion_applique'
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'taux_conversion_applique' => 'decimal:4',
        'date_paiement' => 'datetime',
    ];

    // Relations
    public function commande()
    {
        return $this->belongsTo(Commande::class);
    }

    public function moyenPaiement()
    {
        return $this->belongsTo(MoyenPaiementEnregistre::class);
    }

    // Méthodes
    public function estValide()
    {
        return $this->statut === 'valide';
    }

    public function estEnAttente()
    {
        return $this->statut === 'en_attente';
    }

    public function getMontantEnFCFAAttribute()
    {
        if ($this->devise === 'FCFA') {
            return $this->montant;
        }

        $taux = TauxChange::where('devise_source', $this->devise)
            ->where('devise_cible', 'FCFA')
            ->first();

        if ($taux) {
            return $this->montant * $taux->valeur_taux;
        }

        return $this->montant;
    }
}