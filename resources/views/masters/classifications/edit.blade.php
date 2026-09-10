<x-masters.form action="{{ route('masters.classifications.update', $classification) }}" :isEdit="true">
    <h2>Edit Classification: {{ $classification->classification }}</h2>
    <div class="filters">
        <div>
            <label>Classification Name *</label>
            <input type="text" name="classification" value="{{ old('classification', $classification->classification) }}" maxlength="100" required>
        </div>
    </div>
</x-masters.form>
