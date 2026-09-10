<x-masters.form action="{{ route('masters.subbins.update', $subBin) }}" :isEdit="true">
    <h2>Edit Sub-bin #{{ $subBin->sname }}</h2>
    <div class="filters">
        <div>
            <label>Bin *</label>
            <select name="binid" required>
                <option value="">--Select Bin--</option>
                @foreach ($bins as $bin)
                    <option value="{{ $bin->binid }}" @selected(old('binid', $subBin->binid) == $bin->binid)>
                        {{ $bin->binname }} — {{ $bin->warehouse?->perticulars }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Sub-bin Number *</label>
            <input type="number" name="sname" value="{{ old('sname', $subBin->sname) }}" min="1" required>
        </div>
        <div>
            <label>Status</label>
            <input type="text" name="status" value="{{ old('status', $subBin->status) }}" maxlength="50">
        </div>
    </div>
</x-masters.form>
