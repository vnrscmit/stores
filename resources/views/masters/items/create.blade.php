<x-masters.form action="{{ route('masters.items.store') }}">
    <h2>New Item</h2>
    <div class="filters">
        <div>
            <label>Classification *</label>
            <select name="classification_id" required>
                <option value="">--Select Classification--</option>
                @foreach ($classifications as $classification)
                    <option value="{{ $classification->classification_id }}" @selected(old('classification_id') == $classification->classification_id)>{{ $classification->classification }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Item Name *</label>
            <input type="text" name="stores_item" value="{{ old('stores_item') }}" maxlength="100" required>
        </div>
        <div>
            <label>UoM *</label>
            <select name="uom" required>
                <option value="">---Select UoM---</option>
                @foreach ($uoms as $uom)
                    <option value="{{ $uom }}" @selected(old('uom') === $uom)>{{ $uom }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Serial Tracking *</label>
            <select name="srl_status" required onchange="document.getElementById('srl-wrap').style.display = this.value === 'Yes' ? 'block' : 'none'">
                <option value="">--</option>
                @foreach (['Yes', 'No'] as $flag)
                    <option value="{{ $flag }}" @selected(old('srl_status') === $flag)>{{ $flag }}</option>
                @endforeach
            </select>
        </div>
        <div id="srl-wrap" style="display:{{ old('srl_status') === 'Yes' ? 'block' : 'none' }}">
            <label>Re-order Level</label>
            <input type="number" step="0.001" min="0" name="srl" value="{{ old('srl') }}">
        </div>
        <div>
            <label>Status *</label>
            <select name="actstatus" required>
                @foreach (['Active', 'Suspend'] as $state)
                    <option value="{{ $state }}" @selected(old('actstatus', 'Active') === $state)>{{ $state }}</option>
                @endforeach
            </select>
        </div>
    </div>
</x-masters.form>
