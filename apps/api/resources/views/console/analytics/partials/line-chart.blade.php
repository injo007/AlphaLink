@php
    $labels = $chart['labels'];
    $pointCount = count($labels);
    $allValues = collect($chart['series'])->flatMap(fn (array $series): array => $series['values']);
    $maxValue = max(1, (float) $allValues->max());
    $plotLeft = 58;
    $plotRight = 930;
    $plotTop = 22;
    $plotBottom = 222;
    $plotHeight = $plotBottom - $plotTop;
    $plotWidth = $plotRight - $plotLeft;
    $tickValues = array_values(array_unique([0, (int) ceil($maxValue / 2), (int) ceil($maxValue)]));
    $labelIndices = collect([0, (int) floor(max(0, $pointCount - 1) * 0.25), (int) floor(max(0, $pointCount - 1) * 0.5), (int) floor(max(0, $pointCount - 1) * 0.75), max(0, $pointCount - 1)])->unique()->values();
    $displayValue = fn (float|int $value): string => $currency ? '$'.number_format((float) $value, 2) : number_format((float) $value);
@endphp

<section class="block block-rounded analytics-chart-panel" aria-labelledby="{{ $chartId }}-heading">
    <header class="block-header block-header-default">
        <h3 class="block-title" id="{{ $chartId }}-heading">{{ $title }}</h3>
    </header>
    <div class="block-content block-content-full">
        <figure class="analytics-line-chart">
            <figcaption class="analytics-line-chart__caption">{{ $description }}</figcaption>
            <div class="analytics-legend" aria-label="{{ $title }} legend">
                @foreach ($chart['series'] as $series)
                    <span><i aria-hidden="true" style="background-color: {{ $series['color'] }}"></i>{{ $series['label'] }}</span>
                @endforeach
            </div>
            <svg class="analytics-line-chart__svg" viewBox="0 0 960 270" role="img" aria-labelledby="{{ $chartId }}-heading {{ $chartId }}-description">
                <desc id="{{ $chartId }}-description">{{ $description }} Values use the vertical axis; dates or months use the horizontal axis.</desc>
                @foreach ($tickValues as $tick)
                    @php
                        $tickY = $plotBottom - (($tick / $maxValue) * $plotHeight);
                    @endphp
                    <line class="analytics-line-chart__grid" x1="{{ $plotLeft }}" y1="{{ $tickY }}" x2="{{ $plotRight }}" y2="{{ $tickY }}" />
                    <text class="analytics-line-chart__axis-label" x="{{ $plotLeft - 8 }}" y="{{ $tickY + 4 }}" text-anchor="end">{{ $displayValue($tick) }}</text>
                @endforeach
                <line class="analytics-line-chart__axis" x1="{{ $plotLeft }}" y1="{{ $plotBottom }}" x2="{{ $plotRight }}" y2="{{ $plotBottom }}" />
                @foreach ($chart['series'] as $series)
                    @php
                        $coordinates = collect($series['values'])->map(function (float|int $value, int $index) use ($pointCount, $plotLeft, $plotRight, $plotTop, $plotBottom, $plotHeight, $plotWidth, $maxValue): array {
                            $x = $plotLeft + ($pointCount <= 1 ? 0 : ($index / ($pointCount - 1)) * $plotWidth);
                            $y = $plotBottom - (((float) $value / $maxValue) * $plotHeight);

                            return ['x' => $x, 'y' => $y, 'value' => $value];
                        });
                        $linePoints = $coordinates->map(fn (array $point): string => number_format($point['x'], 1, '.', '').','.number_format($point['y'], 1, '.', ''))->implode(' ');
                    @endphp
                    <polyline class="analytics-line-chart__line" points="{{ $linePoints }}" stroke="{{ $series['color'] }}" />
                    @foreach ($coordinates as $index => $point)
                        <circle class="analytics-line-chart__point" cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="3.5" fill="{{ $series['color'] }}">
                            <title>{{ $labels[$index] }}: {{ $series['label'] }} {{ $displayValue($point['value']) }}</title>
                        </circle>
                    @endforeach
                @endforeach
                @foreach ($labelIndices as $index)
                    @php
                        $labelX = $plotLeft + ($pointCount <= 1 ? 0 : ($index / ($pointCount - 1)) * $plotWidth);
                    @endphp
                    <text class="analytics-line-chart__axis-label" x="{{ $labelX }}" y="250" text-anchor="middle">{{ $labels[$index] }}</text>
                @endforeach
            </svg>
        </figure>
    </div>
</section>
