<?php

namespace App\Http\Requests\Masters;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-masters');
    }

    public function rules(): array
    {
        // Legacy add_party_master.php: business_name unique; category from
        // the legacy dropdown; India parties must carry a state (legacy JS).
        return [
            'classification' => ['required', 'string', 'max:100', Rule::in(['Vendor', 'C&F', 'Dealers', 'Stock Transfer', 'Internal Return'])],
            'business_name' => [
                'required', 'string', 'max:255',
                Rule::unique('parties', 'business_name')
                    ->ignore($this->route('party'), 'p_id'),
            ],
            'contact' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100', 'required_if:country,India'],
            'country' => ['required', 'string', 'max:200'],
            'pin' => ['nullable', 'integer', 'digits_between:5,6'],
            'mob' => ['nullable', 'integer', 'digits_between:10,12'],
            'std' => ['nullable', 'integer', 'max:99'],
            'phone' => ['nullable', 'string', 'max:20'],
            'tin' => ['nullable', 'string', 'max:100'],
            'cst' => ['nullable', 'string', 'max:100'],
            'pan' => ['nullable', 'string', 'max:100'],
            'product' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'business_name.unique' => 'Duplicate Party Name. ID already Present.',
            'state.required_if' => 'State is required for India.',
        ];
    }
}
