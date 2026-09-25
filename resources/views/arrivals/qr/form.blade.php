@extends('layouts.app')

@section('title', 'Generate QR codes')

@section('content')
<div class="card" x-data="qrGenerate()">
    <h2>Generate QR codes</h2>

    <table class="data" style="margin-bottom:1rem">
        <tr>
            <th>Mode</th>
            <td>
                @if ($arrival !== null && $line !== null)
                    Linked — arrival #{{ $arrival->arrival_id }}, line #{{ $line->arrsub_id }}
                @else
                    Draft — codes link when the arrival is posted
                @endif
            </td>
            <th>Prefix</th>
            <td><code>{{ $prefix }}</code></td>
        </tr>
        <tr>
            <th>Classification</th>
            <td>{{ $classification->classification }}</td>
            <th>Item</th>
            <td>{{ $item->stores_item }} ({{ $item->uom }})</td>
        </tr>
        <tr>
            <th>Good UPS</th>
            <td>{{ $upsGood }} codes</td>
            <th>Serial start</th>
            <td>{{ str_pad((string) $serialStart, 5, '0', STR_PAD_LEFT) }}</td>
        </tr>
    </table>

    <p class="muted">
        One QR per good UPS. Enter each code's weighed value — saving a linked batch
        overwrites the line's good quantity with the total weight (legacy behaviour);
        a draft batch replaces any earlier draft codes for this item.
    </p>

    <table class="data" style="margin-top:1rem">
        <thead>
        <tr>
            <th>SR No</th>
            <th>QR Code Text</th>
            <th>Weight (kg)</th>
        </tr>
        </thead>
        <tbody>
        <template x-for="(code, index) in codes" :key="code.serial">
            <tr>
                <td x-text="code.serial"></td>
                <td><code x-text="code.text"></code></td>
                <td><input type="number" min="0" step="0.01" x-model.number="code.weight"></td>
            </tr>
        </template>
        </tbody>
    </table>

    <div style="margin-top:1rem; display:flex; gap:.5rem; align-items:center">
        <button class="btn" type="button" @click="save()">Save batch</button>
        <button class="btn secondary" type="button" @click="print()">Print sheet</button>
        <span class="muted" x-show="error" x-text="error" style="color:#b00"></span>
    </div>
</div>

<script>
function qrGenerate() {
    return {
        prefix: '{{ $prefix }}',
        serialStart: {{ $serialStart }},
        codes: [],
        error: '',

        init() {
            for (let i = 0; i < {{ $upsGood }}; i++) {
                const serial = String(this.serialStart + i).padStart(5, '0');
                this.codes.push({ serial: serial, text: this.prefix + serial, weight: 0 });
            }
        },

        payload() {
            return {
                classification_id: {{ $classification->classification_id }},
                item_id: {{ $item->items_id }},
                ups_good: {{ $upsGood }},
                serial_start: this.serialStart,
                codes: this.codes.map((c) => ({ text: c.text, weight: c.weight })),
            };
        },

        async save() {
            this.error = '';
            const unfilled = this.codes.filter((c) => !(Number(c.weight) > 0)).length;
            if (unfilled > 0) {
                this.error = 'Enter a weight for every code before saving.';
                return;
            }
            const resp = await fetch('{{ $arrival !== null && $line !== null
                ? route('arrivals.vendor.qr.save-linked', [$arrival, $line])
                : route('arrivals.vendor.qr.save') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: JSON.stringify(this.payload()),
            });
            const body = await resp.json().catch(() => ({}));
            if (!resp.ok || !body.ok) {
                this.error = body.message ?? body.error ?? 'Save failed.';
                return;
            }
            this.error = '';
            alert('Saved ' + body.count + ' QR codes. Total weight: ' + Number(body.total_weight).toFixed(2) + ' kg');
        },

        print() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '{{ route('arrivals.vendor.qr.print') }}';
            form.target = '_blank';

            const csrf = document.createElement('input');
            csrf.type = 'hidden';
            csrf.name = '_token';
            csrf.value = document.querySelector('meta[name=csrf-token]').content;
            form.appendChild(csrf);

            const add = (name, value) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            };

            add('classification', '{{ $classification->classification }}');
            add('item_name', '{{ $item->stores_item }}');
            add('uom', '{{ $item->uom }}');
            add('type_code', '{{ $typeCode }}');
            this.codes.forEach((c, i) => {
                add('codes[' + i + '][text]', c.text);
                add('codes[' + i + '][weight]', c.weight ?? 0);
            });

            document.body.appendChild(form);
            form.submit();
            form.remove();
        },
    };
}
</script>
@endsection
