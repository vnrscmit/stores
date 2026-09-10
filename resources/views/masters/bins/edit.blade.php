<x-masters.form action="{{ route('masters.bins.update', $bin) }}" :isEdit="true">
    <h2>Edit Bin: {{ $bin->binname }}</h2>
    <div class="filters">
        <div>
            <label>Warehouse *</label>
            <select name="whid" required>
                <option value="">--Select Warehouse--</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->whid }}" @selected(old('whid', $bin->whid) == $warehouse->whid)>{{ $warehouse->perticulars }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Bin Name *</label>
            <input type="text" name="binname" value="{{ old('binname', $bin->binname) }}" maxlength="100" required>
        </div>
    </div>
</x-masters.form>
