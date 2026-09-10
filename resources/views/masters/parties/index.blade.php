<x-masters.layout title="Parties" createRoute="masters.parties.create" createLabel="New Party">
    <x-slot:filters>
        <div><label>Search name / contact / city</label><input type="text" name="q" value="{{ $q }}"></div>
        <div>
            <label>Category</label>
            <select name="classification">
                <option value="">All</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat }}" @selected($category === $cat)>{{ $cat }}</option>
                @endforeach
            </select>
        </div>
    </x-slot:filters>

    <table class="data">
        <thead>
        <tr>
            <th>ID</th><th>Business Name</th><th>Category</th><th>Contact</th><th>City</th><th>State</th><th>Phone</th><th style="width:14rem">Actions</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($parties as $party)
            <tr>
                <td>{{ $party->p_id }}</td>
                <td>{{ $party->business_name }}</td>
                <td>{{ $party->classification }}</td>
                <td>{{ $party->contact }}</td>
                <td>{{ $party->city }}</td>
                <td>{{ $party->state }}</td>
                <td>{{ $party->phone }}</td>
                <td>
                    <a class="btn secondary" href="{{ route('masters.parties.edit', $party) }}">Edit</a>
                    <form method="POST" action="{{ route('masters.parties.destroy', $party) }}" style="display:inline"
                          onsubmit="return confirm('Delete this party? (Legacy parity: hard delete.)')">
                        @csrf @method('DELETE')
                        <button class="btn secondary" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="8" style="color:var(--muted)">No parties found.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $parties->links() }}
</x-masters.layout>
