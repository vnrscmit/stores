@extends('layouts.app')

@section('title', 'New — Material Discard')

@section('content')
<div class="card" x-data="discardEntry()">
    <h2>New Material Discard</h2>
    <p class="muted">
        Pick an item and discard quantities from its damage-SLOC rows. The transaction is created
        with the first line; further lines, transport details and the final post happen in the workspace.
    </p>

    <h3 style="font-size:.95rem; margin-top:1rem">Transaction details</h3>
    <div class="filters" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(12rem,1fr)); gap:1rem; margin:.5rem 0 1rem">
        <div><label>Discard Date *</label><input type="date" x-model="header.tdate"></div>
        <div><label>Discard Instruction Ref. No.</label><input type="text" x-model="header.drno" maxlength="50"></div>
        <div><label>Party Name *</label><input type="text" x-model="header.party_name" maxlength="250"></div>
        <div><label>Phone</label><input type="text" x-model="header.phoneno" maxlength="20"></div>
        <div style="grid-column:1/-1"><label>Address</label><input type="text" x-model="header.address" maxlength="1000"></div>
        <div><label>Return status</label>
            <select x-model="header.rettyp">
                <option value="damage">damage</option>
                <option value="returnable">returnable</option>
            </select>
        </div>
        <div style="grid-column:1/-1"><label>Remarks</label><input type="text" x-model="header.remarks" maxlength="1000"></div>
    </div>

    <h3 style="font-size:.95rem">Discard line</h3>
    <div class="filters" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(11rem,1fr)); gap:1rem">
        <div><label>Classification *</label>
            <select x-model.number="line.classification_id" @change="loadItems()">
                <option value="">-- Select Classification --</option>
                @foreach ($classifications as $c)
                    <option value="{{ $c->classification_id }}">{{ $c->classification }}</option>
                @endforeach
            </select>
        </div>
        <div><label>Item *</label>
            <select x-model.number="line.items_id" @change="loadAvailability()" :disabled="! items.length">
                <option value="">-- Select Item --</option>
                <template x-for="i in items" :key="i.items_id">
                    <option :value="i.items_id" x-text="i.stores_item"></option>
                </template>
            </select>
        </div>
        <div><label>UoM</label><input type="text" :value="uom" readonly placeholder="from item"></div>
    </div>

    <table class="data" x-show="rows.length">
        <thead>
        <tr>
            <th>SLOC (wh / bin / sub-bin)</th>
            <th>Available UPS</th>
            <th>Available Qty</th>
            <th>Discard UPS</th>
            <th>Discard Qty</th>
            <th>Balance UPS</th>
            <th>Balance Qty</th>
        </tr>
        </thead>
        <tbody>
        <template x-for="(row, idx) in rows" :key="row.stld_id">
            <tr>
                <td x-text="`${row.whid} / ${row.binid} / ${row.subbinid}`"></td>
                <td x-text="row.ups"></td>
                <td x-text="row.qty"></td>
                <td><input type="number" min="0" x-model.number="row.ups_discard"></td>
                <td><input type="number" min="0" step="0.001" x-model.number="row.qty_discard"></td>
                <td x-text="Math.max(0, row.ups - row.ups_discard)"></td>
                <td x-text="Math.max(0, row.qty - row.qty_discard)"></td>
            </tr>
        </template>
        </tbody>
    </table>
    <p class="muted" x-show="! rows.length && line.items_id">Item not in damage stock.</p>

    <div style="margin-top:1rem; display:flex; gap:.75rem; align-items:center">
        <button type="button" class="btn" @click="save()" :disabled="busy">Save line &amp; open workspace</button>
        <span class="alert error" x-show="msg" x-text="msg" style="margin:0"></span>
    </div>
</div>
@endsection

@push('styles')
<style>.muted { color: var(--muted); }</style>
@endpush

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('discardEntry', () => ({
            header: {
                tdate: new Date().toISOString().slice(0, 10),
                drno: '', party_name: '', address: '', phoneno: '',
                rettyp: 'damage', remarks: '',
            },
            line: { classification_id: '', items_id: '' },
            items: [],
            rows: [],
            busy: false,
            msg: '',
            get uom() {
                const item = this.items.find((i) => Number(i.items_id) === Number(this.line.items_id));
                return item ? (item.uom || '') : '';
            },
            async loadItems() {
                this.items = [];
                this.line.items_id = '';
                this.rows = [];
                if (! this.line.classification_id) return;
                const r = await fetch(`{{ route('discards.items', ':id') }}`.replace(':id', this.line.classification_id));
                if (r.ok) this.items = (await r.json()).items;
            },
            async loadAvailability() {
                this.rows = [];
                if (! this.line.items_id) return;
                const r = await fetch(`{{ route('discards.availability', ':id') }}`.replace(':id', this.line.items_id));
                if (r.ok) {
                    const body = await r.json();
                    this.rows = body.availability.map((a) => ({ ...a, ups_discard: 0, qty_discard: 0 }));
                }
            },
            async save() {
                this.busy = true; this.msg = '';
                const rows = this.rows.filter((r) => Number(r.qty_discard) > 0 || Number(r.ups_discard) > 0);
                if (! rows.length) { this.msg = 'Enter a discard quantity on at least one SLOC row.'; this.busy = false; return; }
                if (! this.header.party_name.trim()) { this.msg = 'Party name is required.'; this.busy = false; return; }
                const payload = {
                    ...this.header,
                    discard_id: null,
                    classification_id: this.line.classification_id,
                    items_id: this.line.items_id,
                    rows: rows.map((r) => ({
                        stld_id: r.stld_id,
                        ups_discard: Number(r.ups_discard),
                        qty_discard: Number(r.qty_discard),
                    })),
                };
                const resp = await fetch('{{ route('discards.lines.store') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify(payload),
                });
                const body = await resp.json().catch(() => ({}));
                this.busy = false;
                if (! resp.ok) { this.msg = body.message || 'Save failed.'; return; }
                window.location = body.redirect;
            },
        }));
    });
</script>
