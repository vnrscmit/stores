<?php

namespace App\Http\Requests\Viewer;

use Illuminate\Foundation\Http\FormRequest;

class StockOnHandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level role:viewer,admin gate applies
    }

    public function rules(): array
    {
        return [
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'classification_id' => ['nullable', 'integer'],
        ];
    }

    public function asOf(): string
    {
        return $this->query('as_of', date('Y-m-d'));
    }

    public function classificationId(): ?int
    {
        $v = $this->query('classification_id');

        return $v ? (int) $v : null;
    }
}
