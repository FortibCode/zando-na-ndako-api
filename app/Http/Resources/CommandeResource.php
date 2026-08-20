<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommandeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'numero_commande'         => $this->numero_commande,
            'date_commande'           => $this->date_commande?->toIso8601String(),
            'statut_commande'         => $this->statut_commande,
            'statut_label'            => $this->statut_label,
            'montant_sous_total'      => (float) $this->montant_sous_total,
            'frais_livraison'         => (float) $this->frais_livraison,
            'montant_total'           => (float) $this->montant_total,
            'devise_paiement'         => $this->devise_paiement,
            'adresse_livraison'       => $this->adresse_livraison,
            'creneau_livraison_debut' => $this->creneau_livraison_debut?->toIso8601String(),
            'creneau_livraison_fin'   => $this->creneau_livraison_fin?->toIso8601String(),
            'type_commande'           => $this->type_commande,
            'motif_annulation'        => $this->motif_annulation,
            'beneficiaire'            => new BeneficiaireResource($this->whenLoaded('beneficiaire')),
            'lignes'                  => LigneCommandeResource::collection($this->whenLoaded('lignes')),
        ];
    }
}

