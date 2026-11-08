import { lazy, Suspense, useCallback, useEffect, useRef, useState } from 'react';
import type { JSX } from 'react';
import { createRoot } from 'react-dom/client';
import {
    Activity,
    ChartNoAxesCombined,
    CircleHelp,
    CloudUpload,
    DollarSign,
    Flame,
    Globe2,
    Layers3,
    Link2,
    Mail,
    MapPinned,
    Moon,
    MousePointer2,
    Network,
    RefreshCw,
    Send,
    ShieldCheck,
    Sun,
    Target,
    TriangleAlert,
    UserRoundMinus,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

type MetricKey = 'deliveries' | 'opens' | 'clicks' | 'leads' | 'revenue' | 'unsubscribes' | 'bounces';
type Metric = {
    key: MetricKey;
    label: string;
    value: number;
    format: 'number' | 'currency';
    detail: string;
    trend: number[];
};
type DashboardAction = {
    label: string;
    href: string | null;
    icon: string;
    disabled?: boolean;
    reason?: string;
};
export type DashboardPayload = {
    userName: string;
    workspaceName: string;
    workspaceRole: string;
    today: { date: string; deliveries: number; opens: number; clicks: number; revenue: number };
    period: { label: string; from: string; to: string };
    metrics: Metric[];
    trend: { labels: string[]; deliveries: number[]; opens: number[] };
    charts: Record<'mtd_actions' | 'mtd_revenue' | 'ytd_revenue', DashboardChart>;
    refresh: { url: string; interval: number };
    coverage: { deliveries: string; revenue: string; geo: string; graph: string };
    analytics: DashboardAnalytics;
    actions: DashboardAction[];
};
export type AnalyticsRow = { label: string; leads: number; clicks: number; attributed_revenue: number; deliveries?: number; opens?: number; unsubscribes?: number; bounces?: number };
export type ProviderSponsorRevenueRow = { dimension_id: string; label: string; currency_code: string | null; reported_conversions: number; reported_amount: number };
export type DashboardChartSeries = { label: string; color: string; values: number[] };
export type DashboardChart = { labels: string[]; series: DashboardChartSeries[] };
export type DashboardGeoMetric = 'deliveries' | 'opens' | 'clicks' | 'leads' | 'bounces' | 'unsubscribes';
export type GeoCountry = { country: string; opens: number; clicks: number; leads: number; unsubscribes: number; deliveries: number; bounces: number };
export type MarketingNode = { id: string; label: string; count: number; color: string; href: string | null };
export type MarketingEdge = { source: string; target: string; count: number };
export type DashboardAnalytics = {
    totals: { deliveries: number; opens: number; clicks: number; leads: number; unsubscribes: number; bounces: number; attributed_revenue: number };
    sponsors: AnalyticsRow[];
    provider_sponsors: ProviderSponsorRevenueRow[];
    isps: AnalyticsRow[];
    mailers: AnalyticsRow[];
    offers: AnalyticsRow[];
    geo: { countries: GeoCountry[]; proxy_opens_excluded: number; feedback_note: string };
    graph: { nodes: MarketingNode[]; edges: MarketingEdge[]; note: string };
    period: { key: string; label: string; from: string; to: string; maxDate: string; action: string };
};
type Theme = 'light' | 'dark';

const DeliverabilityGeoMap = lazy(() => import('./command-os-geo-map'));
const MarketingGraph = lazy(() => import('./command-os-graph'));

class DashboardAuthenticationError extends Error {}

const metricIcons: Record<MetricKey, LucideIcon> = {
    deliveries: Send,
    opens: Mail,
    clicks: MousePointer2,
    leads: Target,
    revenue: DollarSign,
    unsubscribes: UserRoundMinus,
    bounces: TriangleAlert,
};

const metricColors: Record<MetricKey, string> = {
    deliveries: 'blue',
    opens: 'violet',
    clicks: 'cyan',
    leads: 'indigo',
    revenue: 'green',
    unsubscribes: 'pink',
    bounces: 'red',
};

const actionIcons: Record<string, LucideIcon> = {
    chart: ChartNoAxesCombined,
    globe: Globe2,
    dollar: DollarSign,
    activity: Activity,
    map: MapPinned,
    network: Network,
    send: Send,
    layers: Layers3,
    flame: Flame,
    upload: CloudUpload,
    shield: ShieldCheck,
    link: Link2,
};

function number(value: number): string {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
}

function money(value: number): string {
    return `$${new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)}`;
}

function providerAmount(value: number): string {
    return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
}

function safeStoredTheme(): Theme {
    try {
        return window.localStorage.getItem('alphalink-command-os-theme') === 'dark' ? 'dark' : 'light';
    } catch {
        return 'light';
    }
}

function setTheme(theme: Theme): void {
    document.documentElement.dataset.commandTheme = theme;
    document.body.dataset.commandTheme = theme;
    try {
        window.localStorage.setItem('alphalink-command-os-theme', theme);
    } catch {
        // Theme selection still applies for this page when storage is unavailable.
    }
    window.dispatchEvent(new CustomEvent('alphalink-command-os-theme-change', { detail: theme }));
}

function ThemeToggle(): JSX.Element {
    const [theme, updateTheme] = useState<Theme>('light');

    useEffect(() => {
        const initial = safeStoredTheme();
        updateTheme(initial);
        setTheme(initial);
    }, []);

    const toggle = () => {
        const next = theme === 'light' ? 'dark' : 'light';
        updateTheme(next);
        setTheme(next);
    };

    const ThemeIcon = theme === 'light' ? Moon : Sun;

    return (
        <button
            className="command-os-theme-button"
            type="button"
            onClick={toggle}
            aria-label={`Switch to ${theme === 'light' ? 'dark' : 'light'} theme`}
            title={`Switch to ${theme === 'light' ? 'dark' : 'light'} theme`}
        >
            <ThemeIcon size={17} aria-hidden="true" />
        </button>
    );
}

function readPayload(element: ParentNode = document): DashboardPayload | null {
    const script = element.querySelector<HTMLScriptElement>('#command-os-dashboard-data');
    if (!script?.textContent) return null;

    try {
        return JSON.parse(script.textContent) as DashboardPayload;
    } catch {
        return null;
    }
}

async function fetchPayload(url: string): Promise<DashboardPayload> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (response.redirected || response.status === 401 || response.status === 403) {
        const destination = new URL(response.url || window.location.href, window.location.href);
        window.location.assign(destination.origin === window.location.origin ? destination.href : window.location.href);
        throw new DashboardAuthenticationError();
    }

    if (!response.ok) throw new Error(`Dashboard refresh returned ${response.status}.`);

    const html = await response.text();
    const documentFragment = new DOMParser().parseFromString(html, 'text/html');
    const refreshed = readPayload(documentFragment);
    if (!refreshed) throw new Error('The dashboard refresh response did not include metric data.');

    return refreshed;
}

