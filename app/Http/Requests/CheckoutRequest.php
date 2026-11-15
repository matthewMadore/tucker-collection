<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guest checkout. Deliberately has no amount field: the price is always
 * read from the art piece in CheckoutService.
 */
class CheckoutRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'shipping_address' => ['required', 'array'],
            'shipping_address.name' => ['required', 'string', 'max:255'],
            'shipping_address.line1' => ['required', 'string', 'max:255'],
            'shipping_address.line2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['required', 'string', 'max:255'],
            'shipping_address.state' => ['required', 'string', 'max:255'],
            'shipping_address.postal_code' => ['required', 'string', 'max:20'],
            'shipping_address.country' => ['required', 'string', 'size:2'],
        ];
    }

    /**
     * Readable names for error messages ("The city field is required."
     * instead of "The shipping address.city field is required.").
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'shipping_address.name' => 'name',
            'shipping_address.line1' => 'address',
            'shipping_address.line2' => 'address line 2',
            'shipping_address.city' => 'city',
            'shipping_address.state' => 'state',
            'shipping_address.postal_code' => 'ZIP / postal code',
            'shipping_address.country' => 'country',
        ];
    }
}
