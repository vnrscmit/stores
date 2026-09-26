<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompanySettingRequest;
use App\Models\CompanySetting;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Company profile — the port of the legacy Masters "Parameter" screens
 * (add_company.php / edit_company.php) over the single tbl_parameters
 * row, company_settings id=41 in the port. The same row feeds the QR
 * serial {plant} prefix via QrSerial::plantCode(), so the editor is
 * the real owner of the plant code (legacy's plantcode edits never
 * persisted — the live schema lacked the column; migration 000055).
 *
 * Deviation: the logo upload is not ported (legacy copied the file to
 * ../help/ and stored a path the port never renders); the migrated
 * logo column is left untouched.
 */
class CompanySettingController extends Controller
{
    public function edit(): View
    {
        return view('admin.company-edit', [
            'company' => $this->company(),
        ]);
    }

    public function update(CompanySettingRequest $request): RedirectResponse
    {
        $company = $this->company();

        $data = $request->validated();

        $company->fill($data)->save();

        Audit::changed('admin.company', 'update', $company, [
            'company_name', 'address', 'plantcode', 'licence_no', 'tin', 'cst_no',
        ]);

        return redirect()
            ->route('admin.company.edit')
            ->with('status', 'Company profile updated.');
    }

    /** The single profile row, id=41 (the legacy live row). */
    private function company(): CompanySetting
    {
        $company = CompanySetting::query()->where('id', 41)->first();

        if ($company === null) {
            $company = new CompanySetting;
            $company->id = 41;
            $company->save();
        }

        return $company;
    }
}
