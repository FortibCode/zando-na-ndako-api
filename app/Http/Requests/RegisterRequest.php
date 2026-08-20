<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nom'               => 'required|string|max:100',
            'prenom'            => 'required|string|max:100',
            'email'             => 'required|email|unique:users,email',
            'telephone'         => 'required|string|unique:users,telephone',
            'mot_de_passe'      => 'required|string|min:8|confirmed',
            'type_utilisateur'  => 'required|in:client,vendeur,livreur',
            'consentement_cgu'  => 'required|accepted',
            'est_diaspora'      => 'sometimes|boolean',
            'pays_residence'    => 'sometimes|string|max:100',
        ];
    }
}

