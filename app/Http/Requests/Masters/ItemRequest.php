<?php

namespace App\Http\Requests\Masters;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-masters');
    }

    public function rules(): array
    {
        // Legacy add_stores.php: stores_item unique; UoM from a fixed list;
        // serial tracking flag Yes/No; optional reorder level number.
        return [
            'classification_id' => ['required', 'integer', 'exists:classifications,classification_id'],
            'stores_item' => [
                'required', 'string', 'max:100',
                Rule::unique('items', 'stores_item')
                    ->ignore($this->route('item'), 'items_id'),
            ],
            'uom' => ['required', 'string', 'max:100', Rule::in(['Number', 'Kg', 'Meters', 'Litres', 'Mililitres'])],
            'srl_status' => ['required', Rule::in(['Yes', 'No'])],
            'srl' => ['nullable', 'numeric', 'min:0', 'required_if:srl_status,Yes'],
            'actstatus' => ['required', Rule::in(['Active', 'Suspend'])],
        ];
    }

    public function messages(): array
    {
        return [
            'stores_item.unique' => 'Duplicate not allowed.',
            'srl.required_if' => 'The re-order level is required when serial tracking is Yes.',
        ];
    }
}
