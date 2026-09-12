@extends('layouts.app')

@section('title', $meta['label'].' — workspace')

@section('content')
<div class="card" x-data="issueWorkspace()">
    <h2>{{ $meta['label'] }} — {{ $issue->transactionId() }}</h2>

    <table class="data" style="margin-bottom:1rem">
        <tr>
            <th>Transaction Id</th>
            <td><strong>{{ $issue->transactionId() }}</strong></td>
            <th>Date</th>
            <td>{{ optional($issue->issue_date)->format('d-m-Y') }}</td>
        </tr>
        <tr>
            @if ($type === 'pindent')
                <th>Physical Indent No</th><td>{{ $issue->dcrefno }}</td>
                <th>Raised By</th><td>{{ $issue->strefno }}</td>
            @elseif ($type === 'stocktr')
                <th>Transfer Ref No</th><td>{{ $issue->strefno }}</td>
                <th>Transfer Date</th><td>{{ optional($issue->strdate)->format('d-m-Y') }}</td>
            @else
                <th>Party DC Ref No</th><td>{{ $issue->dcrefno }}</td>
                <th>Party</th><td>{{ $issue->party_id }}</td>
            @endif
        </tr>
        <tr>
            <th>Mode of Transit</th><td>{{ $issue->tmode }}</td>
            <th>Stage</th>
            <td><span class="badge">{{ \App\Support\EIssueStatus::LABELS[$issue->status] ?? $issue->status }}</span></td>
        </tr>
        @if ($issue->tmode === 'Transport')
            <tr><th>Transport</th><td>{{ $issue->trans_name }} (LR {{ $issue->trans_lorryrepno }})</td>
                <th>Vehicle / Payment</th><td>{{ $issue->trans_vehno }} / {{ $issue->trans_paymode }}</td></tr>
        @elseif ($issue->tmode === 'Courier')
            <tr><th>Courier</th><td>{{ $issue->courier_name }}</td><th>Docket No</th><td>{{ $issue->docket_no }}</td></tr>
        @elseif ($issue->tmode === 'By Hand')
            <tr><th>Name of Person</th><td colspan="3">{{ $issue->pname_byhand }}</td></tr>
        @endif
        @if ($issue->remarks)
            <tr><th>Remarks</th><td colspan="3">{{ $issue->remarks }}</td></tr>
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
                <span class="muted">UPS {{ $line->ups_indent }}</span>
                <span class="muted">Qty {{ $line->qty_indent }}</span>
            </div>
            @if ($state['distributed']->isNotEmpty())
                <table class="data" style="margin-top:.5rem">
                    <thead><tr><th>SLOC (wh / bin / sub-bin)</th><th>UPS</th><th>Qty</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($state['distributed'] as $d)
                        <tr>
                            <td>{{ $d['whid'] }} / {{ $d['binid'] }} / {{ $d['subbinid'] }}</td>
                            <td>{{ $d['ups'] }}</td>
                            <td>{{ $d['qty'] }}</td>
                            <td>
                                @if (! $issue->isPosted())
                                    <button type="button" class="btn secondary"
                                            @click="removeLine({{ $line->issuesub_id }})">Remove line</button>
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

    @if (! $issue->isPosted())
        <h3 style="font-size:.95rem; margin-top:1rem">Add another line</h3>
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

        <table class="data" x-show="availability.length" style="margin-top:.8rem">
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

        <div style="margin-top:.8rem; display:flex; gap:.75rem; align-items:center">
            <button type="button" class="btn" @click="addLine()" :disabled="busy">Save line</button>
            <span class="alert error" x-show="msg" x-text="msg" style="margin:0"></span>
            <span class="alert success" x-show="ok" x-text="ok" style="margin:0"></span>
        </div>

        <form method="POST" action="{{ route('issues.'.$type.'.post', $issue) }}"
              onsubmit="return confirm('Post this issue? Stock will be updated.')"
              style="margin-top:1.2rem">
            @csrf
            <button class="btn" type="submit">Final post (update stock)</button>
        </form>
    @else
        <p class="muted">Posted — committed as {{ \App\Support\IssueNumbering::committedId($issue) }}. Rows are immutable.</p>
    @endif

    <a class="btn secondary" href="{{ route('issues.'.$type.'.index') }}" style="margin-top:1rem">Back to list</a>
</div>
@endsection

@push('styles')
<style>.muted { color: var(--muted); }</style>
@endpush

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('issueWorkspace', () => ({
            line: { classification_id: '', items_id: '', ups: 0, qty: 0, uom: '' },
            items: [],
            availability: [],
            sel: [],
            rows: {},
            busy: false,
            msg: '',
            ok: '',
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
            async addLine() {
                this.busy = true; this.msg = ''; this.ok = '';
                const payload = {
                    issue_id: {{ $issue->issue_id }},
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
                this.ok = 'Saved. Reloading…';
                window.location.reload();
            },
            async removeLine(lineId) {
                if (! confirm('Remove this line and its distribution?')) return;
                const resp = await fetch(`{{ route('issues.pindent.lines.delete', ':line') }}`.replace(':line', lineId), {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                });
                if (resp.ok) window.location.reload();
            },
        }));
    });
</script>
@endsection
