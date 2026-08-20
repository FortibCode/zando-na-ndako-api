<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminCategorieRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nom_categorie'       => 'required|string|max:100',
            'description'         => 'nullable|string',
            'categorie_parent_id' => 'nullable|uuid|exists:categories,id',
        ];
    }
}

