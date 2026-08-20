<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProduitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'nom_produit'          => $this->nom_produit,
            'description'          => $this->description,
            'prix_unitaire'        => (float) $this->prix_unitaire,
            'prix_affiche'         => $this->prix_affiche,
            'unite_mesure'         => $this->unite_mesure,
            'quantite_stock'       => $this->quantite_stock,
            'statut_disponibilite' => $this->statut_disponibilite,
            'type_fraicheur'       => $this->type_fraicheur,
            'photo_produit'        => $this->photo_produit,
            'est_disponible'       => $this->estDisponible(),
            'est_en_promotion'     => $this->est_en_promotion,
            'prix_avec_promotion'  => (float) $this->prix_avec_promotion,
            'categorie'            => new CategorieResource($this->whenLoaded('categorie')),
        ];
    }
}

