<?php

namespace App\Http\Requests\Masters;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClassificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-masters');
    }

    public function rules(): array
    {
        return [
            'classification' => [
                'required', 'string', 'max:100',
                Rule::unique('classifications', 'classification')
                    ->ignore($this->route('classification'), 'classification_id'),
            ],
        ];
    }

    public function messages(): array
    {
        return ['classification.unique' => 'This classification is Already Present.'];
    }
}
