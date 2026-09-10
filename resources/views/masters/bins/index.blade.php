<x-masters.layout title="Bins" createRoute="masters.bins.create" createLabel="New Bin">
    <x-slot:filters>
        <div>
            <label>Warehouse</label>
            <select name="whid">
                <option value="">All warehouses</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->whid }}" @selected((string) $whid === (string) $warehouse->whid)>{{ $warehouse->perticulars }}</option>
                @endforeach
            </select>
        </div>
        <div><label>Search bin name</label><input type="text" name="q" value="{{ $q }}"></div>
    </x-slot:filters>

    <table class="data">
        <thead>
        <tr>
            <th>ID</th><th>Bin</th><th>Warehouse</th><th>Sub-bins</th><th style="width:14rem">Actions</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($bins as $bin)
            <tr>
                <td>{{ $bin->binid }}</td>
                <td>{{ $bin->binname }}</td>
                <td>{{ $bin->warehouse?->perticulars }}</td>
                <td>{{ $bin->sub_bins_count }}</td>
                <td>
                    <a class="btn secondary" href="{{ route('masters.subbins.index', ['binid' => $bin->binid]) }}">Sub-bins</a>
                    <a class="btn secondary" href="{{ route('masters.bins.edit', $bin) }}">Edit</a>
                    <form method="POST" action="{{ route('masters.bins.destroy', $bin) }}" style="display:inline"
                          onsubmit="return confirm('Delete this bin and all its sub-bins? (Legacy cascade, now transactional.)')">
                        @csrf @method('DELETE')
                        <button class="btn secondary" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" style="color:var(--muted)">No bins found.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $bins->links() }}
</x-masters.layout>
