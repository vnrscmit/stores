<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The company profile editor, porting the legacy add_company.php /
 * edit_company.php field set over the single tbl_parameters row
 * (company_settings id=41 in the port).
 *
 * Parity and deviations:
 *  - Fields verbatim: company name, address, city/pin/state/phone/std
 *    for BOTH the company block and the plant block, licence_no, tin
 *    and cst_no with the legacy maxlengths.
 *  - plantcode: the legacy screens edited it, but the live legacy
 *    schema had no such column (the edits silently vanished); the
 *    port adds the column (migration 000055) and really persists it —
 *    it feeds the QR code {plant} prefix via QrSerial.
 *  - Deviation: the logo upload is not ported — legacy copied the
 *    uploaded file to ../help/ and stored a relative path the port's
 *    front end never renders; the column is migrated as-is.
 *  - The single row is id=41 (the legacy live row and the value the
 *    QR serial contract reads); the editor has no "create" — the
 *    companyhome.php create flow only ever produced that one row.
 */
class CompanySettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route group already gates on role:admin
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'ccity' => ['nullable', 'string', 'max:100'],
            'cpin' => ['nullable', 'digits_between:1,10'],
            'cstate' => ['nullable', 'string', 'max:50'],
            'cstd' => ['nullable', 'digits_between:1,6'],
            'cphone' => ['nullable', 'digits_between:1,15'],
            'cphone1' => ['nullable', 'digits_between:1,15'],
            'plant' => ['nullable', 'string', 'max:500'],
            'plantcode' => ['nullable', 'string', 'max:20', 'not_regex:/\s/'],
            'pcity' => ['nullable', 'string', 'max:50'],
            'ppin' => ['nullable', 'digits_between:1,10'],
            'pstate' => ['nullable', 'string', 'max:50'],
            'pstd' => ['nullable', 'digits_between:1,6'],
            'pphone' => ['nullable', 'digits_between:1,15'],
            'pphone1' => ['nullable', 'digits_between:1,15'],
            'licence_no' => ['nullable', 'string', 'max:40'],
            'tin' => ['nullable', 'string', 'max:20'],
            'cst_no' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'plantcode.not_regex' => 'The plant code must be a single token without spaces (it prefixes every QR code).',
            'company_name.required' => 'Please enter the company name.',
        ];
    }
}
