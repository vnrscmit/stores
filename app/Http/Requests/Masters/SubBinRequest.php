<?php

namespace App\Http\Requests\Masters;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubBinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-masters');
    }

    public function rules(): array
    {
        // Legacy add_subbin.php checked sname globally (a legacy bug that made
        // reuse of numbers 1..20 across bins impossible); the port scopes the
        // uniqueness to the bin, which the legacy sub-bin data proves was the
        // intent (bins already share numbers 1..20 across warehouses).
        return [
            'sname' => [
                'required', 'integer', 'min:1',
                Rule::unique('sub_bins', 'sname')
                    ->where('binid', (int) $this->input('binid'))
                    ->ignore($this->route('subbin'), 'sid'),
            ],
            'binid' => ['required', 'integer', 'exists:bins,binid'],
            'status' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return ['sname.unique' => 'This Sub-Bin Number is Already Present.'];
    }
}