function PerformanceChart({ payload, theme }: { payload: DashboardPayload; theme: Theme }): JSX.Element {
    const container = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!container.current) return undefined;
        let active = true;
        let dispose: () => void = () => {};

        void import('./command-os-chart').then(({ renderPerformanceChart }) => {
            if (!active || !container.current) return;
            dispose = renderPerformanceChart(container.current, payload, theme);
        });

        return () => {
            active = false;
            dispose();
        };
    }, [payload.trend, theme]);

    return (
        <>
            <div className="command-os-chart" ref={container} role="img" aria-label={`Daily deliveries and opens from ${payload.period.from} through ${payload.period.to}.`} />
            <p className="command-os-sr-only">The chart plots daily imported PMTA deliveries and recorded campaign and Drop opens for the last 30 days.</p>
        </>
    );
}

function Sparkline({ values, color }: { values: number[]; color: string }): JSX.Element {
    const maximum = Math.max(1, ...values);
    const points = values.map((value, index) => {
        const x = values.length < 2 ? 0 : (index / (values.length - 1)) * 120;
        const y = 31 - (Math.max(0, value) / maximum) * 27;
        return `${x.toFixed(2)},${y.toFixed(2)}`;
    }).join(' ');

    return <svg className="command-os-sparkline" viewBox="0 0 120 34" preserveAspectRatio="none" aria-hidden="true">
        <polyline points={points} pathLength="1" fill="none" stroke={color} strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" />
    </svg>;
}

