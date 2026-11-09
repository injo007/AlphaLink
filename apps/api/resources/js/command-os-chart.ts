import { init, use } from 'echarts/core';
import { BarChart, LineChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import type { EChartsType } from 'echarts/core';
import type { DashboardChart, DashboardPayload } from './command-os';

use([BarChart, LineChart, GridComponent, LegendComponent, TooltipComponent, CanvasRenderer]);

function formatNumber(value: number): string {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
}

function formatCurrency(value: number): string {
    return `$${new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)}`;
}

function formatCompact(value: number, currency = false): string {
    if (value === 0) return currency ? '$0.00' : '0';
    if (value >= 1_000_000) {
        return (currency ? '$' : '') + (value / 1_000_000).toFixed(1) + 'M';
    }
    if (value >= 1_000) {
        return (currency ? '$' : '') + (value / 1_000).toFixed(1) + 'k';
    }
    return currency ? formatCurrency(value) : formatNumber(value);
}

export type HorizontalBarRow = {
    label: string;
    value: number;
    leads?: number;
    clicks?: number;
    deliveries?: number;
    secondary?: string;
};

export function renderPerformanceChart(
    element: HTMLElement,
    payload: DashboardPayload,
    theme: 'light' | 'dark',
): () => void {
    const chart: EChartsType = init(element, undefined, { renderer: 'canvas' });
    const dark = theme === 'dark';
    const muted = dark ? '#aab7dc' : '#68779a';
    const grid = dark ? 'rgba(148, 170, 224, .15)' : 'rgba(73, 95, 165, .13)';
    const deliveries = dark ? '#25bbff' : '#347cff';
    const opens = dark ? '#ca5cff' : '#9146f3';
    const fill = (color: string, opacity: number) => {
        const alpha = (value: number) => Math.round(value * 255).toString(16).padStart(2, '0');
        return {
            type: 'linear' as const,
            x: 0,
            y: 0,
            x2: 0,
            y2: 1,
            colorStops: [
                { offset: 0, color: `${color}${alpha(opacity)}` },
                { offset: 0.58, color: `${color}${alpha(opacity * 0.38)}` },
                { offset: 1, color: `${color}00` },
            ],
            global: false,
        };
    };
    chart.setOption({
        animationDuration: 720,
        animationEasing: 'cubicOut',
        color: [deliveries, opens],
        grid: { left: 44, right: 16, top: 35, bottom: 38, containLabel: false },
        legend: {
            data: ['Deliveries', 'Opens'],
            right: 4,
            top: 0,
            itemWidth: 9,
            itemHeight: 9,
            itemGap: 15,
            textStyle: { color: muted, fontSize: 9, fontWeight: 650, fontFamily: 'Inter, sans-serif' },
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'line', lineStyle: { color: dark ? '#91a3dc' : '#8b9bc4', type: 'dashed', width: 1 } },
            backgroundColor: dark ? '#101b38' : '#ffffff',
            borderColor: grid,
            borderWidth: 1,
            padding: [9, 12],
            extraCssText: 'box-shadow: 0 12px 30px rgba(13, 25, 60, .24); border-radius: 10px;',
            textStyle: { color: dark ? '#eff3ff' : '#1b2648', fontSize: 11, fontFamily: 'Inter, sans-serif' },
            valueFormatter: (value: unknown) => formatNumber(Number(value ?? 0)),
        },
        xAxis: {
            type: 'category',
            boundaryGap: false,
            data: payload.trend.labels,
            axisLine: { lineStyle: { color: grid, width: 1 } },
            axisTick: { show: false },
            axisLabel: { color: muted, fontSize: 8.5, margin: 10, interval: 4, hideOverlap: true },
        },
        yAxis: {
            type: 'value',
            min: 0,
            axisLabel: { color: muted, fontSize: 8.5, margin: 10, formatter: (value: number) => formatNumber(value) },
            axisLine: { show: false },
            axisTick: { show: false },
            splitNumber: 4,
            splitLine: { lineStyle: { color: grid, type: 'dashed', dashOffset: 3 } },
        },
        series: [
            {
                name: 'Deliveries',
                type: 'line',
                smooth: 0.38,
                showSymbol: false,
                data: payload.trend.deliveries,
                lineStyle: { width: 2.8, shadowBlur: 12, shadowColor: `${deliveries}77` },
                areaStyle: { color: fill(deliveries, dark ? 0.22 : 0.14) },
                emphasis: { focus: 'series', lineStyle: { width: 3.4 }, itemStyle: { borderColor: dark ? '#071329' : '#fff', borderWidth: 2 } },
                symbol: 'circle',
                symbolSize: 7,
                showAllSymbol: false,
            },
            {
                name: 'Opens',
                type: 'line',
                smooth: 0.38,
                showSymbol: false,
                data: payload.trend.opens,
                lineStyle: { width: 2.6, shadowBlur: 12, shadowColor: `${opens}77` },
                areaStyle: { color: fill(opens, dark ? 0.19 : 0.12) },
                emphasis: { focus: 'series', lineStyle: { width: 3.2 }, itemStyle: { borderColor: dark ? '#071329' : '#fff', borderWidth: 2 } },
                symbol: 'circle',
                symbolSize: 7,
                showAllSymbol: false,
            },
        ],
    });

    const resize = () => chart.resize();
    const observer = new ResizeObserver(resize);
    observer.observe(element);

    return () => {
        observer.disconnect();
        chart.dispose();
    };
}

