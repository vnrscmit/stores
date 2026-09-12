@extends('layouts.app')

@section('title', 'New Captive Consumption (CC)')

@section('content')
<div class="card" x-data="ccEntry()">
    <h2>New Captive Consumption (CC)</h2>
    <p class="muted">
        Enter the transaction details and the first item line. The transaction is created with the
        first line; further lines and the final post happen in the workspace. The line quantity is
        the sum of the stock-location distribution (no separate qty field — legacy behaviour).
    </p>

    <h3 style="font-size:.95rem; margin-top:1rem">Transaction details</h3>
    <div class="filters" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(14rem,1fr)); gap:1rem; margin:.5rem 0 1rem">
        <div><label>Date *</label><input type="date" x-model="header.tdate"></div>
        <div><label>Party mode *</label>
            <select x-model="header.party_mode">
                <option value="master">From party master</option>
                <option value="manual">Manual entry</option>
            </select>
        </div>
        <template x-if="header.party_mode === 'master'">
            <div><label>Party *</label>
                <select x-model.number="header.party_id">
                    <option value="">-- Select Party --</option>
                    @foreach ($parties as $p)
                        <option value="{{ $p->p_id }}">{{ $p->business_name }}</option>
                    @endforeach
                </select>
            </div>
        </template>
        <template x-if="header.party_mode === 'manual'">
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(12rem,1fr)); gap:1rem; grid-column:1/-1">
                <div><label>Party Name *</label><input type="text" x-model="header.party_name" maxlength="250"></div>
                <div><label>Address</label><input type="text" x-model="header.address" maxlength="100"></div>
                <div><label>Address 1</label><input type="text" x-model="header.address1" maxlength="100"></div>
                <div><label>City</label><input type="text" x-model="header.city" maxlength="100"></div>
                <div><label>PIN</label><input type="text" x-model="header.pin" maxlength="10"></div>
                <div><label>State</label><input type="text" x-model="header.state" maxlength="50"></div>
            </div>
        </template>
        <div><label>Contact No</label><input type="text" x-model="header.contactno" maxlength="20"></div>
        <div><label>Return Type *</label>
            <select x-model="header.rettyp">
                <option value="">-- Select --</option>
                <option>Returnable</option>
                <option>Not Returnable</option>
            </select>
        </div>
        <div><label>Mode of Transit *</label>
            <select x-model="header.tmode">
                <option value="">-- Select Mode --</option>
                <option>Transport</option>
                <option>Courier</option>
                <option>By Hand</option>
            </select>
        </div>
        <template x-if="header.tmode === 'Transport'">
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(12rem,1fr)); gap:1rem; grid-column:1/-1">
                <div><label>Transport Name</label><input type="text" x-model="header.tname" maxlength="100"></div>
                <div><label>LR No</label><input type="text" x-model="header.lrno" maxlength="50"></div>
                <div><label>Vehicle No</label><input type="text" x-model="header.vno" maxlength="100"></div>
                <div><label>Payment Mode</label><input type="text" x-model="header.pmode" maxlength="100"></div>
            </div>
        </template>
        <template x-if="header.tmode === 'Courier'">
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(12rem,1fr)); gap:1rem; grid-column:1/-1">
                <div><label>Courier Name</label><input type="text" x-model="header.cname" maxlength="50"></div>
                <div><label>Docket No</label><input type="text" x-model="header.docketno" maxlength="50"></div>
            </div>
        </template>
        <template x-if="header.tmode === 'By Hand'">
            <div style="grid-column:1/-1"><label>Name of Person</label><input type="text" x-model="header.pname" maxlength="100"></div>
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
        <div><label>Item condition *</label>
            <select x-model="line.type">
                <option value="good">Good</option>
            </select>
        </div>
        <div><label>UoM</label><input type="text" x-model="line.uom" readonly placeholder="from item"></div>
    </div>

    <h3 style="font-size:.95rem; margin-top:1rem">Stock locations (quantity = distribution sum)</h3>
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
        Alpine.data('ccEntry', () => ({
            header: { party_mode: 'master', tmode: '', rettyp: '' },
            line: { classification_id: '', items_id: '', type: 'good', uom: '' },
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
                const r = await fetch(`{{ route('issues.cc.availability', [':c', ':i']) }}`
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
                    uom: this.line.uom || 'Number',
                    type: this.line.type,
                    slocs: this.sel.map((stlgId) => {
                        const loc = this.availability.find((l) => Number(l.stlg_id) === Number(stlgId));
                        const r = loc ? this.rows[loc.key] : null;
                        return r ? { stlg_id: stlgId, ups: r.ups, qty: r.qty } : null;
                    }).filter(Boolean),
                };
                const resp = await fetch('{{ route('issues.cc.lines.store') }}', {
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
