{{-- Dashboard charts: paid volume (columns) and success rate (line), one axis each. --}}
@php
    $b = $chart['buckets'];
    $n = count($b);
    $every = max(1, (int) ceil($n / 6));
    // Hours label from midnight (12 AM, 4 AM, …); days and months from the latest bucket back.
    $showX = fn (int $i) => ($chart['unit'] === 'hour' ? $i : $n - 1 - $i) % $every === 0;
    $compact = $chart['top'] >= 10000;
    $tick = function (float $v) use ($compact): string {
        if (! $compact || $v == 0) {
            return number_format($v);
        }
        return $v >= 1000000
            ? rtrim(rtrim(number_format($v / 1000000, 1), '0'), '.').'M'
            : rtrim(rtrim(number_format($v / 1000, 1), '0'), '.').'K';
    };
    // X labels sit under their column's centre; the script hides any that would touch a neighbour.
    $xLabels = [];
    foreach ($b as $i => $x) {
        if ($showX($i)) {
            $xLabels[] = ['left: '.round(($i + 0.5) / $n * 100, 3).'%', $x['short']];
        }
    }
    $overall = $chart['orders'] ? round($chart['paid'] / $chart['orders'] * 100, 1) : null;

    // Success-rate line in a 0–100 viewBox, broken where a bucket has no orders.
    $path = '';
    $pen = false;
    $dots = [];
    foreach ($b as $i => $x) {
        if ($x['rate'] === null) {
            $pen = false;
            continue;
        }
        $px = round(($i + 0.5) / $n * 100, 3);
        $py = round(100 - $x['rate'], 3);
        $path .= ($pen ? 'L' : 'M').$px.' '.$py.' ';
        $isolated = ($b[$i - 1]['rate'] ?? null) === null && ($b[$i + 1]['rate'] ?? null) === null;
        if ($isolated) {
            $dots[] = [$px, $x['rate']];
        }
        $pen = true;
        $lastDot = [$px, $x['rate']];
    }
    if (isset($lastDot)) {
        $dots[] = $lastDot;
    }
@endphp