export function renderDashboardAnalyticsChart(
    element: HTMLElement,
    data: DashboardChart,
    theme: 'light' | 'dark',
    currency = false,
): () => void {
    const chart: EChartsType = init(element, undefined, { renderer: 'canvas' });
    const dark = theme === 'dark';
    const muted = dark ? '#aab7dc' : '#68779a';
    const grid = dark ? 'rgba(148, 170, 224, .15)' : 'rgba(73, 95, 165, .13)';
    const formatValue = (value: number) => currency
        ? new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(value)
        : formatNumber(value);
    const hasRevenue = data.series.some((series) => series.values.some((value) => Number(value || 0) !== 0));
    const fill = {
        type: 'linear' as const,
        x: 0,
        y: 0,
        x2: 0,
        y2: 1,
        colorStops: [
            { offset: 0, color: dark ? 'rgba(168, 121, 255, .32)' : 'rgba(116, 69, 245, .24)' },
            { offset: 1, color: 'rgba(116, 69, 245, 0)' },
        ],
        global: false,
    };
    chart.setOption({
        animationDuration: 900,
        animationEasing: 'cubicOut',
        color: data.series.map((series) => series.color),
        grid: { left: 45, right: 15, top: data.series.length > 1 ? 31 : 13, bottom: 25 },
        legend: data.series.length > 1 ? {
            data: data.series.map((series) => series.label),
            top: 0,
            left: 'center',
            itemWidth: 8,
            itemHeight: 8,
            itemGap: 12,
            textStyle: { color: muted, fontSize: 8, fontWeight: 650, fontFamily: 'Inter, sans-serif' },
        } : undefined,
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'line', lineStyle: { color: dark ? '#91a3dc' : '#8b9bc4', type: 'dashed', width: 1 } },
            backgroundColor: dark ? '#101b38' : '#ffffff',
            borderColor: grid,
            borderWidth: 1,
            padding: [9, 12],
            extraCssText: 'box-shadow: 0 12px 30px rgba(13, 25, 60, .24); border-radius: 10px;',
            textStyle: { color: dark ? '#eff3ff' : '#1b2648', fontSize: 11, fontFamily: 'Inter, sans-serif' },
            formatter: (items: unknown) => {
                const points = (Array.isArray(items) ? items : [items]) as Array<{ marker?: string; seriesName?: string; value?: unknown }>;
                return points.map((point) => {
                    const series = data.series.find((item) => item.label === point.seriesName);
                    const amount = Number(point.value ?? 0);
                    const formatted = series?.currency_code
                        ? new Intl.NumberFormat('en-US', { style: 'currency', currency: series.currency_code, maximumFractionDigits: 2 }).format(amount)
                        : `${formatNumber(amount)} (currency not reported)`;
                    return `${point.marker ?? ''}${point.seriesName ?? 'Revenue'}: ${formatted}`;
                }).join('<br/>');
            },
        },
        xAxis: {
            type: 'category',
            boundaryGap: false,
            data: data.labels,
            axisLine: { lineStyle: { color: grid, width: 1 } },
            axisTick: { show: false },
            axisLabel: { color: muted, fontSize: 8, margin: 9, hideOverlap: true },
        },
        yAxis: {
            type: 'value',
            min: 0,
            axisLabel: { color: muted, fontSize: 8, margin: 8, formatter: (value: number) => formatValue(value) },
            axisLine: { show: false },
            axisTick: { show: false },
            splitNumber: 3,
            splitLine: { lineStyle: { color: grid, type: 'dashed', dashOffset: 3 } },
        },
        series: data.series.map((series, index) => ({
            name: series.label,
            type: 'line',
            smooth: 0.32,
            showSymbol: true,
            symbol: 'circle',
            symbolSize: 5,
            data: series.values,
            lineStyle: { width: index === 0 ? 2.8 : 2.3, shadowBlur: 13, shadowColor: `${series.color}88` },
            itemStyle: { color: series.color, borderColor: dark ? '#0b1430' : '#fff', borderWidth: 1.5 },
            ...(index === 0 ? { areaStyle: fill } : {}),
            emphasis: { focus: 'series', scale: 1.6 },
        })),
        graphic: !hasRevenue ? [{
            type: 'text',
            left: 'center',
            top: 'middle',
            style: { text: 'No revenue recorded in this period', fill: muted, font: '10px Inter, sans-serif' },
        }] : [],
    });

    const resize = () => chart.resize();
    const observer = new ResizeObserver(resize);
    observer.observe(element);

    return () => {
        observer.disconnect();
        chart.dispose();
    };
}

