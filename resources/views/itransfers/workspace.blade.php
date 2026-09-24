@extends('layouts.app')

@section('title', 'Inter Item Transfer — workspace')

@section('content')
<div class="card" x-data="itransferWorkspace()">
    <h2>Inter Item Transfer — TIT{{ $transfer->iitr_id }}/{{ $transfer->yearcode }}</h2>

    <table class="data" style="margin-bottom:1rem">
        <tr>
            <th>Transaction Id</th>
            <td><strong>TIT{{ $transfer->iitr_id }}/{{ $transfer->yearcode }}</strong></td>
            <th>Date</th>
            <td>{{ $transfer->tdate ? \Illuminate\Support\Carbon::parse($transfer->tdate)->format('d-m-Y') : '—' }}</td>
        </tr>
        <tr>
            <th>Source Item</th>
            <td>{{ \App\Models\Item::find($transfer->items_id_from)?->stores_item ?? '—' }}</td>
            <th>Stage</th>
            <td><span class="badge">{{ \App\Support\ArrivalStatus::LABELS[$transfer->status] ?? $transfer->status }}</span></td>
        </tr>
        @if ($transfer->remarks)
            <tr><th>Remarks</th><td colspan="3">{{ $transfer->remarks }}</td></tr>
        @endif
    </table>

    <h3 style="font-size:.95rem">Conversions</h3>
    @forelse ($groups as $group)
        <div style="border:1px solid var(--line); border-radius:.5rem; padding:.8rem; margin-bottom:.8rem">
            <div style="display:flex; gap:1rem; align-items:center; flex-wrap:wrap">
                <strong>Source row #{{ $group['rowid'] }}</strong>
                @if ($group['source'])
                    <span>{{ $group['source']['item_name'] }}</span>
                    <span class="muted">SLOC {{ $group['source']['whid'] }} / {{ $group['source']['binid'] }} / {{ $group['source']['subbinid'] }}</span>
                    <span class="muted">Balance {{ $group['source']['ups'] }} / {{ $group['source']['qty'] }}</span>
                @endif
            </div>
            <table class="data" style="margin-top:.5rem">
                <thead>
                <tr><th>Item</th><th>SLOC (wh / bin / sub-bin)</th><th>UPS</th><th>Qty</th><th></th></tr>
                </thead>
                <tbody>
                @foreach ($group['lines'] as $line)
                    <tr>
                        <td>{{ $line['item_name'] }}</td>
                        <td>{{ $line['whid'] }} / {{ $line['binid'] }} / {{ $line['subbinid'] }}</td>
                        <td>{{ $line['ups_to'] }}</td>
                        <td>{{ $line['qty_to'] }}</td>
                        <td>
                            @if ((int) $transfer->iitrflg !== 1)
                                <button type="button" class="btn secondary"
                                        @click="removeGroup({{ $group['rowid'] }})">Remove row</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <p class="muted">No destination lines yet — add the first one below.</p>
    @endforelse

    @if ((int) $transfer->iitrflg !== 1)
        <h3 style="font-size:.95rem; margin-top:1rem">Add another conversion</h3>
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
            <div><label>Source row</label>
                <select x-model.number="source.rowid" :disabled="! sources.length">
                    <option value="">-- Select SLOC --</option>
                    <template x-for="loc in sources" :key="loc.stlg_id">
                        <option :value="loc.stlg_id" x-text="`${loc.whid} / ${loc.binid} / ${loc.subbinid} — ${loc.ups} / ${loc.qty}`"></option>
                    </template>
                </select>
            </div>
            <div><label>UoM</label><input type="text" :value="sourceUom" readonly placeholder="from item"></div>
        </div>

        <div class="filters" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(11rem,1fr)); gap:1rem; margin-top:.6rem">
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
            <div><label>WH Id *</label><input type="number" min="1" x-model.number="target.whid"></div>
            <div><label>Bin Id *</label><input type="number" min="1" x-model.number="target.binid"></div>
            <div><label>Sub-bin Id *</label><input type="number" min="1" x-model.number="target.subbin"></div>
            <div><label>Transfer UPS *</label><input type="number" min="0" x-model.number="target.ups_to"></div>
            <div><label>Transfer Qty *</label><input type="number" min="0" step="0.001" x-model.number="target.qty_to"></div>
        </div>

        <div style="margin-top:.8rem; display:flex; gap:.75rem; align-items:center">
            <button type="button" class="btn" @click="addLine()" :disabled="busy">Save line</button>
            <span class="alert error" x-show="msg" x-text="msg" style="margin:0"></span>
            <span class="alert success" x-show="ok" x-text="ok" style="margin:0"></span>
        </div>

        <form method="POST" action="{{ route('itransfers.post', $transfer) }}"
              onsubmit="return confirm('Post this inter-item transfer? Stock will be updated.')"
              style="margin-top:1.2rem">
            @csrf
            <button class="btn" type="submit">Final post (update stock)</button>
        </form>
    @else
        <p class="muted">Posted — committed as {{ \App\Support\DocumentNumber::pretty('iitr', (int) $transfer->iitr_code, (string) $transfer->yearcode) }}. Rows are immutable.</p>
    @endif

    <a class="btn secondary" href="{{ route('itransfers.index') }}" style="margin-top:1rem">Back to list</a>
</div>
@endsection

@push('styles')
<style>.muted { color: var(--muted); }</style>
@endpush

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('itransferWorkspace', () => ({
            header: { tdate: '{{ optional($transfer->tdate)->toDateString() }}', remarks: {{ json_encode((string) $transfer->remarks) }} },
            source: { classification_id: '', items_id: '', rowid: '' },
            target: { classification_id: '', items_id: '', uom: '', whid: '', binid: '', subbin: '', ups_to: 0, qty_to: 0 },
            sourceItems: [],
            destItems: [],
            sources: [],
            busy: false,
            msg: '',
            ok: '',
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
            async addLine() {
                this.busy = true; this.msg = ''; this.ok = '';
                if (! this.source.rowid) { this.msg = 'Select the source stock location.'; this.busy = false; return; }
                const src = this.sources.find((l) => Number(l.stlg_id) === Number(this.source.rowid)) || { ups: 0, qty: 0 };
                const payload = {
                    ...this.header,
                    transfer_id: {{ $transfer->iitr_id }},
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
                this.ok = 'Saved. Reloading…';
                window.location.reload();
            },
            async removeGroup(rowid) {
                if (! confirm('Remove all destination rows for this source row?')) return;
                const resp = await fetch(`{{ route('itransfers.lines.delete', ':rowid') }}`.replace(':rowid', rowid), {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ transfer_id: {{ $transfer->iitr_id }} }),
                });
                if (resp.ok) window.location.reload();
            },
        }));
    });
</script>
