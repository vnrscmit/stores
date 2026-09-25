@extends('layouts.app')

@section('title', $direction === 'g2d' ? 'Good → Damage — new' : 'Damage → Good — new')

@section('content')
<div class="card" x-data="gateMovementCreate()">
    <h2>{{ $direction === 'g2d' ? 'New Good → Damage (G2D)' : 'New Damage → Good (D2G)' }}</h2>

    <p class="muted">
        One document converts ONE item, slot by slot: pick the source SLOC rows (the
        {{ $direction === 'g2d' ? 'good' : 'damage' }} stock to convert) and a destination
        sub-bin for each — the {{ $direction === 'g2d' ? 'damage' : 'good' }} ledger receives the stock.
    </p>

    <div class="filters" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(11rem,1fr)); gap:1rem">
        <div>
            <label>Transaction Date *</label>
            <input type="date" x-model="doc.tdate">
        </div>
        @if ($direction === 'g2d')
            <div>
                <label>Party *</label>
                <select x-model.number="doc.party_id">
                    <option value="">-- Select Party --</option>
                    @foreach ($parties as $party)
                        <option value="{{ $party->p_id }}">{{ $party->business_name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div>
            <label>Classification *</label>
            <select x-model.number="doc.classification_id" @change="loadItems()">
                <option value="">-- Select Classification --</option>
                @foreach ($classifications as $classification)
                    <option value="{{ $classification->classification_id }}">{{ $classification->classification }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Item *</label>
            <select x-model.number="doc.items_id" @change="loadAvailability()">
                <option value="">-- Select Item --</option>
                <template x-for="item in items" :key="item.items_id">
                    <option :value="item.items_id" x-text="item.stores_item"></option>
                </template>
            </select>
        </div>
        <div>
            <label>UoM *</label>
            <input type="text" x-model="doc.uom" maxlength="20">
        </div>
    </div>

    <h3 style="font-size:.95rem; margin-top:1rem">Source SLOC rows</h3>
    <template x-if="rows.length > 0">
        <table class="data">
            <thead>
            <tr>
                <th>SLOC (wh / bin / sub-bin)</th>
                <th>Available UPS</th>
                <th>Available Qty</th>
                <th>Destination wh *</th>
                <th>Destination bin *</th>
                <th>Destination sub-bin *</th>
                <th>Convert UPS</th>
                <th>Convert Qty</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="(row, index) in rows" :key="row.rowid">
                <tr>
                    <td x-text="`${row.whid} / ${row.binid} / ${row.subbinid}`"></td>
                    <td x-text="row.ups"></td>
                    <td x-text="row.qty"></td>
                    <td><input type="number" min="1" x-model.number="row.dest_whid"></td>
                    <td><input type="number" min="1" x-model.number="row.dest_binid"></td>
                    <td><input type="number" min="1" x-model.number="row.dest_subbinid"></td>
                    <td><input type="number" min="0" x-model.number="row.ups"></td>
                    <td><input type="number" min="0" step="0.001" x-model.number="row.qty"></td>
                </tr>
            </template>
            </tbody>
        </table>
    </template>
    <p class="muted" x-show="rows.length === 0">Select classification and item to load the source SLOC rows.</p>

    <div style="margin-top:1rem">
        <label>Remarks</label>
        <textarea x-model="doc.remarks" rows="3" maxlength="1000"></textarea>
    </div>

    <div style="margin-top:1rem; display:flex; gap:.5rem">
        <button class="btn" @click="save()">Save workspace</button>
        <span class="muted" x-show="error" x-text="error" style="color:#b00"></span>
    </div>
</div>

<script>
function gateMovementCreate() {
    return {
        doc: {
            direction: '{{ $direction }}',
            gtod_id: null,
            dtog_id: null,
            tdate: new Date().toISOString().slice(0, 10),
            classification_id: '',
            items_id: '',
            uom: '',
            party_id: '',
            remarks: '',
        },
        items: [],
        rows: [],
        error: '',

        async loadItems() {
            this.items = [];
            this.rows = [];
            if (!this.doc.classification_id) return;
            const resp = await fetch(`{{ route('gatemovements.items', ':id') }}`.replace(':id', this.doc.classification_id));
            const body = await resp.json();
            this.items = body.items ?? [];
        },

        async loadAvailability() {
            this.rows = [];
            if (!this.doc.items_id) return;
            const item = this.items.find((i) => i.items_id === Number(this.doc.items_id));
            if (item && !this.doc.uom) this.doc.uom = item.uom ?? '';
            const resp = await fetch(`{{ route('gatemovements.availability', ':id') }}`.replace(':id', this.doc.items_id) + `?direction={{ $direction }}`);
            const body = await resp.json();
            this.rows = (body.rows ?? []).map((r) => ({
                ...r,
                dest_whid: '',
                dest_binid: '',
                dest_subbinid: '',
                ups: 0,
                qty: 0,
            }));
        },

        async save() {
            this.error = '';
            const selected = this.rows.filter((r) =>
                Number(r.dest_subbinid) > 0 && (Number(r.ups) !== 0 || Number(r.qty) !== 0));
            if (selected.length === 0) {
                this.error = 'Select at least one source row with a destination and a quantity.';
                return;
            }
            if ('{{ $direction }}' === 'g2d' && !this.doc.party_id) {
                this.error = 'The party is required for a Good to Damage movement.';
                return;
            }
            const payload = {
                ...this.doc,
                rows: selected.map((r) => ({
                    rowid: r.rowid,
                    whid: Number(r.dest_whid) || Number(r.whid),
                    binid: Number(r.dest_binid) || Number(r.binid),
                    subbinid: Number(r.dest_subbinid),
                    ups: r.ups,
                    qty: r.qty,
                })),
            };
            const resp = await fetch('{{ route('gatemovements.store') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: JSON.stringify(payload),
            });
            if (!resp.ok) {
                const body = await resp.json().catch(() => ({}));
                this.error = body.message ?? 'Save failed.';
                return;
            }
            const body = await resp.json();
            window.location = body.redirect;
        },
    };
}
</script>
@endsection
