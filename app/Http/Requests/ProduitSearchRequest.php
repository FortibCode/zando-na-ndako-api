<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProduitSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search'    => 'sometimes|string|max:100',
            'categorie' => 'sometimes|uuid|exists:categories,id',
            'prix_min'  => 'sometimes|numeric|min:0',
            'prix_max'  => 'sometimes|numeric|gte:prix_min',
        ];
    }
}

