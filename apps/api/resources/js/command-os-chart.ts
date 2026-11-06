import { init, use } from 'echarts/core';
import { LineChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import type { EChartsType } from 'echarts/core';
import type { DashboardChart, DashboardPayload } from './command-os';

use([LineChart, GridComponent, LegendComponent, TooltipComponent, CanvasRenderer]);

function formatNumber(value: number): string {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
}

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
    const accent = dark ? '#a879ff' : '#7445f5';
    const formatValue = (value: number) => currency
        ? new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', notation: 'compact', maximumFractionDigits: 1 }).format(value)
        : formatNumber(value);
    const values = data.series[0]?.values ?? [];
    const total = values.reduce((sum, value) => sum + Number(value || 0), 0);
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
        color: [accent],
        grid: { left: 54, right: 15, top: 13, bottom: 29 },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'line', lineStyle: { color: dark ? '#91a3dc' : '#8b9bc4', type: 'dashed', width: 1 } },
            backgroundColor: dark ? '#101b38' : '#ffffff',
            borderColor: grid,
            borderWidth: 1,
            padding: [9, 12],
            extraCssText: 'box-shadow: 0 12px 30px rgba(13, 25, 60, .24); border-radius: 10px;',
            textStyle: { color: dark ? '#eff3ff' : '#1b2648', fontSize: 11, fontFamily: 'Inter, sans-serif' },
            valueFormatter: (value: unknown) => formatValue(Number(value ?? 0)),
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
        series: [{
            name: data.series[0]?.label ?? 'Revenue',
            type: 'line',
            smooth: 0.32,
            showSymbol: true,
            symbol: 'circle',
            symbolSize: 5,
            data: values,
            lineStyle: { width: 2.8, shadowBlur: 13, shadowColor: `${accent}88` },
            itemStyle: { color: accent, borderColor: dark ? '#0b1430' : '#fff', borderWidth: 1.5 },
            areaStyle: fill,
            emphasis: { focus: 'series', scale: 1.6 },
        }],
        graphic: total === 0 ? [{
            type: 'text',
            left: 'center',
            top: 'middle',
            style: { text: 'No attributed revenue yet', fill: muted, font: '10px Inter, sans-serif' },
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
