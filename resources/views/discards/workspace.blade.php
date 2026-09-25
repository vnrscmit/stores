@extends('layouts.app')

@section('title', 'Material Discard — workspace')

@section('content')
<div class="card" x-data="discardWorkspace()">
    <h2>Material Discard — TMD{{ $discard->tcode }}/{{ $discard->yearcode }}</h2>

    <table class="data" style="margin-bottom:1rem">
        <tr>
            <th>Transaction Id</th>
            <td><strong>TMD{{ $discard->tcode }}/{{ $discard->yearcode }}/{{ $discard->ddrole }}</strong></td>
            <th>Discard Date</th>
            <td>{{ $discard->tdate ? \Illuminate\Support\Carbon::parse($discard->tdate)->format('d-m-Y') : '—' }}</td>
        </tr>
        <tr>
            <th>Discard Inst. Ref. No.</th>
            <td>{{ $discard->drno }}</td>
            <th>Party</th>
            <td>{{ $discard->party_name }}</td>
        </tr>
        <tr>
            <th>Return status</th>
            <td>{{ $discard->rettyp }}</td>
            <th>Stage</th>
            <td><span class="badge">{{ (int) $discard->ddflg === 1 ? 'Posted' : 'Open' }}</span></td>
        </tr>
        @if ($discard->remarks)
            <tr><th>Remarks</th><td colspan="3">{{ $discard->remarks }}</td></tr>
        @endif
    </table>

    <h3 style="font-size:.95rem">Discard lines</h3>
    @forelse ($lines as $line)
        <div style="border:1px solid var(--line); border-radius:.5rem; padding:.8rem; margin-bottom:.8rem">
            <div style="display:flex; gap:1rem; align-items:center; flex-wrap:wrap">
                <strong>{{ $line['item_name'] }}</strong>
                <span class="muted">UoM {{ $line['uom'] }}</span>
                <span class="muted">Discard {{ $line['ups'] }} / {{ $line['qty'] }} {{ $line['uom'] }}</span>
            </div>
            <table class="data" style="margin-top:.5rem">
                <thead>
                <tr>
                    <th>SLOC (wh / bin / sub-bin)</th>
                    <th>Discard UPS</th>
                    <th>Discard Qty</th>
                    <th>Balance UPS</th>
                    <th>Balance Qty</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($line['rows'] as $row)
                    <tr>
                        <td>{{ $row['whid'] }} / {{ $row['binid'] }} / {{ $row['subbinid'] }}</td>
                        <td>{{ $row['ups_discard'] }}</td>
                        <td>{{ $row['qty_discard'] }}</td>
                        <td>{{ $row['ups_balance'] }}</td>
                        <td>{{ $row['qty_balance'] }}</td>
                        <td>
                            @if ((int) $discard->ddflg !== 1)
                                <button type="button" class="btn secondary"
                                        @click="removeLine({{ $line['did'] }})">Remove line</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <p class="muted">No discard lines yet — add the first one below.</p>
    @endforelse

    @if ((int) $discard->ddflg !== 1)
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
            <div><label>UoM</label><input type="text" :value="uom" readonly placeholder="from item"></div>
        </div>

        <table class="data" x-show="rows.length" style="margin-top:.6rem">
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
            <template x-for="row in rows" :key="row.stld_id">
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

        <div style="margin-top:.8rem; display:flex; gap:.75rem; align-items:center">
            <button type="button" class="btn" @click="addLine()" :disabled="busy">Save line</button>
            <span class="alert error" x-show="msg" x-text="msg" style="margin:0"></span>
            <span class="alert success" x-show="ok" x-text="ok" style="margin:0"></span>
        </div>

        <form method="POST" action="{{ route('discards.post', $discard) }}"
              onsubmit="return confirm('Post this discard? Stock will be updated.')"
              style="margin-top:1.2rem">
            @csrf
            <button class="btn" type="submit">Final post (update stock)</button>
        </form>
    @else
        <p class="muted">Posted — committed as {{ \App\Support\DocumentNumber::pretty('discard', (int) $discard->dd_code, (string) $discard->yearcode) }}. Lines are immutable.</p>
    @endif

    <a class="btn secondary" href="{{ route('discards.index') }}" style="margin-top:1rem">Back to list</a>
</div>
@endsection

@push('styles')
<style>.muted { color: var(--muted); }</style>
@endpush

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('discardWorkspace', () => ({
            line: { classification_id: '', items_id: '' },
            items: [],
            rows: [],
            busy: false,
            msg: '',
            ok: '',
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
            async addLine() {
                this.busy = true; this.msg = ''; this.ok = '';
                const rows = this.rows.filter((r) => Number(r.qty_discard) > 0 || Number(r.ups_discard) > 0);
                if (! rows.length) { this.msg = 'Enter a discard quantity on at least one SLOC row.'; this.busy = false; return; }
                const payload = {
                    tdate: '{{ $discard->tdate ? \Illuminate\Support\Carbon::parse($discard->tdate)->toDateString() : '' }}',
                    drno: {{ json_encode((string) $discard->drno) }},
                    party_name: {{ json_encode((string) $discard->party_name) }},
                    discard_id: {{ $discard->tid }},
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
                this.ok = 'Saved. Reloading…';
                window.location.reload();
            },
            async removeLine(did) {
                if (! confirm('Remove this discard line?')) return;
                const resp = await fetch(`{{ route('discards.lines.delete', ':did') }}`.replace(':did', did), {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ discard_id: {{ $discard->tid }} }),
                });
                if (resp.ok) window.location.reload();
            },
        }));
    });
</script>
