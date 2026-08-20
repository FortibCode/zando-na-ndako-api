<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminCommandeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'livreur_id' => 'sometimes|uuid|exists:livreurs,id',
            'statut'     => 'sometimes|in:confirmee,achat_marche,preparation,en_route,livree,annulee',
        ];
    }
}

