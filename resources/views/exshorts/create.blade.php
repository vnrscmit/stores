@extends('layouts.app')

@section('title', 'Excess / Shortage — new')

@section('content')
<div class="card" x-data="exshortCreate()">
    <h2>New Excess / Shortage adjustment</h2>

    <p class="muted">
        One document adjusts ONE item in ONE ledger (good or damage). Rows are selected from the
        item's current stock; each selected row is adjusted up (excess, subtype ES) or down
        (shortage, subtype SH) — a row cannot carry both.
    </p>

    <div class="filters" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(11rem,1fr)); gap:1rem">
        <div>
            <label>Transaction Date *</label>
            <input type="date" x-model="doc.tdate">
        </div>
        <div>
            <label>Ledger *</label>
            <select x-model="doc.typ" @change="loadAvailability()">
                <option value="">-- Select Type --</option>
                <option value="good">Good stock</option>
                <option value="damage">Damage stock</option>
            </select>
        </div>
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

    <h3 style="font-size:.95rem; margin-top:1rem">SLOC rows</h3>
    <template x-if="rows.length > 0">
        <table class="data">
            <thead>
            <tr>
                <th>SLOC (wh / bin / sub-bin)</th>
                <th>Opening UPS</th>
                <th>Opening Qty</th>
                <th>Excess UPS</th>
                <th>Excess Qty</th>
                <th>Shortage UPS</th>
                <th>Shortage Qty</th>
                <th>Post Balance Qty</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="(row, index) in rows" :key="row.rowid">
                <tr>
                    <td x-text="`${row.whid} / ${row.binid} / ${row.subbinid}`"></td>
                    <td x-text="row.ups"></td>
                    <td x-text="row.qty"></td>
                    <td><input type="number" min="0" x-model.number="row.upsex" @input="clearShortage(index)"></td>
                    <td><input type="number" min="0" step="0.001" x-model.number="row.qtyex" @input="clearShortage(index)"></td>
                    <td><input type="number" min="0" x-model.number="row.upssh" @input="clearExcess(index)"></td>
                    <td><input type="number" min="0" step="0.001" x-model.number="row.qtysh" @input="clearExcess(index)"></td>
                    <td x-text="postBalanceQty(row)"></td>
                </tr>
            </template>
            </tbody>
        </table>
    </template>
    <p class="muted" x-show="rows.length === 0">Select classification, item and ledger to load the SLOC rows.</p>

    <div style="margin-top:1rem">
        <label>Remarks (reason for excess/shortage)</label>
        <textarea x-model="doc.remarks" rows="3" maxlength="1000"></textarea>
    </div>

    <div style="margin-top:1rem; display:flex; gap:.5rem">
        <button class="btn" @click="save()">Save workspace</button>
        <span class="muted" x-show="error" x-text="error" style="color:#b00"></span>
    </div>
</div>

<script>
function exshortCreate() {
    return {
        doc: {
            excess_id: null,
            tdate: new Date().toISOString().slice(0, 10),
            classification_id: '',
            items_id: '',
            uom: '',
            typ: '',
            remarks: '',
        },
        items: [],
        rows: [],
        error: '',

        async loadItems() {
            this.items = [];
            this.rows = [];
            if (!this.doc.classification_id) return;
            const resp = await fetch(`{{ route('exshorts.items', ':id') }}`.replace(':id', this.doc.classification_id));
            const body = await resp.json();
            this.items = body.items ?? [];
        },

        async loadAvailability() {
            this.rows = [];
            if (!this.doc.items_id || !this.doc.typ) return;
            const item = this.items.find((i) => i.items_id === Number(this.doc.items_id));
            if (item && !this.doc.uom) this.doc.uom = item.uom ?? '';
            const resp = await fetch(`{{ route('exshorts.availability', ':id') }}`.replace(':id', this.doc.items_id) + `?typ=${this.doc.typ}`);
            const body = await resp.json();
            this.rows = (body.rows ?? []).map((r) => ({
                ...r, upsex: 0, qtyex: 0, upssh: 0, qtysh: 0,
            }));
        },

        clearExcess(index) {
            if (this.rows[index].upssh !== 0 || this.rows[index].qtysh !== 0) {
                this.rows[index].upsex = 0;
                this.rows[index].qtyex = 0;
            }
        },

        clearShortage(index) {
            if (this.rows[index].upsex !== 0 || this.rows[index].qtyex !== 0) {
                this.rows[index].upssh = 0;
                this.rows[index].qtysh = 0;
            }
        },

        postBalanceQty(row) {
            const excess = Number(row.upsex) !== 0 || Number(row.qtyex) !== 0;
            const base = Number(row.qty);
            return excess ? (base + Number(row.qtyex)).toFixed(3) : (base - Number(row.qtysh)).toFixed(3);
        },

        async save() {
            this.error = '';
            const selected = this.rows.filter((r) => r.upsex !== 0 || r.qtyex !== 0 || r.upssh !== 0 || r.qtysh !== 0);
            if (selected.length === 0) {
                this.error = 'Select at least one SLOC row and enter an excess or shortage.';
                return;
            }
            const payload = {
                ...this.doc,
                excess_id: this.doc.excess_id,
                rows: selected.map((r) => ({
                    rowid: r.rowid, upsex: r.upsex, qtyex: r.qtyex, upssh: r.upssh, qtysh: r.qtysh,
                })),
            };
            const resp = await fetch('{{ route('exshorts.store') }}', {
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
            this.doc.excess_id = body.excess_id;
            window.location = body.redirect;
        },
    };
}
</script>
@endsection
