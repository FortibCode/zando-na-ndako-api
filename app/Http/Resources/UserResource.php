<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'nom'              => $this->nom,
            'prenom'           => $this->prenom,
            'nom_complet'      => $this->nom_complet,
            'email'            => $this->email,
            'telephone'        => $this->telephone,
            'type_utilisateur' => $this->type_utilisateur,
            'statut_compte'    => $this->statut_compte,
            'photo_profil'     => $this->photo_profil,
            'langue_preferee'  => $this->langue_preferee,
            'devise_preferee'  => $this->devise_preferee,
            'pays_residence'   => $this->pays_residence,
            'date_inscription' => $this->date_inscription?->toIso8601String(),
        ];
    }
}

