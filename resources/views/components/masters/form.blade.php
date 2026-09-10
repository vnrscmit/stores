@props(['action', 'isEdit' => false])

<form method="POST" action="{{ $action }}" class="card">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif
    {{ $slot }}
    <div style="margin-top:1rem; display:flex; gap:.75rem">
        <button class="btn" type="submit">{{ $isEdit ? 'Update' : 'Create' }}</button>
        <a class="btn secondary" href="{{ url()->previous() }}">Cancel</a>
    </div>
</form>
