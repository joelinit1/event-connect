{{-- Shared by attendee-source previews, event-mapping previews and field reports. Inline styles: package views aren't in the host's Tailwind build. --}}
@php
    $cell = 'padding:4px 8px;border-bottom:1px solid rgba(127,127,127,.25);text-align:left;vertical-align:top;white-space:nowrap;max-width:240px;overflow:hidden;text-overflow:ellipsis;';
    $box = fn ($bg, $fg) => "border-radius:6px;padding:8px 12px;margin:0 0 8px;background:{$bg};color:{$fg};font-size:13px;";
@endphp
<div style="display:flex;flex-direction:column;gap:12px;font-size:13px;">
    @foreach ($problems as $message)
        <div style="{{ $box('rgba(220,38,38,.12)', 'rgb(185,28,28)') }}"><strong>Must fix:</strong> {{ $message }}</div>
    @endforeach
    @foreach ($warnings as $message)
        <div style="{{ $box('rgba(217,119,6,.12)', 'rgb(180,83,9)') }}"><strong>Check:</strong> {{ $message }}</div>
    @endforeach
    @foreach ($notes as $message)
        <div style="{{ $box('rgba(127,127,127,.12)', 'inherit') }}">{{ $message }}</div>
    @endforeach
    @if ($problems === [] && $warnings === [] && ($rows !== [] || $coverage !== []))
        <div style="{{ $box('rgba(22,163,74,.12)', 'rgb(21,128,61)') }}">No problems found.</div>
    @endif

    @if ($coverage !== [])
        <div>
            <div style="font-weight:600;margin-bottom:4px;">Fields received</div>
            <div style="overflow-x:auto;">
                <table style="border-collapse:collapse;width:100%;">
                    <thead><tr>
                        <th style="{{ $cell }}">Field</th>
                        <th style="{{ $cell }}">Filled</th>
                        @if (collect($coverage)->contains(fn ($c) => array_key_exists('target', $c)))<th style="{{ $cell }}">Mapped to</th>@endif
                    </tr></thead>
                    <tbody>
                    @foreach ($coverage as $c)
                        @php $pct = $c['total'] > 0 ? round($c['filled'] / $c['total'] * 100) : 0; @endphp
                        <tr>
                            <td style="{{ $cell }}"><code>{{ $c['field'] }}</code></td>
                            <td style="{{ $cell }}{{ $c['filled'] === 0 ? 'color:rgb(220,38,38);' : '' }}">{{ $c['filled'] }} / {{ $c['total'] }} ({{ $pct }}%)</td>
                            @if (array_key_exists('target', $c))<td style="{{ $cell }}">{!! $c['target'] !== null ? '<code>'.e($c['target']).'</code>' : '<span style="opacity:.6">not mapped</span>' !!}</td>@endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($rows !== [])
        <div>
            <div style="font-weight:600;margin-bottom:4px;">First {{ count($rows) }} row(s)</div>
            <div style="overflow-x:auto;">
                <table style="border-collapse:collapse;">
                    <thead><tr>@foreach ($columns as $column)<th style="{{ $cell }}">{{ $column }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach ($rows as $row)
                        <tr>@foreach ($columns as $column)
                            @php $value = $row[$column] ?? null; @endphp
                            <td style="{{ $cell }}" title="{{ is_scalar($value) ? $value : '' }}">{!! $value === null ? '<span style="opacity:.4">—</span>' : e(is_scalar($value) ? (is_bool($value) ? ($value ? 'yes' : 'no') : $value) : json_encode($value)) !!}</td>
                        @endforeach</tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
