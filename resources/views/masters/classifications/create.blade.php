<x-masters.form action="{{ route('masters.classifications.store') }}">
    <h2>New Classification</h2>
    <div class="filters">
        <div>
            <label>Classification Name *</label>
            <input type="text" name="classification" value="{{ old('classification') }}" maxlength="100" required>
        </div>
    </div>
</x-masters.form>
