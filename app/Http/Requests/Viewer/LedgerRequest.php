<?php

namespace App\Http\Requests\Viewer;

use Illuminate\Foundation\Http\FormRequest;

class LedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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

    /** @return array{0:string,1:string,2:?int,3:?int} */
    public function filters(): array
    {
        $to = $this->query('to', date('Y-m-d'));
        $from = $this->query('from', date('Y-01-01'));

        $classification = $this->query('classification_id');
        $item = $this->query('item_id');

        return [$from, $to, $classification ? (int) $classification : null, $item ? (int) $item : null];
    }
}
