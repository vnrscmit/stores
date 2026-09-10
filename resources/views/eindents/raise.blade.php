@extends('layouts.app')

@section('title', $indent ? 'Continue e-Indent' : 'Raise e-Indent')

@section('content')
<div class="card" x-data="indentWorkspace()">
    <h2>{{ $indent ? 'Continue e-Indent '.\App\Support\IndentNumbering::transactionId($indent) : 'Raise e-Indent' }}</h2>

    <div class="filters" style="display:flex; gap:2rem; margin:.5rem 0 1rem">
        <div><span class="muted">Transaction Id</span><br>
            <strong>{{ $indent ? \App\Support\IndentNumbering::transactionId($indent) : 'TIR—' }}</strong></div>
        <div><span class="muted">Indent No</span><br>
            <strong>{{ $indent ? 'T'.$indent->code1 : 'T—' }}</strong></div>
        <div><span class="muted">Indent Date</span><br>
            <strong>{{ now()->format('d-m-Y') }}</strong></div>
        <div><span class="muted">Raised by</span><br>
            <strong>{{ auth()->user()->login }}</strong></div>
    </div>

    {{-- Posted rows (legacy #ind table) --}}
    <table x-show="rows.length">
        <thead>
        <tr><th>#</th><th>Classification</th><th>Item</th><th>UoM</th><th>Quantity</th><th>Edit</th><th>Delete</th></tr>
        </thead>
        <tbody>
        <template x-for="(row, i) in rows" :key="row.eid">
            <tr>
                <td x-text="i + 1"></td>
                <td x-text="row.classification"></td>
                <td x-text="row.item"></td>
                <td x-text="row.uom"></td>
                <td x-text="row.qty"></td>
                <td><button type="button" class="btn secondary" @click="editRow(row)">Edit</button></td>
                <td><button type="button" class="btn danger" @click="deleteRow(row)">Delete</button></td>
            </tr>
        </template>
        </tbody>
    </table>

    {{-- Post Item From (legacy #ind1) --}}
    <form @submit.prevent="postRow()" style="margin-top:1rem">
        <div class="filters">
            <label>Classification *
                <select x-model="form.classification_id" @change="loadItems()" required>
                    <option value="">--Select Classification--</option>
                    @foreach ($classifications as $cid => $name)
                        <option value="{{ $cid }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>

            <label>Stores Item *
                <select x-model="form.items_id" @change="loadUom()" required>
                    <option value="">--Select Item--</option>
                    <template x-for="item in items" :key="item.items_id">
                        <option :value="item.items_id" x-text="item.stores_item"></option>
                    </template>
                </select>
            </label>

            <label>UoM
                <input type="text" x-model="form.uom" readonly>
            </label>

            <label>Quantity *
                <input type="number" x-model="form.qty" min="0.001" max="9999999" step="0.001" required>
            </label>
        </div>

        <div style="margin-top:.75rem; display:flex; gap:.75rem">
            <button type="submit" class="btn" x-text="form.eid ? 'Update Row' : 'Post'"></button>
            <button type="button" class="btn secondary" x-show="form.eid" @click="resetForm()">Cancel Edit</button>
        </div>
    </form>

    {{-- Remarks + final submit (legacy remarks row / print preview hand-off) --}}
    <form method="POST" action="{{ $indent ? route('eindents.remarks', $indent) : '#' }}"
          style="margin-top:1rem">
        @csrf
        @method('PUT')
        <div class="filters">
            <label style="flex:1">Remarks
                <input type="text" name="remarks" maxlength="90" style="width:100%"
                       value="{{ $indent->remarks ?? old('remarks') }}">
            </label>
            @if ($indent)
                <button class="btn secondary" type="submit">Save Remarks</button>
            @endif
        </div>
    </form>

    @if ($indent)
        <form method="POST" action="{{ route('eindents.submit', $indent) }}" style="margin-top:1rem"
              onsubmit="return confirm('Have You completed the Transaction?\nDo You wish to Final Submit it?')">
            @csrf
            <button class="btn" type="submit">Final Submit for Approval</button>
            <a class="btn secondary" href="{{ route('eindents.index') }}">Cancel</a>
        </form>
    @endif
</div>

<script>
    function indentWorkspace() {
        return {
            tid: @js($indent?->tid ?? 0),
            rows: @js($indent ? \App\Http\Controllers\EIndent\IndentItemController::rowsPayload($indent)['rows'] : []),
            items: [],
            form: { eid: null, classification_id: '', items_id: '', uom: '', qty: '' },

            async loadItems() {
                this.form.items_id = '';
                this.form.uom = '';
                if (!this.form.classification_id) { this.items = []; return; }
                const res = await fetch(`/eindents/classifications/${this.form.classification_id}/items`);
                this.items = await res.json();
            },
            loadUom() {
                const item = this.items.find(i => String(i.items_id) === String(this.form.items_id));
                this.form.uom = item ? item.uom : '';
            },
            editRow(row) {
                this.form = { eid: row.eid, classification_id: String(row.classification_id), items_id: String(row.items_id), uom: row.uom, qty: row.qty };
                this.loadItems();
            },
            resetForm() {
                this.form = { eid: null, classification_id: '', items_id: '', uom: '', qty: '' };
                this.items = [];
            },
            async postRow() {
                if (!this.form.classification_id || !this.form.items_id || !this.form.qty) {
                    alert('Select classification, item and quantity.'); return;
                }
                const url = this.form.eid
                    ? `/eindents/items/${this.form.eid}`
                    : '/eindents/items';
                const res = await fetch(url, {
                    method: this.form.eid ? 'PUT' : 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ ...this.form, tid: this.tid }),
                });
                const data = await res.json();
                if (!res.ok) { alert(data.message || 'Could not save the row.'); return; }
                this.rows = data.rows.rows;
                this.resetForm();
            },
            async deleteRow(row) {
                if (!confirm('Do you wish to delete the item?')) return;
                const res = await fetch(`/eindents/items/${row.eid}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                });
                const data = await res.json();
                if (!res.ok) { alert(data.message || 'Could not delete the row.'); return; }
                this.rows = data.rows.rows;
            },
        };
    }
</script>
@endsection