const actionColors = ['#28b7ff', '#ffbd31', '#ff5d7b', '#a376ff', '#32d5a1', '#ffa34a', '#52a4ff'];

function MtdActionsStats({ chart, selectedMetric }: { chart: DashboardChart; selectedMetric: DashboardGeoMetric }): JSX.Element {
    const selectedLabels: Record<DashboardGeoMetric, string[]> = {
        deliveries: ['Deliveries'],
        opens: ['Opens'],
        clicks: ['Clicks'],
        leads: ['Leads (AlphaLink)'],
        bounces: ['Soft bounces', 'Hard bounces'],
        unsubscribes: ['Unsubscribes'],
    };

    return (
        <section className="command-os-panel command-os-stat-grid-panel" aria-labelledby="command-os-mtd-actions-title">
            <div className="command-os-panel-heading">
                <div className="command-os-panel-title"><span className="command-os-heading-icon"><Activity size={17} aria-hidden="true" /></span><h2 id="command-os-mtd-actions-title">Month-to-date activity</h2></div>
                <span className="command-os-status-tag">Month to date</span>
            </div>
            <div className="command-os-action-stats">
                {chart.series.map((series, index) => {
                    const total = series.values.reduce((sum, value) => sum + Number(value || 0), 0);
                    const color = actionColors[index % actionColors.length];
                    const linked = selectedLabels[selectedMetric].includes(series.label);
                    return <article className={`command-os-action-stat${linked ? ' is-geo-linked' : ' is-geo-muted'}`} key={series.label}>
                        <div className="command-os-action-stat-heading"><span>{series.label}</span><strong>{number(total)}</strong></div>
                        <Sparkline values={series.values} color={color} />
                    </article>;
                })}
            </div>
            <p className="command-os-chart-caption">Daily totals through today. The GEO map’s {selectedMetric} selection is highlighted here and in its KPI. Deliveries and bounces use imported PMTA feedback; other events come from stored campaign and Drop activity.</p>
        </section>
    );
}

function RevenueTrendPanel({ title, icon: Icon, chart, theme, id }: { title: string; icon: LucideIcon; chart: DashboardChart; theme: Theme; id: string }): JSX.Element {
    const container = useRef<HTMLDivElement>(null);
    const total = chart.series[0]?.values.reduce((sum, value) => sum + Number(value || 0), 0) ?? 0;

    useEffect(() => {
        if (!container.current) return undefined;
        let active = true;
        let dispose: () => void = () => {};
        void import('./command-os-chart').then(({ renderDashboardAnalyticsChart }) => {
            if (!active || !container.current) return;
            dispose = renderDashboardAnalyticsChart(container.current, chart, theme, true);
        });
        return () => { active = false; dispose(); };
    }, [chart, theme]);

    return <section className="command-os-panel command-os-revenue-chart" aria-labelledby={`${id}-title`}>
        <div className="command-os-panel-heading">
            <div className="command-os-panel-title"><span className="command-os-heading-icon"><Icon size={17} aria-hidden="true" /></span><h2 id={`${id}-title`}>{title}</h2></div>
            <span className="command-os-chart-total">{money(total)}</span>
        </div>
        <div className="command-os-revenue-chart-canvas" ref={container} role="img" aria-label={`${title}, attributed lead revenue: ${chart.labels.map((label, index) => `${label} ${money(chart.series[0]?.values[index] ?? 0)}`).join(', ')}`} />
        <p className="command-os-chart-caption">Attributed revenue from recorded campaign and Drop leads.</p>
    </section>;
}

