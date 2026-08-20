<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VendeurMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'objet'   => 'required|string|max:200',
            'contenu' => 'required|string|max:2000',
        ];
    }
}

