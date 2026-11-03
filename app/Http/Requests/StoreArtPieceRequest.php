<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreArtPieceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Price arrives in dollars (e.g. "49.99"); the controller converts it to cents.
     * Blank image fields arrive as null (ConvertEmptyStringsToNull) and are skipped.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'decimal:0,2', 'min:0.01', 'max:1000000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'images' => ['nullable', 'array', 'max:20'],
            'images.*' => ['nullable', 'url:https', 'max:2048'],
        ];
    }
}
