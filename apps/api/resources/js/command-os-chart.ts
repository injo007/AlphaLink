import { init, use } from 'echarts/core';
import { LineChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import type { EChartsType } from 'echarts/core';
import type { DashboardPayload } from './command-os';

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
    const muted = theme === 'dark' ? '#98a5c9' : '#657397';
    const grid = theme === 'dark' ? 'rgba(148, 163, 220, .13)' : 'rgba(73, 95, 165, .12)';
    chart.setOption({
        animationDuration: 450,
        color: ['#267bff', '#a33df5'],
        grid: { left: 42, right: 16, top: 33, bottom: 36, containLabel: false },
        legend: {
            data: ['Deliveries', 'Opens'],
            right: 4,
            top: 0,
            itemWidth: 8,
            itemHeight: 8,
            textStyle: { color: muted, fontSize: 10, fontFamily: 'Inter, sans-serif' },
        },
        tooltip: {
            trigger: 'axis',
            backgroundColor: theme === 'dark' ? '#172443' : '#ffffff',
            borderColor: grid,
            textStyle: { color: theme === 'dark' ? '#eff3ff' : '#1b2648', fontSize: 11 },
            valueFormatter: (value: unknown) => formatNumber(Number(value ?? 0)),
        },
        xAxis: {
            type: 'category',
            boundaryGap: false,
            data: payload.trend.labels,
            axisLine: { lineStyle: { color: grid } },
            axisTick: { show: false },
            axisLabel: { color: muted, fontSize: 9, interval: 4, hideOverlap: true },
        },
        yAxis: {
            type: 'value',
            min: 0,
            axisLabel: { color: muted, fontSize: 9, formatter: (value: number) => formatNumber(value) },
            splitLine: { lineStyle: { color: grid, type: 'dashed' } },
        },
        series: [
            {
                name: 'Deliveries',
                type: 'line',
                smooth: 0.32,
                showSymbol: false,
                data: payload.trend.deliveries,
                lineStyle: { width: 2.4 },
                areaStyle: { opacity: theme === 'dark' ? 0.13 : 0.08 },
            },
            {
                name: 'Opens',
                type: 'line',
                smooth: 0.32,
                showSymbol: false,
                data: payload.trend.opens,
                lineStyle: { width: 2.4 },
                areaStyle: { opacity: theme === 'dark' ? 0.1 : 0.06 },
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
