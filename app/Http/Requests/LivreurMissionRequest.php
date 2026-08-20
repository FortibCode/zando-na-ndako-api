<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LivreurMissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'commande_id' => 'required|uuid|exists:commandes,id',
        ];
    }
}

