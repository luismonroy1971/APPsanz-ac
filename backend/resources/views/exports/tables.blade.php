<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 28px 30px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1f2937; }
        .header { border-bottom: 2px solid #1e3a5f; padding-bottom: 6px; margin-bottom: 10px; }
        .brand { color: #1e3a5f; font-size: 15px; font-weight: bold; }
        .brand span { color: #c9a227; }
        h1 { font-size: 13px; margin: 4px 0 0; }
        .sub { color: #6b7280; font-size: 9px; margin-top: 2px; }
        h2 { font-size: 11px; color: #1e3a5f; margin: 14px 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th { background: #1e3a5f; color: #fff; padding: 4px 5px; text-align: left; font-size: 8.5px; }
        td { padding: 3px 5px; border-bottom: 1px solid #e5e7eb; }
        tr:nth-child(even) td { background: #f8fafc; }
        .num { text-align: right; white-space: nowrap; }
        .total td { font-weight: bold; border-top: 1.5px solid #1e3a5f; background: #eef2f7; }
        .summary { margin: 6px 0 10px; }
        .summary td { border: 1px solid #e5e7eb; text-align: center; padding: 6px; }
        .summary .v { font-size: 13px; font-weight: bold; color: #1e3a5f; }
        .footer { position: fixed; bottom: -14px; left: 0; right: 0; font-size: 7.5px; color: #9ca3af; text-align: right; }
    </style>
</head>
<body>
<div class="header">
    <div class="brand">SANZ <span>&amp;</span> AC</div>
    <h1>{{ $title }}</h1>
    @if(!empty($subtitle))<div class="sub">{{ $subtitle }}</div>@endif
</div>

@if(!empty($summary))
    <table class="summary"><tr>
        @foreach($summary as $s)
            <td><div class="v">{{ is_numeric($s['value']) ? number_format((float) $s['value'], (($s['format'] ?? null) === 'money' || fmod((float) $s['value'], 1) != 0) ? 2 : 0) : $s['value'] }}{{ ($s['format'] ?? null) === 'percent' ? '%' : '' }}</div>{{ $s['label'] }}</td>
        @endforeach
    </tr></table>
@endif

@foreach($tables as $t)
    @if(!empty($t['title']))<h2>{{ $t['title'] }}</h2>@endif
    <table>
        <thead><tr>@foreach($t['columns'] as $c)<th class="{{ in_array($c['type'] ?? '', ['number','money','percent']) ? 'num' : '' }}">{{ $c['label'] }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse($t['rows'] as $r)
            <tr class="{{ !empty($r['_bold']) ? 'total' : '' }}">
                @foreach($t['columns'] as $c)
                    @php $v = $r[$c['key']] ?? null; $type = $c['type'] ?? null; @endphp
                    <td class="{{ in_array($type, ['number','money','percent']) ? 'num' : '' }}">
                        @if($type === 'money' && is_numeric($v)){{ number_format((float) $v, 2) }}
                        @elseif($type === 'percent' && is_numeric($v)){{ number_format((float) $v, 1) }}%
                        @else{{ is_array($v) ? '' : $v }}@endif
                    </td>
                @endforeach
            </tr>
        @empty
            <tr><td colspan="{{ count($t['columns']) }}" style="text-align:center;color:#9ca3af">Sin registros</td></tr>
        @endforelse
        @if(!empty($t['totals']))
            <tr class="total">
                @foreach($t['columns'] as $i => $c)
                    @php $v = $t['totals'][$c['key']] ?? ''; @endphp
                    <td class="{{ is_numeric($v) ? 'num' : '' }}">{{ $i === 0 ? 'TOTAL' : (is_numeric($v) ? number_format((float) $v, 2) : $v) }}</td>
                @endforeach
            </tr>
        @endif
        </tbody>
    </table>
@endforeach

<div class="footer">APPsanz&amp;ac · Generado el {{ now()->format('d/m/Y H:i') }}</div>
</body>
</html>
