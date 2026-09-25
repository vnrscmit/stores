<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>QR Code Print — {{ $classification }} / {{ $itemName }}</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:Arial, sans-serif; background:white; }
        .print-controls { text-align:center; padding:12px; background:#f0f0f0; margin-bottom:16px; border-bottom:1px solid #ccc; }
        .print-btn { padding:10px 30px; background:#4ea1e1; color:white; border:none; cursor:pointer; font-weight:bold; font-size:15px; border-radius:3px; }
        .a4-page { width:210mm; height:297mm; margin:16px auto; padding:2mm; background:white; box-shadow:0 0 8px rgba(0,0,0,.12); page-break-after:always; }
        .slip-grid { display:grid; grid-template-columns:1fr 1fr; grid-template-rows:repeat(6, 1fr); gap:4mm; height:100%; }
        .qr-slip { border:2px solid #000; display:grid; grid-template-columns:15mm 1fr 90px; grid-template-rows:repeat(3, 14mm); page-break-inside:avoid; height:42mm; overflow:hidden; }
        .slip-label { font-weight:bold; color:#000; font-size:10pt; padding:1.5mm 2mm; white-space:nowrap; border-bottom:1px solid #000; display:flex; align-items:center; overflow:hidden; }
        .slip-value { color:#000; font-size:10pt; padding:1.5mm 2.5mm; word-break:break-word; border-bottom:1px solid #000; border-left:1px solid #000; display:flex; align-items:center; overflow:hidden; }
        .slip-label:nth-last-of-type(1) { border-bottom:none; }
        .slip-weight { border-bottom:none; }
        .slip-qr-column { display:flex; flex-direction:column; grid-column:3; grid-row:1 / 4; padding:1mm .5mm; gap:.4mm; align-items:center; border-left:1px solid #000; overflow:hidden; }
        .slip-qr-image { width:75px; height:75px; border:1px solid #333; flex-shrink:0; }
        .slip-qr-text { font-family:'Courier New', monospace; font-size:8pt; font-weight:bold; word-break:break-all; text-align:center; width:80px; display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .info-header { margin-bottom:8mm; padding:4mm; background:#f0f7ff; border:1px solid #4ea1e1; font-size:10pt; }
        @media print {
            .print-controls { display:none; }
            .info-header { display:none !important; }
            .a4-page { margin:0; box-shadow:none; }
            body { margin:0; }
        }
    </style>
</head>
<body>
    <div class="print-controls">
        <button class="print-btn" onclick="window.print()">Print</button>
    </div>

    @php($pages = $codes->chunk(12))
    @foreach ($pages as $page)
        <div class="a4-page">
            @if ($loop->first)
                <div class="info-header">
                    <strong>{{ $classification }}</strong> — {{ $itemName }} @if ($uom)({{ $uom }})@endif
                </div>
            @endif
            <div class="slip-grid">
                @foreach ($page as $code)
                    <div class="qr-slip">
                        <div class="slip-label">Name:</div>
                        <div class="slip-value">{{ $classification }}</div>
                        <div class="slip-label">Item:</div>
                        <div class="slip-value">{{ $itemName }}</div>
                        <div class="slip-label">Wt.:</div>
                        <div class="slip-value slip-weight">{{ $code['weight'] > 0 ? $code['weight'].' kg' : '' }}</div>
                        <div class="slip-qr-column">
                            <div class="slip-qr-image">{!! $svg($code['text']) !!}</div>
                            <div class="slip-qr-text">{{ $code['text'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</body>
</html>
