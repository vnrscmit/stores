<?php

namespace App\Http\Requests\Masters;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-masters');
    }

    public function rules(): array
    {
        // Legacy add_warehouse.php: exact-duplicate check on perticulars.
        return [
            'perticulars' => [
                'required', 'string', 'max:100',
                Rule::unique('warehouses', 'perticulars')
                    ->ignore($this->route('warehouse'), 'whid'),
            ],
        ];
    }

    public function messages(): array
    {
        return ['perticulars.unique' => 'This warehouse is Already Present.'];
    }
}
