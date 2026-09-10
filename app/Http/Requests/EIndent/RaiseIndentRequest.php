<?php

namespace App\Http\Requests\EIndent;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Indent remarks (legacy add_indents.php: maxlength 90, "&" was stored
 * as-is in legacy; the port keeps it verbatim).
 */
class RaiseIndentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('raise-indents');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:90'],
        ];
    }
}
