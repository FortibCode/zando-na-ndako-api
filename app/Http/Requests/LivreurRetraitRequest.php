<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LivreurRetraitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'montant'          => 'required|numeric|min:1000',
            'methode_retrait'  => 'required|in:mtn_momo,airtel_money',
            'numero_reception' => 'required|string',
        ];
    }
}

