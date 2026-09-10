<x-masters.layout title="Items" createRoute="masters.items.create" createLabel="New Item" exportRoute="masters.items.export">
    <x-slot:filters>
        <div><label>Search item</label><input type="text" name="q" value="{{ $q }}"></div>
        <div>
            <label>Classification</label>
            <select name="classification_id">
                <option value="">All</option>
                @foreach ($classifications as $classification)
                    <option value="{{ $classification->classification_id }}" @selected((string) $classificationId === (string) $classification->classification_id)>{{ $classification->classification }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Status</label>
            <select name="actstatus">
                <option value="">All</option>
                @foreach (['Active', 'Suspend'] as $state)
                    <option value="{{ $state }}" @selected($status === $state)>{{ $state }}</option>
                @endforeach
            </select>
        </div>
    </x-slot:filters>

    <table class="data">
        <thead>
        <tr>
            <th>ID</th><th>Item</th><th>Classification</th><th>UoM</th><th>Serial</th><th>Reorder</th><th>Status</th><th style="width:14rem">Actions</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($items as $item)
            <tr>
                <td>{{ $item->items_id }}</td>
                <td>{{ $item->stores_item }}</td>
                <td>{{ $item->classification?->classification }}</td>
                <td>{{ $item->uom }}</td>
                <td>{{ $item->srl_status }}</td>
                <td>{{ $item->srl }}</td>
                <td>{{ $item->actstatus }}</td>
                <td>
                    <a class="btn secondary" href="{{ route('masters.items.edit', $item) }}">Edit</a>
                    <form method="POST" action="{{ route('masters.items.destroy', $item) }}" style="display:inline"
                          onsubmit="return confirm('Delete this item? (Legacy parity: hard delete.)')">
                        @csrf @method('DELETE')
                        <button class="btn secondary" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="8" style="color:var(--muted)">No items found.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $items->links() }}
</x-masters.layout>
