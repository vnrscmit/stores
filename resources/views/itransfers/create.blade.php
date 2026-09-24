@extends('layouts.app')

@section('title', 'New — Inter Item Transfer')

@section('content')
<div class="card" x-data="itransferEntry()">
    <h2>New Inter Item Transfer</h2>
    <p class="muted">
        Pick the source item and one of its stock locations, then the destination item and location
        with the transfer quantity. The transaction is created with the first line; further lines and
        the final post happen in the workspace.
    </p>

    <h3 style="font-size:.95rem; margin-top:1rem">Transaction details</h3>
    <div class="filters" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(14rem,1fr)); gap:1rem; margin:.5rem 0 1rem">
        <div><label>Transfer Date *</label><input type="date" x-model="header.tdate"></div>
        <div style="grid-column:1/-1"><label>Remarks</label><input type="text" x-model="header.remarks" maxlength="1000"></div>
    </div>

    <h3 style="font-size:.95rem">Source (stock in hand)</h3>
    <div class="filters" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(11rem,1fr)); gap:1rem">
        <div><label>Classification *</label>
            <select x-model.number="source.classification_id" @change="loadSourceItems()">
                <option value="">-- Select Classification --</option>
                @foreach ($classifications as $c)
                    <option value="{{ $c->classification_id }}">{{ $c->classification }}</option>
                @endforeach
            </select>
        </div>
        <div><label>Item *</label>
            <select x-model.number="source.items_id" @change="loadSources()" :disabled="! sourceItems.length">
                <option value="">-- Select Item --</option>
                <template x-for="i in sourceItems" :key="i.items_id">
                    <option :value="i.items_id" x-text="i.stores_item"></option>
                </template>
            </select>
        </div>
        <div><label>UoM</label><input type="text" :value="sourceUom" readonly placeholder="from item"></div>
    </div>

    <table class="data" x-show="sources.length">
        <thead>
        <tr><th></th><th>SLOC (wh / bin / sub-bin)</th><th>UPS</th><th>Qty</th></tr>
        </thead>
        <tbody>
        <template x-for="loc in sources" :key="loc.stlg_id">
            <tr>
                <td><input type="radio" name="source_row" :value="loc.stlg_id" x-model.number="source.rowid"></td>
                <td x-text="`${loc.whid} / ${loc.binid} / ${loc.subbinid}`"></td>
                <td x-text="loc.ups"></td>
                <td x-text="loc.qty"></td>
            </tr>
        </template>
        </tbody>
    </table>

    <h3 style="font-size:.95rem; margin-top:1rem">Transferred to</h3>
    <p class="muted">Destination items of the same classification. The distributed quantity may not exceed the source location's balance.</p>
    <div class="filters" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(11rem,1fr)); gap:1rem">
        <div><label>Classification *</label>
            <select x-model.number="target.classification_id" @change="loadDestItems()">
                <option value="">-- Select Classification --</option>
                @foreach ($classifications as $c)
                    <option value="{{ $c->classification_id }}">{{ $c->classification }}</option>
                @endforeach
            </select>
        </div>
        <div><label>Item *</label>
            <select x-model.number="target.items_id" :disabled="! destItems.length">
                <option value="">-- Select Item --</option>
                <template x-for="i in destItems" :key="i.items_id">
                    <option :value="i.items_id" x-text="i.stores_item"></option>
                </template>
            </select>
        </div>
        <div><label>UoM</label><input type="text" x-model="target.uom" readonly placeholder="from item"></div>
    </div>

    <div class="filters" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(11rem,1fr)); gap:1rem; margin-top:.6rem">
        <div><label>WH Id *</label><input type="number" min="1" x-model.number="target.whid"></div>
        <div><label>Bin Id *</label><input type="number" min="1" x-model.number="target.binid"></div>
        <div><label>Sub-bin Id *</label><input type="number" min="1" x-model.number="target.subbin"></div>
        <div><label>Transfer UPS *</label><input type="number" min="0" x-model.number="target.ups_to"></div>
        <div><label>Transfer Qty *</label><input type="number" min="0" step="0.001" x-model.number="target.qty_to"></div>
    </div>

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
        Alpine.data('itransferEntry', () => ({
            header: { tdate: new Date().toISOString().slice(0, 10), remarks: '' },
            source: { classification_id: '', items_id: '', rowid: '' },
            target: { classification_id: '', items_id: '', uom: '', whid: '', binid: '', subbin: '', ups_to: 0, qty_to: 0 },
            sourceItems: [],
            destItems: [],
            sources: [],
            busy: false,
            msg: '',
            get sourceUom() {
                const item = this.sourceItems.find((i) => Number(i.items_id) === Number(this.source.items_id));
                return item ? (item.uom || '') : '';
            },
            async loadSourceItems() {
                this.sourceItems = [];
                this.source.items_id = '';
                this.sources = [];
                this.source.rowid = '';
                if (! this.source.classification_id) return;
                const r = await fetch(`{{ route('eindents.items.index', ':id') }}`.replace(':id', this.source.classification_id));
                if (r.ok) this.sourceItems = await r.json();
            },
            async loadSources() {
                this.sources = [];
                this.source.rowid = '';
                if (! this.source.classification_id || ! this.source.items_id) return;
                const r = await fetch(`{{ route('itransfers.sources', [':c', ':i']) }}`
                    .replace(':c', this.source.classification_id).replace(':i', this.source.items_id));
                if (r.ok) {
                    const body = await r.json();
                    this.sources = body.availability;
                }
            },
            async loadDestItems() {
                this.destItems = [];
                this.target.items_id = '';
                this.target.uom = '';
                if (! this.target.classification_id || ! this.source.items_id) return;
                const r = await fetch(`{{ route('itransfers.destinations', [':c', ':i']) }}`
                    .replace(':c', this.target.classification_id).replace(':i', this.source.items_id));
                if (r.ok) {
                    const body = await r.json();
                    this.destItems = body.items;
                }
            },
            async save() {
                this.busy = true; this.msg = '';
                if (! this.source.rowid) { this.msg = 'Select the source stock location.'; this.busy = false; return; }
                const src = this.sources.find((l) => Number(l.stlg_id) === Number(this.source.rowid)) || { ups: 0, qty: 0 };
                const payload = {
                    ...this.header,
                    transfer_id: null,
                    classification_id: this.source.classification_id,
                    items_id: this.source.items_id,
                    rowid: Number(this.source.rowid),
                    ups_from: src.ups,
                    qty_from: src.qty,
                    targets: [{
                        classification_id: this.target.classification_id,
                        items_id: this.target.items_id,
                        whid: Number(this.target.whid),
                        binid: Number(this.target.binid),
                        subbin: Number(this.target.subbin),
                        ups_to: Number(this.target.ups_to),
                        qty_to: Number(this.target.qty_to),
                    }],
                };
                const resp = await fetch('{{ route('itransfers.lines.store') }}', {
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