<style>
    .mc-pair { display: grid; grid-template-columns: 1fr; gap: 1.4rem; margin-top: 1.4rem; }
    @media (min-width: 900px) { .mc-pair { grid-template-columns: 1fr 1fr; } }
    .mc-pair .card { margin: 0; }
    .mc-sub { color: var(--muted); font-size: .8rem; margin-top: .15rem; }
    .mc { padding-top: .25rem; }
    .mc-plot { position: relative; height: 180px; margin: 0 14px 0 46px; }
    .mc-grid { position: absolute; left: 0; right: 0; border-top: 1px solid var(--border); }
    .mc-grid span { position: absolute; right: 100%; margin-right: 8px; transform: translateY(-50%); font-size: .7rem; color: var(--muted); white-space: nowrap; font-variant-numeric: tabular-nums; }
    .mc-cols { position: absolute; inset: 0; display: flex; align-items: flex-end; gap: 2px; }
    .mc-col { flex: 1; height: 100%; min-width: 0; display: flex; align-items: flex-end; justify-content: center; padding: 0; border: 0; background: none; cursor: default; }
    .mc-col:focus-visible { outline: 2px solid var(--primary-ring); outline-offset: 1px; border-radius: 4px; }
    .mc-bar { display: block; width: min(24px, 100%); background: var(--primary); border-radius: 4px 4px 0 0; }
    .mc-col:hover .mc-bar, .mc-col:focus-visible .mc-bar { background: #6366f1; }
    .mc-svg { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; }
    .mc-dot { position: absolute; width: 8px; height: 8px; margin: 0 0 -4px -4px; border-radius: 50%; background: var(--primary); box-shadow: 0 0 0 2px var(--surface); pointer-events: none; }
    .mc-cross { position: absolute; top: 0; bottom: 0; width: 1px; background: var(--border-strong); pointer-events: none; }
    .mc-tip { position: absolute; top: 0; z-index: 2; padding: .4rem .6rem; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 8px 20px -10px rgba(15, 23, 42, .35); pointer-events: none; white-space: nowrap; }
    .mc-tip strong { display: block; color: var(--text); font-size: .9rem; font-variant-numeric: tabular-nums; }
    .mc-tip span { color: var(--muted); font-size: .75rem; }
    .mc-x { position: relative; height: 1.1rem; margin: .4rem 14px 0 46px; }
    .mc-x span { position: absolute; top: 0; transform: translateX(-50%); font-size: .7rem; color: var(--muted); white-space: nowrap; }
    .mc-table { margin-top: 1rem; }
    .mc-table summary { cursor: pointer; color: var(--muted); font-size: .85rem; }
</style>

@if ($chart['orders'] === 0)
    <div class="card" style="margin-top: 1.4rem">
        <div class="card-body"><p class="muted mb-0">No payments in {{ strtolower($periodLabel) }} yet, so there is nothing to chart. Try a longer period above.</p></div>
    </div>
@else
    <div class="mc-pair">
        <div class="card">
            <div class="card-head">
                <div>
                    <h2>Paid volume</h2>
                    <div class="mc-sub">PKR {{ number_format($chart['volume'], 2) }} · {{ $periodLabel }}</div>
                </div>
            </div>
            <div class="card-body">
                <div class="mc" data-mc>
                    <div class="mc-plot">
                        @foreach ($chart['ticks'] as $t)
                            <div class="mc-grid" style="bottom: {{ round($t / $chart['top'] * 100, 3) }}%"><span>{{ $tick($t) }}</span></div>
                        @endforeach
                        <div class="mc-cols">
                            @foreach ($b as $x)
                                @if ($x['future'])
                                    <span class="mc-col" aria-hidden="true"></span>
                                    @continue
                                @endif
                                <button type="button" class="mc-col"
                                        data-tip-value="PKR {{ number_format($x['volume'], 2) }}"
                                        data-tip-label="{{ $x['long'] }} · {{ $x['paid'] }} paid of {{ $x['orders'] }}"
                                        aria-label="{{ $x['long'] }}: PKR {{ number_format($x['volume'], 2) }}, {{ $x['paid'] }} paid of {{ $x['orders'] }} orders">
                                    <span class="mc-bar" style="height: {{ round($x['volume'] / $chart['top'] * 100, 3) }}%"></span>
                                </button>
                            @endforeach
                        </div>
                        <div class="mc-tip" hidden><strong></strong><span></span></div>
                    </div>
                    <div class="mc-x" aria-hidden="true">
                        @foreach ($xLabels as [$style, $text])<span style="{{ $style }}">{{ $text }}</span>@endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div>
                    <h2>Success rate</h2>
                    <div class="mc-sub">{{ $overall }}% overall · {{ number_format($chart['paid']) }} of {{ number_format($chart['orders']) }} paid · {{ $periodLabel }}</div>
                </div>
            </div>
            <div class="card-body">
                <div class="mc" data-mc>
                    <div class="mc-plot">
                        @foreach ([0, 50, 100] as $t)
                            <div class="mc-grid" style="bottom: {{ $t }}%"><span>{{ $t }}%</span></div>
                        @endforeach
                        <svg class="mc-svg" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                            <path d="{{ trim($path) }}" fill="none" stroke="var(--primary)" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"></path>
                        </svg>
                        @foreach ($dots as [$dx, $dy])
                            <span class="mc-dot" style="left: {{ $dx }}%; bottom: {{ $dy }}%"></span>
                        @endforeach
                        <div class="mc-cross" hidden></div>
                        <span class="mc-dot mc-dot--hover" hidden></span>
                        <div class="mc-cols">
                            @foreach ($b as $x)
                                @if ($x['future'])
                                    <span class="mc-col" aria-hidden="true"></span>
                                    @continue
                                @endif
                                <button type="button" class="mc-col"
                                        data-y="{{ $x['rate'] ?? '' }}"
                                        data-tip-value="{{ $x['rate'] === null ? 'No orders' : $x['rate'].'%' }}"
                                        data-tip-label="{{ $x['long'] }}{{ $x['orders'] ? ' · '.$x['paid'].' of '.$x['orders'].' paid' : '' }}"
                                        aria-label="{{ $x['long'] }}: {{ $x['rate'] === null ? 'no orders' : $x['rate'].'% success, '.$x['paid'].' of '.$x['orders'].' paid' }}"></button>
                            @endforeach
                        </div>
                        <div class="mc-tip" hidden><strong></strong><span></span></div>
                    </div>
                    <div class="mc-x" aria-hidden="true">
                        @foreach ($xLabels as [$style, $text])<span style="{{ $style }}">{{ $text }}</span>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <details class="mc-table">
        <summary>Show as table</summary>
        <div class="card" style="margin-top: .6rem">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ ['hour' => 'Hour', 'day' => 'Day', 'month' => 'Month'][$chart['unit']] }}</th><th class="num">Orders</th><th class="num">Paid</th><th class="num">Success</th><th class="num">Paid volume (PKR)</th></tr></thead>
                    <tbody>
                    @foreach ($b as $x)
                        @continue($x['future'])
                        <tr>
                            <td class="nowrap">{{ $x['long'] }}</td>
                            <td class="num">{{ number_format($x['orders']) }}</td>
                            <td class="num">{{ number_format($x['paid']) }}</td>
                            <td class="num">{{ $x['rate'] === null ? '—' : $x['rate'].'%' }}</td>
                            <td class="num">{{ number_format($x['volume'], 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </details>

    <script>
        document.querySelectorAll('[data-mc]').forEach(function (mc) {
            var plot = mc.querySelector('.mc-plot');
            var tip = mc.querySelector('.mc-tip');
            var cross = mc.querySelector('.mc-cross');
            var dot = mc.querySelector('.mc-dot--hover');

            function show(btn) {
                // Labels are data: always textContent, never innerHTML.
                tip.querySelector('strong').textContent = btn.getAttribute('data-tip-value');
                tip.querySelector('span').textContent = btn.getAttribute('data-tip-label');
                tip.hidden = false;
                var pr = plot.getBoundingClientRect();
                var br = btn.getBoundingClientRect();
                var cx = br.left + br.width / 2 - pr.left;
                tip.style.left = Math.max(0, Math.min(cx - tip.offsetWidth / 2, pr.width - tip.offsetWidth)) + 'px';
                if (cross) { cross.hidden = false; cross.style.left = cx + 'px'; }
                if (dot) {
                    var y = btn.getAttribute('data-y');
                    dot.hidden = y === '';
                    if (y !== '') { dot.style.left = cx + 'px'; dot.style.bottom = y + '%'; }
                }
            }

            function hide() {
                tip.hidden = true;
                if (cross) { cross.hidden = true; }
                if (dot) { dot.hidden = true; }
            }

            // Keep x labels from colliding at any width: walk right to left, hide a label that would touch the last kept one.
            function thinLabels() {
                var keptLeft = Infinity;
                var labels = mc.querySelectorAll('.mc-x span');
                for (var i = labels.length - 1; i >= 0; i--) {
                    labels[i].style.visibility = '';
                    var r = labels[i].getBoundingClientRect();
                    if (r.right + 6 > keptLeft) {
                        labels[i].style.visibility = 'hidden';
                    } else {
                        keptLeft = r.left;
                    }
                }
            }
            thinLabels();
            window.addEventListener('resize', thinLabels);

            mc.querySelectorAll('.mc-col').forEach(function (btn) {
                btn.addEventListener('pointerenter', function () { show(btn); });
                btn.addEventListener('focus', function () { show(btn); });
                btn.addEventListener('pointerleave', hide);
                btn.addEventListener('blur', hide);
            });
        });
    </script>
@endif
