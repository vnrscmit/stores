@extends('layouts.app')

@section('title', 'New — '.$meta['label'])

@section('content')
<div class="card" x-data="issueEntry()">
    <h2>New {{ $meta['label'] }}</h2>
    <p class="muted">
        Enter the transaction details and the first item line. The transaction is created with the
        first line; further lines and the final post happen in the workspace.
    </p>

    <h3 style="font-size:.95rem; margin-top:1rem">Transaction details</h3>
    <div class="filters" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(14rem,1fr)); gap:1rem; margin:.5rem 0 1rem">
        @if ($type === 'pindent')
            <div><label>Physical Indent No *</label><input type="text" name="dcrefno" x-model="header.dcrefno" maxlength="50"></div>
            <div><label>Raised By *</label><input type="text" name="strefno" x-model="header.strefno" maxlength="50"></div>
            <div><label>Issue Date *</label><input type="date" name="issue_date" x-model="header.issue_date"></div>
        @elseif ($type === 'stocktr')
            <div><label>Transfer Ref No *</label><input type="text" name="strefno" x-model="header.strefno" maxlength="50"></div>
            <div><label>Transfer Date *</label><input type="date" name="strdate" x-model="header.strdate"></div>
            <div><label>Party *</label>
                <select name="party_id" x-model.number="header.party_id">
                    <option value="">-- Select Party --</option>
                    @foreach ($parties as $p)
                        <option value="{{ $p->p_id }}">{{ $p->business_name }}</option>
                    @endforeach
                </select>
            </div>
            <div><label>Return Type *</label><input type="text" name="rettyp" x-model="header.rettyp" maxlength="50"></div>
        @else
            <div><label>Party *</label>
                <select name="party_id" x-model.number="header.party_id">
                    <option value="">-- Select Party --</option>
                    @foreach ($parties as $p)
                        <option value="{{ $p->p_id }}">{{ $p->business_name }}</option>
                    @endforeach
                </select>
            </div>
            <div><label>Party DC Ref No *</label><input type="text" name="dcrefno" x-model="header.dcrefno" maxlength="50"></div>
            <div><label>Return Date *</label><input type="date" name="issue_date" x-model="header.issue_date"></div>
        @endif

        <div><label>Mode of Transit *</label>
            <select name="tmode" x-model="header.tmode">
                <option value="">-- Select Mode --</option>
                <option>Transport</option>
                <option>Courier</option>
                <option>By Hand</option>
            </select>
        </div>
        <template x-if="header.tmode === 'Transport'">
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(12rem,1fr)); gap:1rem; grid-column:1/-1">
                <div><label>Transport Name</label><input type="text" x-model="header.trans_name" maxlength="100"></div>
                <div><label>Lorry Receipt No</label><input type="text" x-model="header.trans_lorryrepno" maxlength="50"></div>
                <div><label>Vehicle No</label><input type="text" x-model="header.trans_vehno" maxlength="50"></div>
                <div><label>Payment Mode</label><input type="text" x-model="header.trans_paymode" maxlength="50"></div>
            </div>
        </template>
        <template x-if="header.tmode === 'Courier'">
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(12rem,1fr)); gap:1rem; grid-column:1/-1">
                <div><label>Courier Name</label><input type="text" x-model="header.courier_name" maxlength="100"></div>
                <div><label>Docket No</label><input type="text" x-model="header.docket_no" maxlength="50"></div>
            </div>
        </template>
        <template x-if="header.tmode === 'By Hand'">
            <div style="grid-column:1/-1"><label>Name of Person</label><input type="text" x-model="header.pname_byhand" maxlength="250"></div>
        </template>
        <div style="grid-column:1/-1"><label>Remarks</label><input type="text" x-model="header.remarks" maxlength="1000"></div>
    </div>

    <h3 style="font-size:.95rem">First item line</h3>
    <div class="filters" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(12rem,1fr)); gap:1rem">
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
        <div><label>UPS *</label><input type="number" min="0" x-model.number="line.ups"></div>
        <div><label>Quantity *</label><input type="number" min="0.001" step="0.001" x-model.number="line.qty"></div>
        <div><label>UoM</label><input type="text" x-model="line.uom" readonly placeholder="from item"></div>
    </div>

    <h3 style="font-size:.95rem; margin-top:1rem">Stock locations</h3>
    <table class="data" x-show="availability.length">
        <thead>
        <tr><th>Use</th><th>SLOC (wh / bin / sub-bin)</th><th>Stock UPS</th><th>Stock Qty</th><th>Issue UPS</th><th>Issue Qty</th></tr>
        </thead>
        <tbody>
        <template x-for="loc in availability" :key="loc.key">
            <tr>
                <td><input type="checkbox" :value="loc.stlg_id" x-model.number="sel"></td>
                <td x-text="`${loc.whid} / ${loc.binid} / ${loc.subbinid}`"></td>
                <td x-text="loc.ups"></td>
                <td x-text="loc.qty"></td>
                <td><input type="number" min="0" x-model.number="rows[loc.key].ups" style="width:6rem"></td>
                <td><input type="number" min="0" step="0.001" x-model.number="rows[loc.key].qty" style="width:7rem"></td>
            </tr>
        </template>
        </tbody>
    </table>
    <p class="muted" x-show="! availability.length && line.items_id">No stock locations hold this item.</p>

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
        Alpine.data('issueEntry', () => ({
            header: { tmode: '', remarks: '' },
            line: { classification_id: '', items_id: '', ups: 0, qty: 0, uom: '' },
            items: [],
            availability: [],
            sel: [],
            rows: {},
            busy: false,
            msg: '',
            async loadItems() {
                this.items = [];
                this.line.items_id = '';
                this.availability = [];
                if (! this.line.classification_id) return;
                const r = await fetch(`{{ route('eindents.items.index', ':id') }}`.replace(':id', this.line.classification_id));
                if (r.ok) this.items = await r.json();
            },
            async loadAvailability() {
                this.availability = [];
                this.sel = [];
                const item = this.items.find((i) => Number(i.items_id) === Number(this.line.items_id));
                if (item) this.line.uom = item.uom || '';
                if (! this.line.classification_id || ! this.line.items_id) return;
                const r = await fetch(`{{ route('issues.pindent.availability', [':c', ':i']) }}`
                    .replace(':c', this.line.classification_id).replace(':i', this.line.items_id));
                if (r.ok) {
                    const body = await r.json();
                    this.availability = body.availability;
                    this.availability.forEach((loc) => { this.rows[loc.key] = { ups: 0, qty: 0 }; });
                }
            },
            async save() {
                this.busy = true; this.msg = '';
                const payload = {
                    ...this.header,
                    classification_id: this.line.classification_id,
                    items_id: this.line.items_id,
                    ups: this.line.ups,
                    qty: this.line.qty,
                    uom: this.line.uom || 'Number',
                    slocs: this.sel.map((stlgId) => {
                        const loc = this.availability.find((l) => Number(l.stlg_id) === Number(stlgId));
                        const r = loc ? this.rows[loc.key] : null;
                        return r ? { stlg_id: stlgId, ups: r.ups, qty: r.qty } : null;
                    }).filter(Boolean),
                };
                const resp = await fetch('{{ route('issues.pindent.lines.store') }}', {
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
