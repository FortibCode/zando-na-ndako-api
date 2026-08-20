<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'statut_compte' => 'sometimes|in:actif,suspendu,en_attente_validation',
            'motif'         => 'sometimes|string|max:500',
        ];
    }
}

