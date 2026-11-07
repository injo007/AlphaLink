import { init, use } from 'echarts/core';
import { LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import type { EChartsType } from 'echarts/core';

type CampaignDay = {
    date: string;
    sent: number;
    deliveries: number;
    opens: number;
    clicks: number;
    leads: number;
    unsubscribes: number;
    soft_bounces: number;
    hard_bounces: number;
    bounces: number;
};

type CampaignSummary = {
    sent: number;
    deliveries: number;
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
        fractionIp?: string;
        sleepEmail?: string;
        sleepIp?: string;
        seedEvery?: string;
        sendTypeInput?: string;
        sendTypeEditable?: string;
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
const infoTitle = document.querySelector<HTMLElement>('[data-campaign-info-title]');
const informationDetails = document.querySelector<HTMLElement>('[data-campaign-information-details]');
const aggregateSummary = document.querySelector<HTMLElement>('[data-campaign-aggregate-summary]');
const aggregateMetricElements = new Map(
    Array.from(document.querySelectorAll<HTMLElement>('[data-campaign-aggregate-metric]'))
        .map((element) => [element.dataset.campaignAggregateMetric ?? '', element]),
);
const infoSendType = document.querySelector<HTMLSelectElement>('[data-campaign-info-send-type]');
const infoSendTypeValue = infoElements.get('send-type');
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

    const updateInformation = (value: string, selectedIds: string[] = []): void => {
        if (value === 'selected') {
            if (infoTitle) infoTitle.textContent = 'Selected Campaign Activity';
            if (infoName) infoName.textContent = `${selectedIds.length} campaigns selected`;
            if (informationDetails) informationDetails.hidden = true;
            if (aggregateSummary) aggregateSummary.hidden = false;
            for (const [key, element] of infoElements) element.textContent = key === 'id' ? 'Multiple' : '—';
            if (infoSendType) {
                infoSendType.hidden = true;
                infoSendType.disabled = true;
                delete infoSendType.dataset.targetInput;
            }
            if (infoSendTypeValue) infoSendTypeValue.hidden = false;
            return;
        }

        const selectedOption = campaignSelect?.selectedOptions[0] as CampaignOption | undefined;
        if (!selectedOption || value === 'all') {
            if (infoTitle) infoTitle.textContent = 'All Campaign Activity';
            if (infoName) infoName.textContent = 'All campaigns';
            if (informationDetails) informationDetails.hidden = true;
            if (aggregateSummary) aggregateSummary.hidden = false;
            for (const [key, element] of infoElements) element.textContent = key === 'id' ? 'All' : '—';
            if (infoSendType) {
                infoSendType.hidden = true;
                infoSendType.disabled = true;
                delete infoSendType.dataset.targetInput;
            }
            if (infoSendTypeValue) infoSendTypeValue.hidden = false;
            return;
        }

        if (infoTitle) infoTitle.textContent = 'Campaign Information';
        if (infoName) infoName.textContent = selectedOption.dataset.name ?? 'Selected campaign';
        if (informationDetails) informationDetails.hidden = false;
        if (aggregateSummary) aggregateSummary.hidden = true;
        const values: Record<string, string> = {
            id: selectedOption.dataset.id ?? '—',
            created: selectedOption.dataset.created ?? '—',
            offer: selectedOption.dataset.offer ?? '—',
            isp: selectedOption.dataset.isp ?? '—',
            server: selectedOption.dataset.server ?? '—',
            'send-type': selectedOption.dataset.sendType ?? '—',
            'send-state': selectedOption.dataset.sendState ?? '—',
            'total-send': selectedOption.dataset.totalSend ?? '—',
            'fraction-ip': selectedOption.dataset.fractionIp ?? '—',
            'sleep-email': selectedOption.dataset.sleepEmail ?? '—',
            'sleep-ip': selectedOption.dataset.sleepIp ?? '—',
            'seed-every': selectedOption.dataset.seedEvery ?? '—',
        };
        for (const [key, element] of infoElements) element.textContent = values[key] ?? '—';

        if (infoSendType) {
            const editableInput = selectedOption.dataset.sendTypeEditable === '1'
                ? document.getElementById(selectedOption.dataset.sendTypeInput ?? '') as HTMLSelectElement | null
                : null;
            infoSendType.hidden = !editableInput;
            infoSendType.disabled = !editableInput;
            if (editableInput) {
                infoSendType.dataset.targetInput = editableInput.id;
                infoSendType.value = editableInput.value;
                infoSendType.setAttribute('aria-label', `Send type for ${selectedOption.dataset.name ?? 'selected campaign'}`);
                if (infoSendTypeValue) infoSendTypeValue.hidden = true;
            } else {
                delete infoSendType.dataset.targetInput;
                if (infoSendTypeValue) infoSendTypeValue.hidden = false;
            }
        }
    };

    const updateMetrics = (summary: CampaignSummary | null): void => {
        const keys = ['sent', 'deliveries', 'opens', 'clicks', 'leads', 'unsubscribes', 'bounces', 'soft_bounces'] as const;
        for (const key of keys) {
            const element = metricElements.get(key);
            if (element) element.textContent = summary ? numberFormat.format(Number(summary[key] ?? 0)) : '—';
            const aggregateElement = aggregateMetricElements.get(key);
            if (aggregateElement) aggregateElement.textContent = summary ? numberFormat.format(Number(summary[key] ?? 0)) : '—';
        }
    };

    const loadAnalytics = async (value = campaignSelect.value, selectedIds: string[] = []): Promise<void> => {
        currentRequest?.abort();
        currentRequest = new AbortController();
        const url = new URL(summaryRoot.dataset.aggregateUrl ?? '', window.location.origin);
        if (selectedIds.length > 1) {
            selectedIds.forEach((id) => url.searchParams.append('campaign_ids[]', id));
        } else if (selectedIds.length === 1) {
            url.searchParams.set('campaign_id', selectedIds[0]);
        } else if (value !== 'all' && value !== 'selected') {
            url.searchParams.set('campaign_id', value);
        }
        updateInformation(value, selectedIds);
        updateMetrics(null);
        currentSummary = { sent: 0, deliveries: 0, opens: 0, clicks: 0, leads: 0, unsubscribes: 0, soft_bounces: 0, hard_bounces: 0, bounces: 0, timeline: [], coverage: '' };
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

    const rowSelections = Array.from(document.querySelectorAll<HTMLInputElement>('.campaign-row-select'));
    const selectedRowIds = (): string[] => rowSelections.filter((input) => input.checked).map((input) => input.value);
    const removeMultiSelectionOption = (): void => {
        campaignSelect.querySelector<HTMLOptionElement>('option[data-selected-campaigns]')?.remove();
    };
    const syncAnalyticsFromRows = (): void => {
        const ids = selectedRowIds();
        if (ids.length === 0) {
            removeMultiSelectionOption();
            campaignSelect.value = 'all';
            void loadAnalytics('all');
            return;
        }
        if (ids.length === 1) {
            removeMultiSelectionOption();
            campaignSelect.value = ids[0];
            void loadAnalytics(ids[0], ids);
            return;
        }
        let multiOption = campaignSelect.querySelector<HTMLOptionElement>('option[data-selected-campaigns]');
        if (!multiOption) {
            multiOption = document.createElement('option');
            multiOption.value = 'selected';
            multiOption.dataset.selectedCampaigns = 'true';
            campaignSelect.append(multiOption);
        }
        multiOption.textContent = `Selected campaigns (${ids.length})`;
        campaignSelect.value = 'selected';
        void loadAnalytics('selected', ids);
    };

    rowSelections.forEach((input) => input.addEventListener('change', syncAnalyticsFromRows));
    document.addEventListener('campaign-row-selection-change', syncAnalyticsFromRows);
    campaignSelect.addEventListener('change', () => {
        const value = campaignSelect.value;
        if (value !== 'selected') {
            removeMultiSelectionOption();
            void loadAnalytics(value, value === 'all' ? [] : [value]);
        }
    });
    infoSendType?.addEventListener('change', () => {
        const target = document.getElementById(infoSendType.dataset.targetInput ?? '') as HTMLSelectElement | null;
        if (!target || infoSendType.disabled) return;
        target.value = infoSendType.value;
        target.dispatchEvent(new Event('change', { bubbles: true }));
    });
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
        removeMultiSelectionOption();
        campaignSelect.value = campaignId;
        void loadAnalytics(campaignId, [campaignId]);
    });

    window.addEventListener('alphalink-command-os-theme-change', drawChart);
    window.addEventListener('resize', () => chart?.resize(), { passive: true });
    syncAnalyticsFromRows();
}
