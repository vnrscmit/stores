<x-masters.layout title="Sub-bins (SLOC)" createRoute="masters.subbins.create" createLabel="New Sub-bin">
    <x-slot:filters>
        <div>
            <label>Bin</label>
            <select name="binid">
                <option value="">All bins</option>
                @foreach ($bins as $bin)
                    <option value="{{ $bin->binid }}" @selected((string) $binid === (string) $bin->binid)>
                        {{ $bin->binname }} — {{ $bin->warehouse?->perticulars }}
                    </option>
                @endforeach
            </select>
        </div>
        <div><label>Search number</label><input type="text" name="q" value="{{ $q }}"></div>
    </x-slot:filters>

    <table class="data">
        <thead>
        <tr>
            <th>ID</th><th>#</th><th>Bin</th><th>Warehouse</th><th>Status</th><th style="width:10rem">Actions</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($subBins as $subBin)
            <tr>
                <td>{{ $subBin->sid }}</td>
                <td>{{ $subBin->sname }}</td>
                <td>{{ $subBin->bin?->binname }}</td>
                <td>{{ $subBin->bin?->warehouse?->perticulars }}</td>
                <td>{{ $subBin->status }}</td>
                <td>
                    <a class="btn secondary" href="{{ route('masters.subbins.edit', $subBin) }}">Edit</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" style="color:var(--muted)">No sub-bins found.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $subBins->links() }}
</x-masters.layout>
