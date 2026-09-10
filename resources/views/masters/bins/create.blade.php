<x-masters.form action="{{ route('masters.bins.store') }}">
    <h2>New Bin</h2>
    <p style="color:var(--muted); font-size:.85rem">Creating a bin automatically seeds sub-bins 1–20 (legacy behaviour).</p>
    <div class="filters">
        <div>
            <label>Warehouse *</label>
            <select name="whid" required>
                <option value="">--Select Warehouse--</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->whid }}" @selected(old('whid') == $warehouse->whid)>{{ $warehouse->perticulars }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Bin Name *</label>
            <input type="text" name="binname" value="{{ old('binname') }}" maxlength="100" required>
        </div>
    </div>
</x-masters.form>