function MetricCard({ metric, selectedMetric, onMetricChange }: { metric: Metric; selectedMetric: DashboardGeoMetric; onMetricChange: (metric: DashboardGeoMetric) => void }): JSX.Element {
    const Icon = metricIcons[metric.key];
    const value = metric.format === 'currency' ? money(metric.value) : number(metric.value);
    const canDrillDown = metric.key !== 'revenue';
    const isSelected = metric.key === selectedMetric;
    const values = metric.trend;
    const minimum = Math.min(0, ...values);
    const maximum = Math.max(1, ...values);
    const points = values.map((point, index) => {
        const x = values.length < 2 ? 0 : (index / (values.length - 1)) * 48;
        const y = 20 - ((point - minimum) / (maximum - minimum)) * 16;
        return `${x.toFixed(1)},${y.toFixed(1)}`;
    }).join(' ');

    return (
        <article className={`command-os-metric command-os-metric-${metricColors[metric.key]}${isSelected ? ' is-geo-linked' : ''}`} aria-label={`${metric.label}: ${value}`} data-geo-selected={isSelected || undefined}>
            <button
                className="command-os-metric-main"
                type="button"
                disabled={!canDrillDown}
                aria-pressed={canDrillDown ? isSelected : undefined}
                aria-label={canDrillDown
                    ? `Show ${metric.label.toLowerCase()} on the GEO map and activity report`
                    : `${metric.label}: ${value}. Country drilldown is unavailable because revenue has no verified country attribution.`}
                title={canDrillDown ? `Show ${metric.label.toLowerCase()} on the GEO map and activity report` : 'Revenue has no verified country attribution'}
                onClick={() => { if (canDrillDown) onMetricChange(metric.key as DashboardGeoMetric); }}
            >
                <span className="command-os-metric-icon"><Icon size={19} strokeWidth={2.1} aria-hidden="true" /></span>
                <span className="command-os-metric-copy">
                    <span className="command-os-metric-label">{metric.label}</span>
                    <strong className="command-os-metric-value">{value}</strong>
                    {isSelected && <span className="command-os-sr-only">Selected as the GEO map metric.</span>}
                </span>
                <svg className="command-os-metric-sparkline" viewBox="0 0 48 22" aria-hidden="true" focusable="false">
                    <polyline points={points} pathLength="1" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
            </button>
            <details className="command-os-metric-source">
                <summary aria-label={`Show ${metric.label.toLowerCase()} data source`} title={`Show ${metric.label.toLowerCase()} data source`}>
                    <CircleHelp size={12} aria-hidden="true" />
                </summary>
                <span className="command-os-metric-source-popover">
                    <strong>Data source</strong>
                    <span>{metric.detail}</span>
                </span>
            </details>
        </article>
    );
}