export function renderHorizontalBarChart(
    element: HTMLElement,
    rows: HorizontalBarRow[],
    theme: 'light' | 'dark',
    options?: {
        accentColor?: string;
        valuePrefix?: string;
        emptyMessage?: string;
    },
): () => void {
    const chart: EChartsType = init(element, undefined, { renderer: 'canvas' });
    const dark = theme === 'dark';
    const muted = dark ? '#aab7dc' : '#68779a';
    const grid = dark ? 'rgba(148, 170, 224, .15)' : 'rgba(73, 95, 165, .13)';
    const textInk = dark ? '#e9effd' : '#1e293b';

    const accent = options?.accentColor ?? (dark ? '#a879ff' : '#7445f5');
    const accentGradStart = dark ? '#c084fc' : '#8b5cf6';
    const accentGradEnd = dark ? '#7c3aed' : '#6d28d9';

    const hasData = rows.length > 0 && rows.some((r) => Number(r.value || 0) > 0);
    const displayRows = rows.slice(0, 10);

    const labels = displayRows.map((r) => r.label);
    const values = displayRows.map((r) => r.value);

    chart.setOption({
        animationDuration: 800,
        animationEasing: 'cubicOut',
        grid: {
            left: 105,
            right: 48,
            top: 10,
            bottom: 24,
            containLabel: false,
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow', shadowStyle: { color: dark ? 'rgba(255,255,255,0.04)' : 'rgba(0,0,0,0.03)' } },
            backgroundColor: dark ? '#101b38' : '#ffffff',
            borderColor: grid,
            borderWidth: 1,
            padding: [8, 12],
            extraCssText: 'box-shadow: 0 12px 30px rgba(13, 25, 60, .24); border-radius: 9px;',
            textStyle: { color: dark ? '#eff3ff' : '#1b2648', fontSize: 11, fontFamily: 'Inter, sans-serif' },
            formatter: (params: unknown) => {
                const arr = Array.isArray(params) ? params : [params];
                const point = arr[0] as { dataIndex: number } | undefined;
                if (!point) return '';
                const row = displayRows[point.dataIndex];
                if (!row) return '';
                let html = `<strong style="display:block;margin-bottom:4px;color:${accent};">${row.label}</strong>`;
                html += `<div style="font-size:10px;line-height:1.4;">Attributed Revenue: <b>${formatCurrency(row.value)}</b>`;
                if (row.leads !== undefined) html += `<br/>Leads: <b>${formatNumber(row.leads)}</b>`;
                if (row.clicks !== undefined) html += `<br/>Clicks: <b>${formatNumber(row.clicks)}</b>`;
                if (row.deliveries !== undefined) html += `<br/>Deliveries: <b>${formatNumber(row.deliveries)}</b>`;
                html += `</div>`;
                return html;
            },
        },
        xAxis: {
            type: 'value',
            min: 0,
            axisLabel: {
                color: muted,
                fontSize: 8,
                formatter: (val: number) => formatCompact(val, true),
            },
            axisLine: { show: false },
            axisTick: { show: false },
            splitLine: { lineStyle: { color: grid, type: 'dashed' } },
        },
        yAxis: {
            type: 'category',
            inverse: true,
            data: labels,
            axisLine: { lineStyle: { color: grid } },
            axisTick: { show: false },
            axisLabel: {
                color: textInk,
                fontSize: 8.5,
                fontWeight: 600,
                formatter: (value: string) => (value.length > 15 ? value.slice(0, 14) + '…' : value),
            },
        },
        series: [
            {
                name: 'Attributed Revenue',
                type: 'bar',
                barWidth: 12,
                barMaxWidth: 16,
                data: values,
                itemStyle: {
                    borderRadius: [0, 6, 6, 0],
                    color: {
                        type: 'linear',
                        x: 0,
                        y: 0,
                        x2: 1,
                        y2: 0,
                        colorStops: [
                            { offset: 0, color: accentGradEnd },
                            { offset: 1, color: accentGradStart },
                        ],
                    },
                },
                emphasis: {
                    itemStyle: {
                        color: dark ? '#38bdf8' : '#2563eb',
                    },
                },
                label: {
                    show: true,
                    position: 'right',
                    fontSize: 8,
                    fontWeight: 600,
                    color: muted,
                    formatter: (param: { value: unknown }) => {
                        const num = Number(param.value ?? 0);
                        return num > 0 ? formatCompact(num, true) : '$0';
                    },
                },
            },
        ],
        graphic: !hasData
            ? [
                  {
                      type: 'text',
                      left: 'center',
                      top: 'middle',
                      style: {
                          text: options?.emptyMessage ?? 'No attributed revenue recorded in this period',
                          fill: muted,
                          font: '10px Inter, sans-serif',
                      },
                  },
              ]
            : [],
    });

    const resize = () => chart.resize();
    const observer = new ResizeObserver(resize);
    observer.observe(element);

    return () => {
        observer.disconnect();
        chart.dispose();
    };
}

