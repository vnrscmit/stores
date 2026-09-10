@extends('layouts.app')

@section('title', 'Issue workspace — indent '.$indent->code)

@php
    // Client passes stlg_id keys per line; keep payloads small here and let
    // the server resolve locations (ids are verified server-side).
@endphp

@section('content')
<div class="card">
    <h2>Issue against {{ \App\Support\IndentNumbering::committedId($indent) }}</h2>

    <table class="data" style="margin-bottom:1rem">
        <tr>
            <th>Transaction Id</th>
            <td><strong>{{ $issue->transactionId() }}</strong></td>
            <th>Issue Date</th>
            <td>{{ optional($issue->issue_date)->format('d-m-Y') }}</td>
        </tr>
        <tr>
            <th>Indent No</th>
            <td>{{ $indent->code }}</td>
            <th>Indent Date</th>
            <td>{{ optional($indent->tdate)->format('d-m-Y') }}</td>
        </tr>
        <tr>
            <th>Raised By</th>
            <td>{{ $indent->raiser?->login }}</td>
            <th>Stage</th>
            <td>{{ \App\Support\EIssueStatus::LABELS[$issue->status] }}</td>
        </tr>
    </table>

    @foreach ($lines as $state)
        @php($line = $state['line'])
        <div style="border:1px solid var(--line); border-radius:.5rem; padding:1rem; margin-bottom:1rem"
             x-data="issueLine({{ $indent->tid }}, {{ $line->eid }}, {{ $state['remaining_qty'] }})">

            <div style="display:flex; gap:1rem; align-items:center; margin-bottom:.6rem">
                <strong>#{{ $loop->iteration }}</strong>
                <span>{{ $line->classification?->classification ?? '—' }} / {{ $line->item?->stores_item ?? '—' }}</span>
                <span class="muted">UoM {{ $line->uom }}</span>
                <span class="muted">Indent qty: {{ $line->qty }}</span>
                <span class="badge" x-text="remaining > 0 ? 'Remaining: '+remaining : 'Fully distributed'"></span>
            </div>

            {{-- Already-distributed rows in this workspace --}}
            @if ($state['distributed']->isNotEmpty())
                <table class="data" style="margin-bottom:.6rem">
                    <thead>
                    <tr><th>Saved SLOC distribution</th><th>UPS</th><th>Qty</th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach ($state['distributed'] as $d)
                        <tr>
                            <td>{{ $d['whid'] }} / {{ $d['binid'] }} / {{ $d['subbinid'] }}</td>
                            <td>{{ $d['ups'] }}</td>
                            <td>{{ $d['qty'] }}</td>
                            <td>
                                @if (! $issue->isPosted())
                                    <button type="button" class="btn secondary"
                                            @click="removeLine()">Remove</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif

            @if ($state['distributed_elsewhere']->isNotEmpty())
                <div class="alert error">
                    Parts of this line are reserved in another open workspace:
                    {{ $state['distributed_elsewhere']->sum('qty') }} ({{ \App\Support\FiscalYear::yearcode() }}).
                </div>
            @endif

            @if (! $issue->isPosted() && $state['remaining_qty'] > 0)
                <h3 style="font-size:.92rem; margin:.4rem 0">Distribute remaining quantity</h3>
                <table class="data">
                    <thead>
                    <tr>
                        <th>Use</th>
                        <th>SLOC (wh / bin / sub-bin)</th>
                        <th>Stock UPS</th>
                        <th>Stock Qty</th>
                        <th>Issue UPS</th>
                        <th>Issue Qty</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($state['available'] as $loc)
                        <tr>
                            <td><input type="checkbox" :value="{{ $loc['stlg_id'] }}" x-model.number="sel"></td>
                            <td>{{ $loc['whid'] }} / {{ $loc['binid'] }} / {{ $loc['subbinid'] }}</td>
                            <td>{{ $loc['ups'] }}</td>
                            <td>{{ $loc['qty'] }}</td>
                            <td><input type="number" min="0" step="1" x-model.number="rows['{{ $loc['key'] }}'].ups" style="width:6rem"></td>
                            <td><input type="number" min="0" step="0.001" x-model.number="rows['{{ $loc['key'] }}'].qty" style="width:7rem"></td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No stock locations hold this item.</td></tr>
                    @endforelse
                    </tbody>
                </table>

                <div style="margin-top:.6rem; display:flex; gap:.75rem; align-items:center">
                    <button type="button" class="btn" @click="save()" :disabled="busy">Save distribution</button>
                    <span class="alert error" x-show="msg" x-text="msg" style="margin:0"></span>
                    <span class="alert success" x-show="ok" x-text="ok" style="margin:0"></span>
                </div>
            @elseif ($issue->isPosted())
                <span class="muted">Posted — rows are immutable.</span>
            @endif
        </div>
    @endforeach

    <div style="display:flex; gap:.75rem; margin-top:1rem">
        @if (! $issue->isPosted())
            <form method="POST" action="{{ route('issues.eindents.post', $indent) }}"
                  onsubmit="return confirm('Post this issue? Stock will be updated and the indent closed.')">
                @csrf
                <button class="btn" type="submit">Final post (update stock + close indent)</button>
            </form>
        @endif
        <a class="btn secondary" href="{{ route('issues.eindents.pending') }}">Back to pending queue</a>
    </div>
</div>

@push('styles')
<style>
    .muted { color: var(--muted); }
</style>
@endpush

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('issueLine', (indentId, lineId, remainingStart) => ({
            sel: [],
            rows: {},
            remaining: remainingStart,
            busy: false,
            msg: '',
            ok: '',
            init() {
                document.querySelectorAll('input[type=checkbox]').forEach((cb) => {
                    cb.addEventListener('change', () => {
                        const key = cb.closest('tr').children[1].textContent.trim();
                        if (!(key in this.rows)) {
                            this.rows[key] = { key, ups: 0, qty: 0 };
                        }
                    });
                });
            },
            payload() {
                const slocs = [];
                this.sel.forEach((stlgId) => {
                    const key = this.keyFor(stlgId);
                    const r = this.rows[key];
                    if (r && (r.qty > 0 || r.ups > 0)) {
                        slocs.push({ stlg_id: stlgId, ups: r.ups, qty: r.qty });
                    }
                });
                return slocs;
            },
            keyFor(stlgId) {
                let found = '';
                document.querySelectorAll('input[type=checkbox]').forEach((cb) => {
                    if (Number(cb.value) === Number(stlgId)) {
                        found = cb.closest('tr').children[1].textContent.trim();
                    }
                });
                return found;
            },
            async save() {
                this.busy = true; this.msg = ''; this.ok = '';
                const resp = await fetch('{{ route("issues.eindents.lines.save", $indent) }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ line: lineId, slocs: this.payload() }),
                });
                const body = await resp.json().catch(() => ({}));
                this.busy = false;
                if (!resp.ok) {
                    this.msg = body.message || 'Save failed.';
                    return;
                }
                this.ok = 'Saved. Reloading…';
                window.location.reload();
            },
            async removeLine() {
                this.busy = true;
                const resp = await fetch('{{ route("issues.eindents.lines.delete", $indent) }}', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ line: lineId }),
                });
                this.busy = false;
                if (resp.ok) window.location.reload();
            },
        }));
    });
</script>
@endsection