function DateAnalyticsFilter({ period }: { period: DashboardAnalytics['period'] }): JSX.Element {
    const [showCustom, setShowCustom] = useState(period.key === 'custom');
    const [customFrom, setCustomFrom] = useState(period.key === 'custom' ? period.from : '');
    const [customTo, setCustomTo] = useState(period.key === 'custom' ? period.to : '');
    const [dateError, setDateError] = useState('');
    const fromInput = useRef<HTMLInputElement>(null);
    const toInput = useRef<HTMLInputElement>(null);

    return (
        <form
            className="command-os-date-filter"
            method="get"
            action={period.action}
            noValidate
            onSubmit={(event) => {
                const selectedPeriod = new FormData(event.currentTarget).get('analytics_period');
                if (selectedPeriod !== 'custom') return;

                let message = '';
                let firstInvalid: HTMLInputElement | null = null;
                if (!customFrom) {
                    message = 'Choose a start date.';
                    firstInvalid = fromInput.current;
                } else if (!customTo) {
                    message = 'Choose an end date.';
                    firstInvalid = toInput.current;
                } else if (customTo < customFrom) {
                    message = 'End date must be on or after the start date.';
                    firstInvalid = toInput.current;
                } else if (customFrom > period.maxDate || customTo > period.maxDate) {
                    message = 'Choose dates through today.';
                    firstInvalid = customFrom > period.maxDate ? fromInput.current : toInput.current;
                }

                setDateError(message);
                if (message) {
                    event.preventDefault();
                    firstInvalid?.focus();
                }
            }}
        >
            <label className="command-os-sr-only" htmlFor="command-os-analytics-period">Analytics date range</label>
            <select
                id="command-os-analytics-period"
                name="analytics_period"
                value={showCustom ? 'custom' : period.key}
                onChange={(event) => {
                    const form = event.currentTarget.form;
                    const selectedPeriod = event.currentTarget.value;
                    setDateError('');
                    if (selectedPeriod === 'custom') {
                        setShowCustom(true);
                        if (period.key !== 'custom') {
                            setCustomFrom('');
                            setCustomTo('');
                        }
                    } else {
                        setShowCustom(false);
                        setCustomFrom('');
                        setCustomTo('');
                        if (form) {
                            const destination = new URL(form.action, window.location.origin);
                            destination.search = '';
                            destination.searchParams.set('analytics_period', selectedPeriod);
                            window.location.assign(destination.toString());
                        }
                    }
                }}
            >
                <option value="today">Today</option>
                <option value="yesterday">Yesterday</option>
                <option value="this_week">This week</option>
                <option value="this_month">This month</option>
                <option value="last_month">Last month</option>
                <option value="custom">Custom range</option>
            </select>
            {showCustom && <>
                <label className="command-os-sr-only" htmlFor="command-os-analytics-from">Start date</label>
                <input ref={fromInput} id="command-os-analytics-from" type="date" name="analytics_from" value={customFrom} max={period.maxDate} aria-invalid={dateError && (!customFrom || customFrom > period.maxDate) ? true : undefined} aria-describedby={dateError ? 'command-os-analytics-date-error' : undefined} onChange={(event) => { setCustomFrom(event.currentTarget.value); setDateError(''); }} required />
                <span aria-hidden="true">to</span>
                <label className="command-os-sr-only" htmlFor="command-os-analytics-to">End date</label>
                <input ref={toInput} id="command-os-analytics-to" type="date" name="analytics_to" value={customTo} min={customFrom || undefined} max={period.maxDate} aria-invalid={dateError && (!customTo || customTo < customFrom || customTo > period.maxDate) ? true : undefined} aria-describedby={dateError ? 'command-os-analytics-date-error' : undefined} onChange={(event) => { setCustomTo(event.currentTarget.value); setDateError(''); }} required />
                <button type="submit">Apply</button>
                {dateError && <span className="command-os-date-error" id="command-os-analytics-date-error" role="alert">{dateError}</span>}
            </>}
        </form>
    );
}

function PartnerRevenueBreakdown({
    title,
    dimension,
    icon: Icon,
    rows,
    rangeLabel,
}: {
    title: string;
    dimension: string;
    icon: LucideIcon;
    rows: AnalyticsRow[];
    rangeLabel: string;
}): JSX.Element {
    return (
        <article className={`command-os-panel command-os-partner-revenue-card${rows.length === 0 ? ' command-os-partner-revenue-empty' : ''}`}>
            <div className="command-os-panel-heading">
                <div className="command-os-panel-title"><span className="command-os-heading-icon"><Icon size={17} aria-hidden="true" /></span><h3>{title}</h3></div>
                <span className="command-os-status-tag">{rangeLabel}</span>
            </div>
            {rows.length === 0 ? <p className="command-os-analytics-empty" role="status">No attributed lead revenue was recorded for {dimension.toLowerCase()}s {rangeLabel.toLowerCase()}.</p> : <div className="command-os-partner-revenue-list" aria-live="polite">
                <div className="command-os-partner-revenue-head"><span>{dimension}</span><span>Leads</span><span>Revenue</span></div>
                {rows.map((row, index) => <div className="command-os-partner-revenue-row" key={`${row.label}-${index}`}>
                    <strong title={row.label}>{row.label}</strong>
                    <span>{number(row.leads)}</span>
                    <b>{money(row.attributed_revenue)}</b>
                </div>)}
            </div>}
            <p className="command-os-analytics-footnote">Attributed lead revenue from recorded campaign and Drop activity.</p>
        </article>
    );
}

