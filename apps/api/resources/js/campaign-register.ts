import { init, use } from 'echarts/core';
import { LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import type { EChartsType } from 'echarts/core';

type CampaignDay = {
    date: string;
    opens: number;
    clicks: number;
    leads: number;
    unsubscribes: number;
    soft_bounces: number;
    hard_bounces: number;
    bounces: number;
};

type CampaignSummary = {
    opens: number;
    clicks: number;
    leads: number;
    unsubscribes: number;
    soft_bounces: number;
    hard_bounces: number;
    bounces: number;
    timeline: CampaignDay[];
    coverage: string;
};

type CampaignOption = HTMLOptionElement & {
    dataset: DOMStringMap & {
        name?: string;
        id?: string;
        created?: string;
        offer?: string;
        isp?: string;
        server?: string;
        sendType?: string;
        sendState?: string;
        totalSend?: string;
    };
};

use([LineChart, GridComponent, TooltipComponent, CanvasRenderer]);

const numberFormat = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });
const summaryRoot = document.querySelector<HTMLElement>('[data-campaign-analytics]');
const chartElement = document.querySelector<HTMLElement>('[data-campaign-engagement-chart]');
const campaignSelect = document.querySelector<HTMLSelectElement>('#campaign-analytics-select');
const status = document.querySelector<HTMLElement>('[data-campaign-analytics-status]');
const metricElements = new Map(
    Array.from(document.querySelectorAll<HTMLElement>('[data-campaign-metric]'))
        .map((element) => [element.dataset.campaignMetric ?? '', element]),
);
const infoElements = new Map(
    Array.from(document.querySelectorAll<HTMLElement>('[data-campaign-info]'))
        .map((element) => [element.dataset.campaignInfo ?? '', element]),
);
const infoName = document.querySelector<HTMLElement>('[data-campaign-info-name]');
const seriesButtons = Array.from(document.querySelectorAll<HTMLButtonElement>('[data-campaign-series]'));
let selectedSeries: keyof Pick<CampaignDay, 'opens' | 'clicks' | 'leads'> = 'opens';
let currentSummary: CampaignSummary | null = null;
let currentRequest: AbortController | null = null;
let chart: EChartsType | null = null;

