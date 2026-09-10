<x-masters.form action="{{ route('masters.warehouses.store') }}">
    <h2>New Warehouse</h2>
    <div class="filters">
        <div>
            <label>Warehouse Name *</label>
            <input type="text" name="perticulars" value="{{ old('perticulars') }}" maxlength="100" required>
        </div>
    </div>
</x-masters.form>
