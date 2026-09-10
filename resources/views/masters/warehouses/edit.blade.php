<x-masters.form action="{{ route('masters.warehouses.update', $warehouse) }}" :isEdit="true">
    <h2>Edit Warehouse: {{ $warehouse->perticulars }}</h2>
    <div class="filters">
        <div>
            <label>Warehouse Name *</label>
            <input type="text" name="perticulars" value="{{ old('perticulars', $warehouse->perticulars) }}" maxlength="100" required>
        </div>
    </div>
</x-masters.form>
