<x-masters.layout title="Classifications" createRoute="masters.classifications.create" createLabel="New Classification">
    <x-slot:filters>
        <div><label>Search</label><input type="text" name="q" value="{{ $q }}"></div>
    </x-slot:filters>

    <table class="data">
        <thead>
        <tr>
            <th>ID</th><th>Classification</th><th>Items</th><th style="width:14rem">Actions</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($classifications as $classification)
            <tr>
                <td>{{ $classification->classification_id }}</td>
                <td>{{ $classification->classification }}</td>
                <td>{{ $classification->items_count }}</td>
                <td>
                    <a class="btn secondary" href="{{ route('masters.classifications.edit', $classification) }}">Edit</a>
                    <form method="POST" action="{{ route('masters.classifications.destroy', $classification) }}" style="display:inline"
                          onsubmit="return confirm('Delete this classification? (Legacy parity: hard delete.)')">
                        @csrf @method('DELETE')
                        <button class="btn secondary" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" style="color:var(--muted)">No classifications found.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $classifications->links() }}
</x-masters.layout>
