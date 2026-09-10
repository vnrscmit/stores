<x-masters.form action="{{ route('masters.items.update', $item) }}" :isEdit="true">
    <h2>Edit Item: {{ $item->stores_item }}</h2>
    <div class="filters">
        <div>
            <label>Classification *</label>
            <select name="classification_id" required>
                <option value="">--Select Classification--</option>
                @foreach ($classifications as $classification)
                    <option value="{{ $classification->classification_id }}" @selected(old('classification_id', $item->classification_id) == $classification->classification_id)>{{ $classification->classification }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Item Name *</label>
            <input type="text" name="stores_item" value="{{ old('stores_item', $item->stores_item) }}" maxlength="100" required>
        </div>
        <div>
            <label>UoM *</label>
            <select name="uom" required>
                <option value="">---Select UoM---</option>
                @foreach ($uoms as $uom)
                    <option value="{{ $uom }}" @selected(old('uom', $item->uom) === $uom)>{{ $uom }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Serial Tracking *</label>
            <select name="srl_status" required onchange="document.getElementById('srl-wrap').style.display = this.value === 'Yes' ? 'block' : 'none'">
                @foreach (['Yes', 'No'] as $flag)
                    <option value="{{ $flag }}" @selected(old('srl_status', $item->srl_status) === $flag)>{{ $flag }}</option>
                @endforeach
            </select>
        </div>
        <div id="srl-wrap" style="display:{{ old('srl_status', $item->srl_status) === 'Yes' ? 'block' : 'none' }}">
            <label>Re-order Level</label>
            <input type="number" step="0.001" min="0" name="srl" value="{{ old('srl', $item->srl) }}">
        </div>
        <div>
            <label>Status *</label>
            <select name="actstatus" required>
                @foreach (['Active', 'Suspend'] as $state)
                    <option value="{{ $state }}" @selected(old('actstatus', $item->actstatus) === $state)>{{ $state }}</option>
                @endforeach
            </select>
        </div>
    </div>
</x-masters.form>
