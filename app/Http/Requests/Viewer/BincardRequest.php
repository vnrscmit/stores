<?php

namespace App\Http\Requests\Viewer;

use Illuminate\Foundation\Http\FormRequest;

class BincardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level role:viewer,admin gate applies
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['nullable', 'integer'],
            'bin_id' => ['nullable', 'integer'],
            'subbin_id' => ['nullable', 'integer'],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'classification_id' => ['nullable', 'integer'],
        ];
    }

    public function warehouseId(): ?int
    {
        $v = $this->query('warehouse_id');

        return $v !== null ? (int) $v : null;
    }

    public function binId(): ?int
    {
        $v = $this->query('bin_id');

        return $v !== null ? (int) $v : null;
    }

    public function subbinId(): ?int
    {
        $v = $this->query('subbin_id');

        return $v !== null ? (int) $v : null;
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
