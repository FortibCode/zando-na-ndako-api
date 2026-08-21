<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VendeurProduitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nom_produit'          => 'required|string|max:200',
            'description'          => 'nullable|string',
            'categorie_id'         => 'required|uuid|exists:categories,id',
            'prix_unitaire'        => 'required|numeric|min:0',
            'unite_mesure'         => 'required|string|max:50',
            'quantite_stock'       => 'required|integer|min:0',
            'type_fraicheur'       => 'sometimes|in:frais,fume,congele',
            'photo'                => 'nullable|image|mimes:jpeg,png,jpg,webp|max:3072',
        ];
    }
}

