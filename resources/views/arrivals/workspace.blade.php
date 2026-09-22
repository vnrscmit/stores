@extends('layouts.app')

@section('title', $meta['label'].' — workspace')

@section('content')
<div class="card" x-data="arrivalWorkspace()">
    <h2>{{ $meta['label'] }} — {{ \App\Support\ArrivalNumbering::transactionId($arrival, $type) }}</h2>

    <table class="data" style="margin-bottom:1rem">
        <tr>
            <th>Transaction Id</th>
            <td><strong>{{ \App\Support\ArrivalNumbering::transactionId($arrival, $type) }}</strong></td>
            <th>Date</th>
            <td>{{ optional($arrival->arrival_date)->format('d-m-Y') }}</td>
        </tr>
        <tr>
            @if ($type === 'vendor')
                <th>Vendor</th><td>{{ $partyName }}</td>
                <th>D.C./Inv. No</th><td>{{ $arrival->dcno }}</td>
            @elseif ($type === 'stocktr')
                <th>STN No</th><td>{{ $arrival->stnno }}</td>
                <th></th><td></td>
            @else
                <th>Party</th><td>{{ $partyName }}</td>
                <th>Returned From / By</th><td>{{ $arrival->stageret }} / {{ $arrival->retid }}</td>
            @endif
        </tr>
        <tr>
            <th>Mode of Transit</th><td>{{ $arrival->tmode }}</td>
            <th>Stage</th>
            <td><span class="badge">{{ \App\Support\ArrivalStatus::LABELS[$arrival->status] ?? $arrival->status }}</span></td>
        </tr>
        @if ($arrival->tmode === 'Transport')
            <tr><th>Transport</th><td>{{ $arrival->trans_name }} (LR {{ $arrival->trans_lorryrepno }})</td>
                <th>Vehicle / Payment</th><td>{{ $arrival->trans_vehno }} / {{ $arrival->trans_paymode }}</td></tr>
        @elseif ($arrival->tmode === 'Courier')
            <tr><th>Courier</th><td>{{ $arrival->courier_name }}</td><th>Docket No</th><td>{{ $arrival->docket_no }}</td></tr>
        @elseif ($arrival->tmode === 'By Hand')
            <tr><th>Name of Person</th><td colspan="3">{{ $arrival->pname_byhand }}</td></tr>
        @endif
        @if ($arrival->porefno)
            <tr><th>P. O. Reference</th><td colspan="3">{{ $arrival->porefno }}</td></tr>
        @endif
        @if ($arrival->remarks)
            <tr><th>Remarks</th><td colspan="3">{{ $arrival->remarks }}</td></tr>
        @endif
    </table>

    <h3 style="font-size:.95rem">Item lines</h3>
    @forelse ($lines as $state)
        @php($line = $state['line'])
        <div style="border:1px solid var(--line); border-radius:.5rem; padding:.8rem; margin-bottom:.8rem">
            <div style="display:flex; gap:1rem; align-items:center; flex-wrap:wrap">
                <strong>#{{ $loop->iteration }}</strong>
                <span>{{ $line->classification?->classification ?? '—' }} / {{ $line->item?->stores_item ?? '—' }}</span>
                <span class="muted">UoM {{ $line->uom }}</span>
                @if ($type === 'vendor')
                    <span class="muted">DC {{ $line->ups_per_dc }} / {{ $line->qty_per_dc }}</span>
                @endif
                <span class="muted">Good {{ $line->ups_good }} / {{ $line->qty_good }}</span>
                <span class="muted">Damage {{ $line->ups_damage }} / {{ $line->qty_damage }}</span>
                <span class="muted">Ex/Sh {{ $line->exsh_ups }} / {{ $line->exsh_qty }}</span>
            </div>
            @if ($state['distributed']->isNotEmpty())
                <table class="data" style="margin-top:.5rem">
                    <thead><tr><th>SLOC (wh / bin / sub-bin)</th><th>Good UPS</th><th>Good Qty</th><th>Damage UPS</th><th>Damage Qty</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($state['distributed'] as $d)
                        <tr>
                            <td>{{ $d['whid'] }} / {{ $d['binid'] }} / {{ $d['subbinid'] }}</td>
                            <td>{{ $d['ups_good'] }}</td>
                            <td>{{ $d['qty_good'] }}</td>
                            <td>{{ $d['ups_damage'] }}</td>
                            <td>{{ $d['qty_damage'] }}</td>
                            <td>
                                @if (! $arrival->isPosted())
                                    <button type="button" class="btn secondary"
                                            @click="removeLine({{ $line->arrsub_id }})">Remove line</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @empty
        <p class="muted">No lines yet — add the first one below.</p>
    @endforelse

    @if (! $arrival->isPosted())
        <h3 style="font-size:.95rem; margin-top:1rem">Add another line</h3>
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
            <div><label>UoM</label><input type="text" x-model="line.uom" readonly placeholder="from item"></div>
            @if ($type === 'vendor')
                <div><label>DC UPS</label><input type="number" min="0" x-model.number="line.ups_per_dc"></div>
                <div><label>DC Qty *</label><input type="number" min="0" step="0.001" x-model.number="line.qty_per_dc"></div>
            @endif
            <div><label>Good UPS *</label><input type="number" min="0" x-model.number="line.ups_good"></div>
            <div><label>Good Qty *</label><input type="number" min="0" step="0.001" x-model.number="line.qty_good"></div>
            <div><label>Damage UPS</label><input type="number" min="0" x-model.number="line.ups_damage"></div>
            <div><label>Damage Qty</label><input type="number" min="0" step="0.001" x-model.number="line.qty_damage"></div>
        </div>

        <div class="filters" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(9rem,1fr)); gap:1rem; margin-top:.6rem">
            <div><label>WH Id *</label><input type="number" min="1" x-model.number="manual.whid"></div>
            <div><label>Bin Id *</label><input type="number" min="1" x-model.number="manual.binid"></div>
            <div><label>Sub-bin Id *</label><input type="number" min="1" x-model.number="manual.subbin"></div>
            <div><label>Recv Good UPS</label><input type="number" min="0" x-model.number="manual.ups_good"></div>
            <div><label>Recv Good Qty</label><input type="number" min="0" step="0.001" x-model.number="manual.qty_good"></div>
            <div><label>Recv Damage UPS</label><input type="number" min="0" x-model.number="manual.ups_damage"></div>
            <div><label>Recv Damage Qty</label><input type="number" min="0" step="0.001" x-model.number="manual.qty_damage"></div>
        </div>

        <div style="margin-top:.8rem; display:flex; gap:.75rem; align-items:center">
            <button type="button" class="btn" @click="addLine()" :disabled="busy">Save line</button>
            <span class="alert error" x-show="msg" x-text="msg" style="margin:0"></span>
            <span class="alert success" x-show="ok" x-text="ok" style="margin:0"></span>
        </div>

        <form method="POST" action="{{ route('arrivals.'.$type.'.post', $arrival) }}"
              onsubmit="return confirm('Post this arrival? Stock and the party ledger will be updated.')"
              style="margin-top:1.2rem">
            @csrf
            <button class="btn" type="submit">Final post (update stock)</button>
        </form>
    @else
        <p class="muted">Posted — committed as {{ \App\Support\ArrivalNumbering::transactionId($arrival, $type) }}. Rows are immutable.</p>
    @endif

    <a class="btn secondary" href="{{ route('arrivals.'.$type.'.index') }}" style="margin-top:1rem">Back to list</a>
