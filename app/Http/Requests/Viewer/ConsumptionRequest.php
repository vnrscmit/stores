<?php

namespace App\Http\Requests\Viewer;

use Illuminate\Foundation\Http\FormRequest;

class ConsumptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level role:viewer,admin gate applies
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'classification_id' => ['nullable', 'integer'],
            'item_id' => ['nullable', 'integer'],
        ];
    }

    public function filters(): array
    {
        return [
            $this->from(),
            $this->to(),
            $this->classificationId(),
            $this->itemId(),
        ];
    }

    public function from(): string
    {
        $v = $this->query('from');

        return $v !== null ? (string) $v : date('Y-m-d', strtotime('first day of last month'));
    }

    public function to(): string
    {
        $v = $this->query('to');

        return $v !== null ? (string) $v : date('Y-m-d');
    }

    public function classificationId(): ?int
    {
        $v = $this->query('classification_id');

        return $v ? (int) $v : null;
    }

    public function itemId(): ?int
    {
        $v = $this->query('item_id');

        return $v ? (int) $v : null;
    }
}
