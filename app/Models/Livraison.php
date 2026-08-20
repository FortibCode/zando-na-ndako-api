<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Livraison extends Model
{
    use HasUuids;

    protected $table = 'livraisons';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

protected $fillable = [
        'commande_id', 'livreur_id', 'statut_livraison', 'date_collecte',
        'date_depart_client', 'date_livraison', 'photo_preuve', 'distance_parcourue',
        'duree_reelle', 'motif_incident'
    ];

    protected $casts = [
        'date_collecte' => 'datetime',
        'date_depart_client' => 'datetime',
        'date_livraison' => 'datetime',
        'distance_parcourue' => 'decimal:2',
    ];

    // Relations
    public function commande()
    {
        return $this->belongsTo(Commande::class);
    }

    public function livreur()
    {
        return $this->belongsTo(Livreur::class);
    }

    public function suiviPositions()
    {
        return $this->hasMany(SuiviPositionLivraison::class);
    }

    // Méthodes
    public function getDernierePositionAttribute()
    {
        return $this->suiviPositions()->latest('horodatage')->first();
    }

public function getStatutLabelAttribute()
    {
        return [
            'assigne' => 'Assignée',
            'en_cours' => 'En cours',
            'en_route_client' => 'En route vers le client',
            'livree' => 'Livrée',
            'echouee' => 'Échouée',
        ][$this->statut_livraison] ?? $this->statut_livraison;
    }

    public function getDureeTotaleAttribute()
    {
        if ($this->date_collecte && $this->date_livraison) {
            return $this->date_collecte->diffInMinutes($this->date_livraison);
        }
        return null;
    }

    // Règlement d'une livraison : clôt la mission, valide le paiement à la livraison le cas
    // échéant, et crédite livreur + vendeur (moins commission). Point d'entrée unique appelé à
    // la fois par le livreur (confirmerLivraison) et par l'admin (modifierStatutCommande) pour
    // éviter que cette logique ne diverge entre les deux chemins.
    public function marquerLivree(?string $photoPreuve = null): void
    {
        DB::transaction(function () use ($photoPreuve) {
            $c = $this->commande;
            $this->update([
                'statut_livraison' => 'livree',
                'date_livraison' => now(),
                'photo_preuve' => $photoPreuve ?? $this->photo_preuve,
                'distance_parcourue' => $c->distance_estimee_km,
            ]);
            $c->update(['statut_commande' => 'livree']);
            if ($c->paiement && $c->paiement->methode === 'paiement_livraison') {
                $c->paiement->update(['statut' => 'valide', 'date_paiement' => now()]);
            }
            $this->livreur->increment('solde_disponible', (float) $c->frais_livraison);
            $taux = (float) ParametrePlateforme::valeur('taux_commission_vendeur', '10') / 100;
            $commission = $c->montant_sous_total * $taux;
            $c->vendeur->increment('solde_disponible', $c->montant_sous_total - $commission);
        });
    }
}