function ProviderSponsorRevenueBreakdown({ rows, rangeLabel }: { rows: ProviderSponsorRevenueRow[]; rangeLabel: string }): JSX.Element {
    return (
        <article className={`command-os-panel command-os-provider-sponsor-card${rows.length === 0 ? ' command-os-partner-revenue-empty' : ''}`}>
            <div className="command-os-panel-heading">
                <div className="command-os-panel-title"><span className="command-os-heading-icon"><DollarSign size={17} aria-hidden="true" /></span><h3>Sponsor reported revenue</h3></div>
                <span className="command-os-status-tag">{rangeLabel}</span>
            </div>
            {rows.length === 0 ? <p className="command-os-analytics-empty" role="status">No sponsor conversions were reported for this date range.</p> : <div className="command-os-provider-revenue-list" aria-live="polite">
                <div className="command-os-provider-revenue-head"><span>Sponsor</span><span>Conversions</span><span>Currency</span><span>Reported amount</span></div>
                {rows.map((row, index) => <div className="command-os-provider-revenue-row" key={`${row.dimension_id}-${row.currency_code ?? 'unknown'}-${index}`}>
                    <strong title={row.label}>{row.label}</strong>
                    <span>{number(row.reported_conversions)}</span>
                    <span>{row.currency_code ?? 'Not supplied'}</span>
                    <b>{providerAmount(row.reported_amount)}</b>
                </div>)}
            </div>}
            <p className="command-os-analytics-footnote">Provider amounts stay grouped by currency. AlphaLink-attributed campaign and Drop revenue is shown separately.</p>
        </article>
    );
}

function PartnerRevenueAnalytics({ analytics }: { analytics: DashboardAnalytics }): JSX.Element {
    return <section className="command-os-partner-revenue-section" aria-labelledby="command-os-partner-revenue-title">
        <div className="command-os-section-heading">
            <div><p className="command-os-eyebrow">PROVIDER REPORTS &amp; ATTRIBUTED PERFORMANCE</p><h2 id="command-os-partner-revenue-title">Partner revenue</h2><p>All panels use the selected range: {analytics.period.label} ({analytics.period.from}–{analytics.period.to}).</p></div>
        </div>
        <ProviderSponsorRevenueBreakdown rows={analytics.provider_sponsors} rangeLabel={analytics.period.label} />
        <div className="command-os-partner-revenue-grid">
            <PartnerRevenueBreakdown title="Attributed sponsor revenue" dimension="Sponsor" icon={Target} rows={analytics.sponsors} rangeLabel={analytics.period.label} />
            <PartnerRevenueBreakdown title="ISP revenue" dimension="ISP" icon={Globe2} rows={analytics.isps} rangeLabel={analytics.period.label} />
            <PartnerRevenueBreakdown title="Mailer revenue" dimension="Mailer" icon={Mail} rows={analytics.mailers} rangeLabel={analytics.period.label} />
            <PartnerRevenueBreakdown title="Top 10 offers by revenue" dimension="Offer" icon={Layers3} rows={analytics.offers} rangeLabel={analytics.period.label} />
        </div>
    </section>;
}

function DashboardCharts({ payload, theme, selectedMetric }: { payload: DashboardPayload; theme: Theme; selectedMetric: DashboardGeoMetric }): JSX.Element {
    return <section className="command-os-charts-section" aria-label="Activity and revenue trends">
        <MtdActionsStats chart={payload.charts.mtd_actions} selectedMetric={selectedMetric} />
        <RevenueTrendPanel id="command-os-mtd-revenue" title="Month-to-date attributed revenue" icon={DollarSign} chart={payload.charts.mtd_revenue} theme={theme} />
        <RevenueTrendPanel id="command-os-ytd-revenue" title="Year-to-date attributed revenue" icon={ChartNoAxesCombined} chart={payload.charts.ytd_revenue} theme={theme} />
    </section>;
}