export function renderMtdActionsMultiLineChart(
    element: HTMLElement,
    chartData: DashboardChart,
    theme: 'light' | 'dark',
): () => void {
    const chart: EChartsType = init(element, undefined, { renderer: 'canvas' });
    const dark = theme === 'dark';
    const muted = dark ? '#aab7dc' : '#68779a';
    const grid = dark ? 'rgba(148, 170, 224, .15)' : 'rgba(73, 95, 165, .13)';

    const seriesColors = ['#28b7ff', '#ffbd31', '#ff5d7b', '#a376ff', '#32d5a1', '#ffa34a', '#52a4ff'];

    chart.setOption({
        animationDuration: 850,
        animationEasing: 'cubicOut',
        grid: { left: 45, right: 15, top: 32, bottom: 25 },
        legend: {
            data: chartData.series.map((s) => s.label),
            top: 2,
            left: 'center',
            itemWidth: 8,
            itemHeight: 8,
            itemGap: 10,
            textStyle: { color: muted, fontSize: 8, fontWeight: 650, fontFamily: 'Inter, sans-serif' },
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'line', lineStyle: { color: dark ? '#91a3dc' : '#8b9bc4', type: 'dashed', width: 1 } },
            backgroundColor: dark ? '#101b38' : '#ffffff',
            borderColor: grid,
            borderWidth: 1,
            padding: [9, 12],
            extraCssText: 'box-shadow: 0 12px 30px rgba(13, 25, 60, .24); border-radius: 9px;',
            textStyle: { color: dark ? '#eff3ff' : '#1b2648', fontSize: 10.5, fontFamily: 'Inter, sans-serif' },
            formatter: (items: unknown) => {
                const points = (Array.isArray(items) ? items : [items]) as Array<{ marker?: string; seriesName?: string; value?: unknown }>;
                const header = `<div style="font-weight:700;margin-bottom:3px;color:${dark ? '#93c5fd' : '#2563eb'};">Month to Date Activity</div>`;
                const lines = points.map((p) => `${p.marker ?? ''}${p.seriesName}: <b>${formatNumber(Number(p.value ?? 0))}</b>`);
                return header + lines.join('<br/>');
            },
        },
        xAxis: {
            type: 'category',
            boundaryGap: false,
            data: chartData.labels,
            axisLine: { lineStyle: { color: grid, width: 1 } },
            axisTick: { show: false },
            axisLabel: { color: muted, fontSize: 8, margin: 8, hideOverlap: true },
        },
        yAxis: {
            type: 'value',
            min: 0,
            axisLabel: { color: muted, fontSize: 8, margin: 8, formatter: (val: number) => formatCompact(val) },
            axisLine: { show: false },
            axisTick: { show: false },
            splitLine: { lineStyle: { color: grid, type: 'dashed', dashOffset: 3 } },
        },
        series: chartData.series.map((series, idx) => {
            const color = series.color || seriesColors[idx % seriesColors.length];
            return {
                name: series.label,
                type: 'line',
                smooth: 0.35,
                showSymbol: false,
                data: series.values,
                lineStyle: { width: 2.2, color },
                itemStyle: { color, borderColor: dark ? '#0b1430' : '#fff', borderWidth: 1.5 },
                emphasis: { focus: 'series', scale: 1.5 },
            };
        }),
    });

    const resize = () => chart.resize();
    const observer = new ResizeObserver(resize);
    observer.observe(element);

    return () => {
        observer.disconnect();
        chart.dispose();
    };
}

