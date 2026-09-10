<x-masters.layout title="Warehouses" createRoute="masters.warehouses.create" createLabel="New Warehouse">
    <x-slot:filters>
        <div><label>Search</label><input type="text" name="q" value="{{ $q }}"></div>
    </x-slot:filters>

    <table class="data">
        <thead>
        <tr>
            <th>ID</th><th>Warehouse</th><th>Bins</th><th style="width:14rem">Actions</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($warehouses as $warehouse)
            <tr>
                <td>{{ $warehouse->whid }}</td>
                <td>{{ $warehouse->perticulars }}</td>
                <td>{{ $warehouse->bins_count }}</td>
                <td>
                    <a class="btn secondary" href="{{ route('masters.warehouses.edit', $warehouse) }}">Edit</a>
                    <form method="POST" action="{{ route('masters.warehouses.destroy', $warehouse) }}" style="display:inline"
                          onsubmit="return confirm('Delete this warehouse? It is hard-deleted (legacy parity).')">
                        @csrf @method('DELETE')
                        <button class="btn secondary" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" style="color:var(--muted)">No warehouses found.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $warehouses->links() }}
</x-masters.layout>
