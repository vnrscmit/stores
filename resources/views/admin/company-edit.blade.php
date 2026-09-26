<x-masters.form action="{{ route('admin.company.update') }}" :isEdit="true">
    <h2>Company profile</h2>
    <p class="muted">
        The single tbl_parameters row (id=41). The plant code prefixes
        every QR code; the licence and tax numbers print on documents.
        Legacy add_company / edit_company vocabulary.
    </p>

    <div class="filters">
        <div>
            <label>Company Name *</label>
            <input type="text" name="company_name" value="{{ old('company_name', $company->company_name) }}" maxlength="40" required>
        </div>
        <div>
            <label>Address</label>
            <textarea name="address" rows="3" maxlength="500">{{ old('address', $company->address) }}</textarea>
        </div>
        <div>
            <label>City</label>
            <input type="text" name="ccity" value="{{ old('ccity', $company->ccity) }}" maxlength="100">
        </div>
        <div>
            <label>Pin Code</label>
            <input type="text" name="cpin" value="{{ old('cpin', $company->cpin) }}" maxlength="10">
        </div>
        <div>
            <label>State</label>
            <input type="text" name="cstate" value="{{ old('cstate', $company->cstate) }}" maxlength="50">
        </div>
        <div>
            <label>STD Code</label>
            <input type="text" name="cstd" value="{{ old('cstd', $company->cstd) }}" maxlength="6">
        </div>
        <div>
            <label>Phone Number</label>
            <input type="text" name="cphone" value="{{ old('cphone', $company->cphone) }}" maxlength="15">
        </div>
        <div>
            <label>Phone Number 1</label>
            <input type="text" name="cphone1" value="{{ old('cphone1', $company->cphone1) }}" maxlength="15">
        </div>
    </div>

    <h3>Plant</h3>
    <div class="filters">
        <div>
            <label>Plant (address block)</label>
            <textarea name="plant" rows="3" maxlength="500">{{ old('plant', $company->plant) }}</textarea>
        </div>
        <div>
            <label>Plant Code (QR prefix)</label>
            <input type="text" name="plantcode" value="{{ old('plantcode', $company->plantcode) }}" maxlength="20">
            <p class="muted">Single token, no spaces — prefixes every QR code (e.g. DEF).</p>
        </div>
        <div>
            <label>City</label>
            <input type="text" name="pcity" value="{{ old('pcity', $company->pcity) }}" maxlength="50">
        </div>
        <div>
            <label>Pin Code</label>
            <input type="text" name="ppin" value="{{ old('ppin', $company->ppin) }}" maxlength="10">
        </div>
        <div>
            <label>State</label>
            <input type="text" name="pstate" value="{{ old('pstate', $company->pstate) }}" maxlength="50">
        </div>
        <div>
            <label>STD Code</label>
            <input type="text" name="pstd" value="{{ old('pstd', $company->pstd) }}" maxlength="6">
        </div>
        <div>
            <label>Phone Number</label>
            <input type="text" name="pphone" value="{{ old('pphone', $company->pphone) }}" maxlength="15">
        </div>
        <div>
            <label>Phone Number 1</label>
            <input type="text" name="pphone1" value="{{ old('pphone1', $company->pphone1) }}" maxlength="15">
        </div>
    </div>

    <h3>Licence &amp; tax</h3>
    <div class="filters">
        <div>
            <label>Licence No</label>
            <input type="text" name="licence_no" value="{{ old('licence_no', $company->licence_no) }}" maxlength="40">
        </div>
        <div>
            <label>TIN</label>
            <input type="text" name="tin" value="{{ old('tin', $company->tin) }}" maxlength="20">
        </div>
        <div>
            <label>CST No</label>
            <input type="text" name="cst_no" value="{{ old('cst_no', $company->cst_no) }}" maxlength="20">
        </div>
    </div>
</x-masters.form>
