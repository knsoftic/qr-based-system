{{-- One decoded field. Params: $row (provider describe() row), $depth (0 root, 1 sub-field), $editable, $behaviours --}}
@php([$label, $badge] = $behaviours[$row['behaviour']] ?? [$row['behaviour'], 'inactive'])
@php($canEdit = $depth === 0 && isset($editable[$row['tag']]))
<tr @class(['decoded-sub' => $depth > 0, 'decoded-missing' => ! $row['present']])>
    <td class="mono nowrap">{{ $row['tag'] }}</td>
    <td class="nowrap"><strong>{{ $row['name'] }}</strong></td>
    <td class="muted">{{ $row['description'] }}</td>
    <td class="decoded-value">
        @if ($canEdit)
            <input type="text" name="fields[{{ $row['tag'] }}]" value="{{ old('fields.'.$row['tag'], $row['value']) }}"
                   maxlength="{{ $editable[$row['tag']]['max'] + 10 }}" class="mono" aria-label="{{ $row['name'] }}"
                   @if ($editable[$row['tag']]['required']) required @endif>
            <div class="hint">{{ $editable[$row['tag']]['help'] }}@unless ($row['present']) Not in this QR yet.@endunless</div>
        @elseif ($row['children'])
            <span class="muted">template, {{ count($row['children']) }} sub-field(s) below</span>
        @elseif ($row['present'])
            <span class="mono">{{ $row['value'] }}</span>
            <span class="muted" style="font-size: .75rem">({{ mb_strlen($row['value']) }})</span>
        @else
            <span class="muted">not in this QR</span>
        @endif
    </td>
    <td><span class="badge badge--{{ $badge }}">{{ $label }}</span></td>
</tr>
