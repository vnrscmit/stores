<x-masters.form action="{{ route('masters.subbins.store') }}">
    <h2>New Sub-bin</h2>
    <div class="filters">
        <div>
            <label>Bin *</label>
            <select name="binid" required>
                <option value="">--Select Bin--</option>
                @foreach ($bins as $bin)
                    <option value="{{ $bin->binid }}" @selected(old('binid') == $bin->binid)>
                        {{ $bin->binname }} — {{ $bin->warehouse?->perticulars }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Sub-bin Number *</label>
            <input type="number" name="sname" value="{{ old('sname') }}" min="1" required>
        </div>
        <div>
            <label>Status</label>
            <input type="text" name="status" value="{{ old('status', 'Empty') }}" maxlength="50">
        </div>
    </div>
</x-masters.form>
