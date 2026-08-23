<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Produit extends Model
{
    use HasUuids;

    protected $table = 'produits';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'vendeur_id', 'categorie_id', 'nom_produit', 'description',
        'prix_unitaire', 'unite_mesure', 'quantite_stock',
        'statut_disponibilite', 'photo_produit', 'type_fraicheur',
        'date_maj_prix'
    ];

    protected $casts = [
        'date_maj_prix' => 'datetime',
        'prix_unitaire' => 'decimal:2',
    ];

    // Relations
    public function vendeur()
    {
        return $this->belongsTo(Vendeur::class);
    }

    public function categorie()
    {
        return $this->belongsTo(Categorie::class);
    }

    public function lignesPanier()
    {
        return $this->hasMany(LignePanier::class);
    }

    public function lignesCommande()
    {
        return $this->hasMany(LigneCommande::class);
    }

    public function promotions()
    {
        return $this->hasMany(PromotionProduit::class);
    }

    // Promotions vendeur (self-service) éventuellement rattachées à ce produit précis.
    public function promotionsVendeur()
    {
        return $this->hasMany(PromotionVendeur::class);
    }

    // Méthodes
    public function estDisponible()
    {
        return $this->statut_disponibilite === 'disponible' && $this->quantite_stock > 0;
    }

    public function getPrixAfficheAttribute()
    {
        return number_format($this->prix_unitaire, 0, ',', ' ') . ' FCFA';
    }

    // La promotion vendeur active la plus pertinente pour ce produit : priorité au ciblage
    // produit précis, sinon la promotion "toute la boutique" (produit_id NULL) du vendeur.
    public function promotionVendeurActive(): ?PromotionVendeur
    {
        $base = PromotionVendeur::where('vendeur_id', $this->vendeur_id)->active();
        return (clone $base)->where('produit_id', $this->id)->first()
            ?? (clone $base)->whereNull('produit_id')->first();
    }

    public function getPrixAvecPromotionAttribute()
    {
        $promo = $this->promotions()
            ->whereHas('promotion', function($query) {
                $query->where('statut_actif', true)
                    ->where('date_debut', '<=', now())
                    ->where('date_fin', '>=', now());
            })
            ->first();

        if ($promo) {
            return $promo->prix_promo;
        }

        // Pas de bannière admin sur ce produit : on retombe sur une éventuelle promotion créée
        // par le vendeur lui-même (voir PromotionVendeur) — sans quoi une promotion "vendeur"
        // resterait purement cosmétique et le client paierait le plein tarif malgré le badge de
        // réduction affiché.
        if ($promoVendeur = $this->promotionVendeurActive()) {
            $prix = (float) $this->prix_unitaire;
            $valeur = (float) $promoVendeur->valeur_reduction;
            $reduit = $promoVendeur->type_reduction === 'montant_fixe'
                ? $prix - $valeur
                : $prix * (1 - $valeur / 100);
            return round(max(0, $reduit), 2);
        }

        return $this->prix_unitaire;
    }

    public function getEstEnPromotionAttribute()
    {
        if ($this->promotions()
            ->whereHas('promotion', function($query) {
                $query->where('statut_actif', true)
                    ->where('date_debut', '<=', now())
                    ->where('date_fin', '>=', now());
            })
            ->exists()) {
            return true;
        }

        return $this->promotionVendeurActive() !== null;
    }
}