export function renderYtdRevenueBarTrendChart(
    element: HTMLElement,
    chartData: DashboardChart,
    theme: 'light' | 'dark',
): () => void {
    const chart: EChartsType = init(element, undefined, { renderer: 'canvas' });
    const dark = theme === 'dark';
    const muted = dark ? '#aab7dc' : '#68779a';
    const grid = dark ? 'rgba(148, 170, 224, .15)' : 'rgba(73, 95, 165, .13)';

    const barGradStart = dark ? '#a855f7' : '#8b5cf6';
    const barGradEnd = dark ? '#6366f1' : '#4f46e5';
    const trendColor = dark ? '#38bdf8' : '#0284c7';

    const values = chartData.series[0]?.values ?? [];
    const hasData = values.some((v) => Number(v || 0) > 0) || chartData.series.some((s) => s.values.some((v) => Number(v || 0) > 0));

    chart.setOption({
        animationDuration: 900,
        animationEasing: 'cubicOut',
        grid: { left: 55, right: 25, top: 28, bottom: 25 },
        legend: {
            data: ['Monthly Attributed', 'Trend Line', ...chartData.series.slice(1).map((s) => s.label)],
            top: 0,
            left: 'center',
            itemWidth: 9,
            itemHeight: 9,
            itemGap: 12,
            textStyle: { color: muted, fontSize: 8.5, fontWeight: 650, fontFamily: 'Inter, sans-serif' },
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'cross', crossStyle: { color: dark ? '#91a3dc' : '#8b9bc4', type: 'dashed' } },
            backgroundColor: dark ? '#101b38' : '#ffffff',
            borderColor: grid,
            borderWidth: 1,
            padding: [9, 12],
            extraCssText: 'box-shadow: 0 12px 30px rgba(13, 25, 60, .24); border-radius: 9px;',
            textStyle: { color: dark ? '#eff3ff' : '#1b2648', fontSize: 10.5, fontFamily: 'Inter, sans-serif' },
            formatter: (params: unknown) => {
                const points = (Array.isArray(params) ? params : [params]) as Array<{ seriesName: string; value: unknown; marker?: string }>;
                const month = (points[0] as unknown as { axisValueLabel?: string })?.axisValueLabel ?? 'Month';
                let html = `<strong style="display:block;margin-bottom:3px;color:${trendColor};">${month} Revenue</strong>`;
                points.forEach((p) => {
                    const series = chartData.series.find((s) => s.label === p.seriesName);
                    const val = Number(p.value ?? 0);
                    const formatted = series?.currency_code
                        ? new Intl.NumberFormat('en-US', { style: 'currency', currency: series.currency_code }).format(val)
                        : formatCurrency(val);
                    html += `${p.marker ?? ''}${p.seriesName}: <b>${formatted}</b><br/>`;
                });
                return html;
            },
        },
        xAxis: {
            type: 'category',
            data: chartData.labels,
            axisLine: { lineStyle: { color: grid, width: 1 } },
            axisTick: { show: false },
            axisLabel: { color: muted, fontSize: 8.5, margin: 9 },
        },
        yAxis: {
            type: 'value',
            min: 0,
            axisLabel: { color: muted, fontSize: 8, margin: 8, formatter: (val: number) => formatCompact(val, true) },
            axisLine: { show: false },
            axisTick: { show: false },
            splitLine: { lineStyle: { color: grid, type: 'dashed', dashOffset: 3 } },
        },
        series: [
            {
                name: 'Monthly Attributed',
                type: 'bar',
                barWidth: 20,
                barMaxWidth: 26,
                data: values,
                itemStyle: {
                    borderRadius: [5, 5, 0, 0],
                    color: {
                        type: 'linear',
                        x: 0,
                        y: 0,
                        x2: 0,
                        y2: 1,
                        colorStops: [
                            { offset: 0, color: barGradStart },
                            { offset: 1, color: barGradEnd },
                        ],
                    },
                },
            },
            {
                name: 'Trend Line',
                type: 'line',
                smooth: 0.35,
                showSymbol: true,
                symbol: 'circle',
                symbolSize: 6,
                data: values,
                lineStyle: { width: 2.8, color: trendColor, shadowBlur: 10, shadowColor: `${trendColor}88` },
                itemStyle: { color: trendColor, borderColor: dark ? '#0a1329' : '#fff', borderWidth: 2 },
            },
            ...chartData.series.slice(1).map((s) => ({
                name: s.label,
                type: 'line' as const,
                smooth: 0.35,
                showSymbol: false,
                data: s.values,
                lineStyle: { width: 2, color: s.color, type: 'dashed' as const },
                itemStyle: { color: s.color },
            })),
        ],
        graphic: !hasData
            ? [
                  {
                      type: 'text',
                      left: 'center',
                      top: 'middle',
                      style: { text: 'No YTD revenue recorded for this period', fill: muted, font: '10px Inter, sans-serif' },
                  },
              ]
            : [],
    });

    const resize = () => chart.resize();
    const observer = new ResizeObserver(resize);
    observer.observe(element);

    return () => {
        observer.disconnect();
        chart.dispose();
    };
}
