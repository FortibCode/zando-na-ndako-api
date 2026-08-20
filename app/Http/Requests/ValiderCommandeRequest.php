<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ValiderCommandeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'adresse_livraison'       => 'required|string|max:500',
            'zone_id'                 => 'required|uuid|exists:zones_livraison,id',
            'creneau_livraison_debut' => 'required|date|after:now',
            'creneau_livraison_fin'   => 'required|date|after:creneau_livraison_debut',
            'type_commande'           => 'sometimes|in:local,diaspora',
            'beneficiaire_id'         => 'nullable|uuid|exists:beneficiaires,id',
            'coordonnees_gps'         => 'nullable|array',
        ];
    }
}