function PerformanceOverview({ payload, theme }: { payload: DashboardPayload; theme: Theme }): JSX.Element {
    return <section className="command-os-panel command-os-performance" id="performance" aria-labelledby="command-os-performance-title">
        <div className="command-os-panel-heading">
            <div className="command-os-panel-title"><span className="command-os-heading-icon"><ChartNoAxesCombined size={17} aria-hidden="true" /></span><h2 id="command-os-performance-title">Performance overview · last 30 days</h2></div>
            <span className="command-os-panel-meta">{payload.period.from} — {payload.period.to}</span>
        </div>
        <PerformanceChart payload={payload} theme={theme} />
        <div className="command-os-today-pulse" aria-label={`Today's performance, ${payload.today.date}`}>
            <div className="command-os-today-pulse-heading"><strong>Today</strong><span>{payload.today.date}</span></div>
            <dl>
                <div><dt>Deliveries</dt><dd>{number(payload.today.deliveries)}</dd></div>
                <div><dt>Opens</dt><dd>{number(payload.today.opens)}</dd></div>
                <div><dt>Clicks</dt><dd>{number(payload.today.clicks)}</dd></div>
                <div><dt>Attributed revenue</dt><dd>{money(payload.today.revenue)}</dd></div>
            </dl>
        </div>
    </section>;
}
function DashboardApp({ initial }: { initial: DashboardPayload }): JSX.Element {
    const [payload, updatePayload] = useState(initial);
    const [theme, updateTheme] = useState<Theme>('light');
    const [selectedGeoMetric, setSelectedGeoMetric] = useState<DashboardGeoMetric>('deliveries');
    const [refreshing, setRefreshing] = useState(false);
    const [refreshError, setRefreshError] = useState('');
    const [lastChecked, setLastChecked] = useState<Date | null>(null);

    useEffect(() => {
        const initialTheme = safeStoredTheme();
        updateTheme(initialTheme);
        setTheme(initialTheme);
    }, []);

    useEffect(() => {
        const handleThemeChange = (event: Event) => {
            const nextTheme = (event as CustomEvent<Theme>).detail;
            if (nextTheme === 'light' || nextTheme === 'dark') updateTheme(nextTheme);
        };

        window.addEventListener('alphalink-command-os-theme-change', handleThemeChange);
        return () => window.removeEventListener('alphalink-command-os-theme-change', handleThemeChange);
    }, []);

    const refresh = useCallback(async () => {
        setRefreshing(true);
        try {
            updatePayload(await fetchPayload(payload.refresh.url));
            setRefreshError('');
            setLastChecked(new Date());
        } catch (error) {
            if (error instanceof DashboardAuthenticationError) return;
            setRefreshError('Refresh failed. Showing the last loaded data.');
        } finally {
            setRefreshing(false);
        }
    }, [payload.refresh.url]);

    useEffect(() => {
        const interval = window.setInterval(() => void refresh(), payload.refresh.interval);
        return () => window.clearInterval(interval);
    }, [payload.refresh.interval, refresh]);

    const checkedLabel = lastChecked
        ? `Last checked ${lastChecked.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}`
        : 'Auto refresh every 5 minutes';

    return (
        <div className="command-os-dashboard-content" data-theme={theme}>
            <div className="command-os-page-heading">
                <svg className="command-os-header-wave" viewBox="0 0 1000 110" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                    <defs>
                        <linearGradient id="command-os-header-wave-spectrum" x1="0" y1="0" x2="1" y2="0">
                            <stop offset="0%" stopColor="#a64cff" />
                            <stop offset="42%" stopColor="#8f63ff" />
                            <stop offset="60%" stopColor="#12c9ff" />
                            <stop offset="82%" stopColor="#b451ff" />
                            <stop offset="100%" stopColor="#ff8fe1" />
                        </linearGradient>
                    </defs>
                    <path className="command-os-header-wave-glow" d="M0 68 C110 64 119 12 238 24 S370 89 500 67 647 7 774 28 894 75 1000 45" />
                    <path className="command-os-header-wave-blue" d="M0 68 C110 64 119 12 238 24 S370 89 500 67 647 7 774 28 894 75 1000 45" />
                    <path className="command-os-header-wave-violet" d="M0 82 C108 77 142 35 254 44 S381 103 523 80 674 25 796 42 915 94 1000 64" />
                    <path className="command-os-header-wave-cyan" d="M0 54 C115 49 150 20 262 32 S390 76 515 57 661 14 789 31 909 69 1000 40" />
                </svg>
                <div>
                    <p className="command-os-eyebrow">ALPHALINK COMMAND OS — {payload.workspaceName}</p>
                    <h1>Welcome back, {payload.userName}<span className="command-os-greeting-wave" aria-hidden="true"> 👋</span></h1>
                    <p className="command-os-subtitle">Email marketing intelligence &amp; operations center</p>
                </div>
                <div className="command-os-heading-actions">
                    <DateAnalyticsFilter period={payload.analytics.period} />
                    <span className="command-os-period"><Activity size={15} aria-hidden="true" />{payload.period.label}</span>
                    <button type="button" className="command-os-refresh" onClick={() => void refresh()} disabled={refreshing} aria-label="Refresh dashboard data">
                        <RefreshCw size={15} className={refreshing ? 'command-os-spin' : ''} aria-hidden="true" />
                        <span>{refreshing ? 'Refreshing' : 'Refresh'}</span>
                    </button>
                </div>
            </div>

            {refreshError && <div className="command-os-refresh-error" role="status">{refreshError}</div>}

            <section className="command-os-metrics" aria-label={`${payload.period.label} performance metrics`}>
                {payload.metrics.map((metric) => <MetricCard key={metric.key} metric={metric} selectedMetric={selectedGeoMetric} onMetricChange={setSelectedGeoMetric} />)}
            </section>

            <section className="command-os-panels command-os-intelligence-panels" aria-label="Geographic and marketing intelligence">
                <Suspense fallback={<section className="command-os-panel command-os-async-panel" role="status">Loading country map…</section>}>
                    <DeliverabilityGeoMap data={payload.analytics.geo} theme={theme} selectedMetric={selectedGeoMetric} onMetricChange={setSelectedGeoMetric} />
                </Suspense>

                <Suspense fallback={<section className="command-os-panel command-os-async-panel" role="status">Loading Marketing Graph…</section>}>
                    <MarketingGraph data={payload.analytics.graph} theme={theme} />
                </Suspense>
            </section>

            <PartnerRevenueAnalytics analytics={payload.analytics} />

            <DashboardCharts payload={payload} theme={theme} selectedMetric={selectedGeoMetric} />

            <section className="command-os-panels command-os-secondary-panels" aria-label="Additional performance and quick actions">
                <PerformanceOverview payload={payload} theme={theme} />
                <section className="command-os-quick-actions command-os-quick-actions-footer" aria-labelledby="command-os-actions-title">
                    <div className="command-os-quick-intro"><Flame size={19} aria-hidden="true" /><span><strong id="command-os-actions-title">Quick actions</strong><small>Faster operations</small></span></div>
                    <div className="command-os-action-list">
                        {payload.actions.map((action) => {
                            const Icon = actionIcons[action.icon] ?? Layers3;
                            if (action.disabled || !action.href) {
                                const reasonId = `command-os-action-${action.label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-reason`;

                                return <button
                                    className="command-os-action command-os-action-disabled"
                                    type="button"
                                    disabled
                                    aria-describedby={reasonId}
                                    key={action.label}
                                    aria-label={action.label}
                                >
                                    <Icon size={17} aria-hidden="true" />
                                    <span className="command-os-action-copy">
                                        <span>{action.label}</span>
                                        <small id={reasonId}>{action.reason ?? 'Unavailable'}</small>
                                    </span>
                                </button>;
                            }

                            return <a className="command-os-action" href={action.href} key={action.label}><Icon size={17} aria-hidden="true" /><span>{action.label}</span></a>;
                        })}
                    </div>
                </section>
            </section>

            <div className="command-os-data-notes" id="command-os-data-coverage">
                <span><strong>Coverage:</strong> {payload.coverage.deliveries}</span>
                <span>{payload.coverage.revenue}</span>
                <span>{payload.coverage.geo}</span>
                <span>{payload.coverage.graph}</span>
                <span className="command-os-refresh-status" role="status">{refreshError || checkedLabel}</span>
            </div>
        </div>
    );
}

function mountDashboard(): void {
    const rootElement = document.getElementById('command-os-dashboard-root');
    const payload = readPayload();
    if (!rootElement || !payload) return;

    createRoot(rootElement).render(<DashboardApp initial={payload} />);
}

function mountThemeToggle(): void {
    const toggleElement = document.getElementById('command-os-theme-toggle');
    if (toggleElement) createRoot(toggleElement).render(<ThemeToggle />);
}

mountDashboard();
mountThemeToggle();
