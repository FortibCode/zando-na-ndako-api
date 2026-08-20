<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Commande extends Model
{
    use HasUuids;

    protected $table = 'commandes';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'numero_commande', 'client_id', 'vendeur_id', 'livreur_id',
        'beneficiaire_id', 'adresse_livraison_id', 'date_commande', 'statut_commande',
        'mode_attribution', 'montant_sous_total', 'frais_livraison', 'distance_estimee_km',
        'montant_total', 'creneau_livraison_debut', 'creneau_livraison_fin',
        'adresse_livraison', 'coordonnees_gps_livraison', 'type_commande',
        'devise_paiement', 'motif_annulation', 'coupon_id', 'montant_reduction',
    ];

    protected $casts = [
        'coordonnees_gps_livraison' => 'array',
        'distance_estimee_km' => 'decimal:2',
        'creneau_livraison_debut' => 'datetime',
        'creneau_livraison_fin' => 'datetime',
        'date_commande' => 'datetime',
    ];

    // Relations
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function vendeur()
    {
        return $this->belongsTo(Vendeur::class);
    }

    public function livreur()
    {
        return $this->belongsTo(Livreur::class);
    }

    public function beneficiaire()
    {
        return $this->belongsTo(Beneficiaire::class);
    }

    public function adresseLivraison()
    {
        return $this->belongsTo(AdresseLivraison::class, 'adresse_livraison_id');
    }

    public function lignes()
    {
        return $this->hasMany(LigneCommande::class);
    }

    public function paiement()
    {
        return $this->hasOne(Paiement::class);
    }

    public function livraison()
    {
        return $this->hasOne(Livraison::class);
    }

    public function commission()
    {
        return $this->hasOne(Commission::class);
    }

    public function notations()
    {
        return $this->hasMany(NotationAvis::class);
    }

    public function litige()
    {
        return $this->hasOne(Litige::class);
    }

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    // Méthodes
    public function getStatutLabelAttribute()
    {
        return [
            'confirmee' => 'Confirmée',
            'achat_marche' => 'Achat au marché',
            'preparation' => 'Préparation',
            'en_route' => 'En route',
            'livree' => 'Livrée',
            'annulee' => 'Annulée',
        ][$this->statut_commande] ?? $this->statut_commande;
    }

    public function isDiaspora()
    {
        return $this->type_commande === 'diaspora';
    }

    public function isAnnulee()
    {
        return $this->statut_commande === 'annulee';
    }

    public function isLivree()
    {
        return $this->statut_commande === 'livree';
    }

    public function peutEtreAnnulee()
    {
        return !in_array($this->statut_commande, ['livree', 'annulee']);
    }

    public function getMontantTotalEnFranc()
    {
        if ($this->devise_paiement === 'FCFA') {
            return $this->montant_total;
        }

        // Convertir en FCFA via le taux de change
        $taux = TauxChange::where('devise_source', $this->devise_paiement)
            ->where('devise_cible', 'FCFA')
            ->first();

        if ($taux) {
            return $this->montant_total * $taux->valeur_taux;
        }

        return $this->montant_total;
    }
}