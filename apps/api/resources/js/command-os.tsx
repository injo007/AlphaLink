import { useCallback, useEffect, useRef, useState } from 'react';
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
    period: { label: string; from: string; to: string };
    metrics: Metric[];
    trend: { labels: string[]; deliveries: number[]; opens: number[] };
    refresh: { url: string; interval: number };
    coverage: { deliveries: string; revenue: string; geo: string; graph: string };
    actions: DashboardAction[];
};
type Theme = 'light' | 'dark';

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

function MetricCard({ metric }: { metric: Metric }): JSX.Element {
    const Icon = metricIcons[metric.key];
    const value = metric.format === 'currency' ? money(metric.value) : number(metric.value);

    return (
        <article className={`command-os-metric command-os-metric-${metricColors[metric.key]}`} title={metric.detail}>
            <span className="command-os-metric-icon"><Icon size={19} strokeWidth={2.1} aria-hidden="true" /></span>
            <span className="command-os-metric-copy">
                <span className="command-os-metric-label">{metric.label}</span>
                <strong className="command-os-metric-value">{value}</strong>
                <span className="command-os-metric-detail">{metric.detail}</span>
            </span>
        </article>
    );
}

function StatusPanel({
    id,
    title,
    icon: Icon,
    message,
    detail,
    tag,
}: {
    id: string;
    title: string;
    icon: LucideIcon;
    message: string;
    detail: string;
    tag: string;
}): JSX.Element {
    return (
        <section className="command-os-panel command-os-status-panel" id={id} aria-labelledby={`${id}-title`}>
            <div className="command-os-panel-heading">
                <div className="command-os-panel-title"><span className="command-os-heading-icon"><Icon size={17} aria-hidden="true" /></span><h2 id={`${id}-title`}>{title}</h2></div>
                <span className="command-os-status-tag"><CircleHelp size={12} aria-hidden="true" />{tag}</span>
            </div>
            <div className="command-os-status-content">
                <div className="command-os-status-icon"><Icon size={27} strokeWidth={1.7} aria-hidden="true" /></div>
                <strong>{message}</strong>
                <p>{detail}</p>
            </div>
        </section>
    );
}

function DashboardApp({ initial }: { initial: DashboardPayload }): JSX.Element {
    const [payload, updatePayload] = useState(initial);
    const [theme, updateTheme] = useState<Theme>('light');
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

    useEffect(() => {
        const page = document.getElementById('page-container');
        const handleClick = (event: MouseEvent) => {
            const target = event.target;
            if (!(target instanceof Element)) return;

            if (target.closest('[data-action="sidebar_open"]')) {
                page?.classList.add('command-os-sidebar-open');
            }
            if (target.closest('[data-action="sidebar_close"]')) {
                page?.classList.remove('command-os-sidebar-open');
            }
        };
        const handleKeydown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') page?.classList.remove('command-os-sidebar-open');
        };

        document.addEventListener('click', handleClick);
        document.addEventListener('keydown', handleKeydown);
        return () => {
            document.removeEventListener('click', handleClick);
            document.removeEventListener('keydown', handleKeydown);
        };
    }, []);

    const checkedLabel = lastChecked
        ? `Last checked ${lastChecked.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}`
        : 'Auto refresh every 5 minutes';

    return (
        <div className="command-os-dashboard-content" data-theme={theme}>
            <div className="command-os-page-heading">
                <div>
                    <p className="command-os-eyebrow">ALPHALINK COMMAND OS — {payload.workspaceName}</p>
                    <h1>Welcome back, {payload.userName}</h1>
                    <p className="command-os-subtitle">Email marketing intelligence &amp; operations center</p>
                </div>
                <div className="command-os-heading-actions">
                    <span className="command-os-period"><Activity size={15} aria-hidden="true" />{payload.period.label}</span>
                    <button type="button" className="command-os-refresh" onClick={() => void refresh()} disabled={refreshing} aria-label="Refresh dashboard data">
                        <RefreshCw size={15} className={refreshing ? 'command-os-spin' : ''} aria-hidden="true" />
                        <span>{refreshing ? 'Refreshing' : 'Refresh'}</span>
                    </button>
                </div>
            </div>

            {refreshError && <div className="command-os-refresh-error" role="status">{refreshError}</div>}

            <section className="command-os-metrics" aria-label={`${payload.period.label} performance metrics`}>
                {payload.metrics.map((metric) => <MetricCard key={metric.key} metric={metric} />)}
            </section>

            <section className="command-os-panels" aria-label="Performance and operational intelligence">
                <section className="command-os-panel command-os-performance" id="performance" aria-labelledby="command-os-performance-title">
                    <div className="command-os-panel-heading">
                        <div className="command-os-panel-title"><span className="command-os-heading-icon"><ChartNoAxesCombined size={17} aria-hidden="true" /></span><h2 id="command-os-performance-title">Performance overview</h2></div>
                        <span className="command-os-panel-meta">{payload.period.from} — {payload.period.to}</span>
                    </div>
                    <PerformanceChart payload={payload} theme={theme} />
                </section>

                <StatusPanel
                    id="geo-data-status"
                    title="Deliverability by GEO"
                    icon={Globe2}
                    message="Country-level delivery data unavailable"
                    detail={payload.coverage.geo}
                    tag="Data coverage"
                />

                <StatusPanel
                    id="relationship-data-status"
                    title="Marketing graph"
                    icon={Network}
                    message="Relationship feed not connected"
                    detail={payload.coverage.graph}
                    tag="Data coverage"
                />
            </section>

            <section className="command-os-quick-actions" aria-labelledby="command-os-actions-title">
                <div className="command-os-quick-intro"><Flame size={19} aria-hidden="true" /><span><strong id="command-os-actions-title">Quick actions</strong><small>Faster operations</small></span></div>
                <div className="command-os-action-list">
                    {payload.actions.map((action) => {
                        const Icon = actionIcons[action.icon] ?? Layers3;
                        if (action.disabled || !action.href) {
                            const reasonId = `command-os-action-${action.label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-reason`;

                            return <button
                                className="command-os-action command-os-action-disabled"
                                type="button"
                                aria-disabled="true"
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

            <div className="command-os-data-notes" id="command-os-data-coverage">
                <span><strong>Coverage:</strong> {payload.coverage.deliveries}</span>
                <span>{payload.coverage.revenue}</span>
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