if (summaryRoot && chartElement && campaignSelect && status) {
    chart = init(chartElement, undefined, { renderer: 'canvas' });
    chartElement.dataset.chartReady = 'true';

    const theme = (): 'light' | 'dark' => document.documentElement.dataset.commandTheme === 'dark' ? 'dark' : 'light';
    const isDark = (): boolean => theme() === 'dark';

    const drawChart = (): void => {
        if (!chart || !currentSummary) return;

        const dark = isDark();
        const muted = dark ? '#aab7dc' : '#68779a';
        const line = selectedSeries === 'clicks' ? (dark ? '#26b9ff' : '#168bef')
            : selectedSeries === 'leads' ? (dark ? '#f263c4' : '#ad43ec')
                : (dark ? '#b18aff' : '#803cf3');
        const fill = {
            type: 'linear' as const,
            x: 0,
            y: 0,
            x2: 0,
            y2: 1,
            colorStops: [
                { offset: 0, color: `${line}${dark ? '55' : '38'}` },
                { offset: 1, color: `${line}00` },
            ],
            global: false,
        };
        const days = currentSummary.timeline ?? [];
        const labels = days.map((day) => new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' }).format(new Date(`${day.date}T12:00:00`)));

        chart?.setOption({
            animationDuration: 520,
            animationEasing: 'cubicOut',
            color: [line],
            grid: { left: 44, right: 13, top: 16, bottom: 30 },
            tooltip: {
                trigger: 'axis',
                backgroundColor: dark ? '#101b38' : '#ffffff',
                borderColor: dark ? 'rgba(148, 170, 224, .2)' : 'rgba(73, 95, 165, .16)',
                borderWidth: 1,
                padding: [8, 11],
                extraCssText: 'box-shadow: 0 12px 30px rgba(13, 25, 60, .2); border-radius: 10px;',
                textStyle: { color: dark ? '#eff3ff' : '#1b2648', fontSize: 10, fontFamily: 'Inter, sans-serif' },
                valueFormatter: (value: unknown) => numberFormat.format(Number(value ?? 0)),
            },
            xAxis: {
                type: 'category',
                boundaryGap: false,
                data: labels,
                axisLine: { lineStyle: { color: dark ? 'rgba(148, 170, 224, .15)' : 'rgba(73, 95, 165, .13)' } },
                axisTick: { show: false },
                axisLabel: { color: muted, fontSize: 8, margin: 9, hideOverlap: true },
            },
            yAxis: {
                type: 'value',
                min: 0,
                splitNumber: 3,
                axisLabel: { color: muted, fontSize: 8, formatter: (value: number) => numberFormat.format(value) },
                axisLine: { show: false },
                axisTick: { show: false },
                splitLine: { lineStyle: { color: dark ? 'rgba(148, 170, 224, .12)' : 'rgba(73, 95, 165, .11)', type: 'dashed' } },
            },
            series: [{
                name: selectedSeries[0].toUpperCase() + selectedSeries.slice(1),
                type: 'line',
                smooth: 0.32,
                showSymbol: true,
                symbol: 'circle',
                symbolSize: days.length > 20 ? 3 : 5,
                data: days.map((day) => Number(day[selectedSeries] ?? 0)),
                lineStyle: { width: 2.6, shadowBlur: 11, shadowColor: `${line}77` },
                itemStyle: { color: line, borderColor: dark ? '#0b1430' : '#fff', borderWidth: 1.5 },
                areaStyle: { color: fill },
                emphasis: { focus: 'series', scale: 1.5 },
            }],
            graphic: days.length === 0 ? [{
                type: 'text',
                left: 'center',
                top: 'middle',
                style: { text: 'No attributed activity in this period', fill: muted, font: '10px Inter, sans-serif' },
            }] : [],
        }, true);
    };

    const updateInformation = (value: string): void => {
        const selectedOption = campaignSelect?.selectedOptions[0] as CampaignOption | undefined;
        if (!selectedOption || value === 'all') {
            if (infoName) infoName.textContent = 'All campaigns';
            for (const [key, element] of infoElements) element.textContent = key === 'id' ? 'All' : '—';
            return;
        }

        if (infoName) infoName.textContent = selectedOption.dataset.name ?? 'Selected campaign';
        const values: Record<string, string> = {
            id: selectedOption.dataset.id ?? '—',
            created: selectedOption.dataset.created ?? '—',
            offer: selectedOption.dataset.offer ?? '—',
            isp: selectedOption.dataset.isp ?? '—',
            server: selectedOption.dataset.server ?? '—',
            'send-type': selectedOption.dataset.sendType ?? '—',
            'send-state': selectedOption.dataset.sendState ?? '—',
            'total-send': selectedOption.dataset.totalSend ?? '—',
        };
        for (const [key, element] of infoElements) element.textContent = values[key] ?? '—';
    };

    const updateMetrics = (summary: CampaignSummary | null): void => {
        const keys = ['opens', 'clicks', 'leads', 'unsubscribes', 'bounces', 'soft_bounces'] as const;
        for (const key of keys) {
            const element = metricElements.get(key);
            if (element) element.textContent = numberFormat.format(Number(summary?.[key] ?? 0));
        }
    };

    const loadAnalytics = async (value = campaignSelect.value): Promise<void> => {
        currentRequest?.abort();
        currentRequest = new AbortController();
        const url = new URL(summaryRoot.dataset.aggregateUrl ?? '', window.location.origin);
        if (value !== 'all') url.searchParams.set('campaign_id', value);
        updateInformation(value);
        updateMetrics(null);
        currentSummary = { opens: 0, clicks: 0, leads: 0, unsubscribes: 0, soft_bounces: 0, hard_bounces: 0, bounces: 0, timeline: [], coverage: '' };
        drawChart();
        status.textContent = 'Loading campaign activity…';

        try {
            const response = await fetch(url, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: currentRequest.signal,
            });
            if (!response.ok) throw new Error('Campaign analytics are unavailable.');
            const rows = await response.json() as CampaignSummary[];
            if (currentRequest.signal.aborted) return;
            currentSummary = rows[0] ?? currentSummary;
            updateMetrics(currentSummary);
            drawChart();
            status.textContent = currentSummary.timeline.length === 0
                ? 'No campaign activity is recorded for this selection and date range.'
                : `${numberFormat.format(currentSummary.timeline.length)} active day${currentSummary.timeline.length === 1 ? '' : 's'} in the selected range.`;
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') return;
            currentSummary = null;
            updateMetrics(null);
            status.textContent = 'Campaign analytics could not be loaded. Select a campaign to retry.';
            if (chart) chart.clear();
        }
    };

    campaignSelect.addEventListener('change', () => loadAnalytics(campaignSelect.value));
    seriesButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const series = button.dataset.campaignSeries;
            if (series !== 'opens' && series !== 'clicks' && series !== 'leads') return;
            selectedSeries = series;
            seriesButtons.forEach((item) => item.setAttribute('aria-pressed', String(item === button)));
            if (chartElement) chartElement.setAttribute('aria-label', `Campaign ${series} over time`);
            drawChart();
        });
    });

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        const button = target.closest<HTMLButtonElement>('[data-campaign-statistics-toggle]');
        if (!button || !campaignSelect) return;
        const campaignId = button.dataset.campaignId;
        if (!campaignId) return;
        campaignSelect.value = campaignId;
        void loadAnalytics(campaignId);
    });

    window.addEventListener('alphalink-command-os-theme-change', drawChart);
    window.addEventListener('resize', () => chart?.resize(), { passive: true });
    void loadAnalytics('all');
}