</div>
@endsection

@push('styles')
<style>.muted { color: var(--muted); }</style>
@endpush

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('arrivalWorkspace', () => ({
            line: { classification_id: '', items_id: '', ups_per_dc: 0, qty_per_dc: 0, ups_good: 0, qty_good: 0, ups_damage: 0, qty_damage: 0, uom: '' },
            items: [],
            availability: [],
            manual: { whid: '', binid: '', subbin: '', ups_good: 0, qty_good: 0, ups_damage: 0, qty_damage: 0 },
            busy: false,
            msg: '',
            ok: '',
            async loadItems() {
                this.items = [];
                this.line.items_id = '';
                if (! this.line.classification_id) return;
                const r = await fetch(`{{ route('eindents.items.index', ':id') }}`.replace(':id', this.line.classification_id));
                if (r.ok) this.items = await r.json();
            },
            async addLine() {
                this.busy = true; this.msg = ''; this.ok = '';
                const m = this.manual;
                if (! (Number(m.whid) > 0 && Number(m.binid) > 0 && Number(m.subbin) > 0)) {
                    this.msg = 'Enter the warehouse, bin and sub-bin ids for the location.';
                    this.busy = false;
                    return;
                }
                const payload = {
                    arrival_id: {{ $arrival->arrival_id }},
                    classification_id: this.line.classification_id,
                    items_id: this.line.items_id,
                    ups_per_dc: this.line.ups_per_dc,
                    qty_per_dc: this.line.qty_per_dc,
                    ups_good: this.line.ups_good,
                    qty_good: this.line.qty_good,
                    ups_damage: this.line.ups_damage,
                    qty_damage: this.line.qty_damage,
                    slocs: [{ whid: Number(m.whid), binid: Number(m.binid), subbin: Number(m.subbin),
                        ups_good: Number(m.ups_good), qty_good: Number(m.qty_good),
                        ups_damage: Number(m.ups_damage), qty_damage: Number(m.qty_damage) }],
                };
                const resp = await fetch('{{ route('arrivals.vendor.lines.store') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify(payload),
                });
                const body = await resp.json().catch(() => ({}));
                this.busy = false;
                if (! resp.ok) { this.msg = body.message || 'Save failed.'; return; }
                this.ok = 'Saved. Reloading…';
                window.location.reload();
            },
            async removeLine(lineId) {
                if (! confirm('Remove this line and its stock locations?')) return;
                const resp = await fetch(`{{ route('arrivals.vendor.lines.delete', ':line') }}`.replace(':line', lineId), {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                });
                if (resp.ok) window.location.reload();
            },
        }));
    });
</script>
