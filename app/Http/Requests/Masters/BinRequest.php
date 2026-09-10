<?php

namespace App\Http\Requests\Masters;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-masters');
    }

    public function rules(): array
    {
        // Legacy add_bin.php: binname unique within the warehouse.
        return [
            'binname' => [
                'required', 'string', 'max:100',
                Rule::unique('bins', 'binname')
                    ->where('whid', (int) $this->input('whid'))
                    ->ignore($this->route('bin'), 'binid'),
            ],
            'whid' => ['required', 'integer', 'exists:warehouses,whid'],
        ];
    }

    public function messages(): array
    {
        return ['binname.unique' => 'This bin is Already Present.'];
    }
}
