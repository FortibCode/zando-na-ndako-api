<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VendeurStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantite'  => 'required|integer|min:0',
            'operation' => 'required|in:ajouter,definir',
        ];
    }
}

