<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AjouterAuPanierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'produit_id' => 'required|uuid|exists:produits,id',
            'quantite'   => 'required|integer|min:1|max:100',
        ];
    }
}

