<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BeneficiaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nom'            => 'required|string|max:100',
            'telephone'      => 'required|string|max:20',
            'adresse'        => 'required|string|max:300',
            'quartier'       => 'required|string|max:100',
            'relation'       => 'nullable|string|max:50',
            'coordonnees_gps'=> 'nullable|array',
            'est_defaut'     => 'sometimes|boolean',
        ];
    }
}

