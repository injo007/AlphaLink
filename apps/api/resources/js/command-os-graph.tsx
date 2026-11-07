import { useEffect, useRef, useState } from 'react';
import type { CSSProperties, JSX } from 'react';
import type { DashboardAnalytics, MarketingNode } from './command-os';

type Theme = 'light' | 'dark';
type GraphSize = { width: number; height: number };

const nodeColors: Record<string, string> = {
    blue: '#347cff',
    violet: '#9146f3',
    pink: '#df429f',
    cyan: '#12aee6',
    indigo: '#7052ef',
};

const coreModuleOrder = ['servers', 'campaigns', 'drops', 'warmups', 'offers', 'tracking', 'lists', 'domains'];
const brandMark = '/console-assets/alphalink-command-os-mark.png';
const moduleIcons: Record<string, string> = {
    campaigns: '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
    drops: '<path d="m12 3 8.5 4.5L12 12 3.5 7.5 12 3Z"/><path d="m3.5 12 8.5 4.5 8.5-4.5M3.5 16.5 12 21l8.5-4.5"/>',
    warmups: '<path d="M8.5 14.5c1.6-1.3 2.1-3.1 1.7-5.5 3.5 2 5.2 4.5 4.7 7.2-.3 1.6-1.5 2.8-3.2 2.8-2.2 0-3.7-1.7-3.2-4.5Z"/><path d="M12 22a8 8 0 0 0 8-8c0-4-2.5-7.3-6.5-10 .4 3.2-.7 5.2-2.3 6.6C10.1 6.6 8.4 4.7 6 3c.7 4-2 6.4-2 11a8 8 0 0 0 8 8Z"/>',
    servers: '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01M11 7.5h6M11 16.5h6"/>',
    domains: '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10Z"/>',
    lists: '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 8v6M23 11h-6"/>',
    offers: '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3.4 13.4A2 2 0 0 1 2.8 12V5a2 2 0 0 1 2-2h7a2 2 0 0 1 1.4.6l7.4 7a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r=".8"/>',
    tracking: '<path d="M3 3v18h18"/><path d="m18 17-6-6-4 4-3-3"/><path d="M14 17h4v-4"/>',
};

function iconImage(id: string, color: string): string {
    const paths = moduleIcons[id] ?? moduleIcons.domains;
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="${color}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${paths}</svg>`;
    return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`;
}

function formatCount(value: number): string {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
}

function nodeWidth(label: string, maxWidth = 128): number {
    return Math.max(Math.min(88, maxWidth), Math.min(maxWidth, Math.ceil(label.length * 6.4 + 48)));
}

function modulePosition(index: number, count: number, width: number, height: number, maxNodeWidth: number): [number, number] {
    if (width < 420) {
        const column = index % 2;
        const rows = Math.ceil(count / 2);
        const row = Math.floor(index / 2);
        const verticalInset = Math.min(22, height / 8);
        const x = column === 0 ? maxNodeWidth / 2 + 12 : width - maxNodeWidth / 2 - 12;
        const y = rows < 2 ? height / 2 : verticalInset + (row * (height - verticalInset * 2)) / (rows - 1);
        return [x, y];
    }

    const angle = -Math.PI / 2 + (index * Math.PI * 2) / count;
    const radiusX = Math.max(72, width / 2 - maxNodeWidth / 2 - 14);
    const radiusY = Math.max(38, height / 2 - 21 - 10);
    return [width / 2 + Math.cos(angle) * radiusX, height / 2 + Math.sin(angle) * radiusY];
}

function moduleCountLabel(node: MarketingNode): string {
    return `${formatCount(node.count)} ${node.id === 'tracking' || node.id === 'workspace-activity' ? 'tracked events' : 'entities with activity'}`;
}

export default function MarketingGraph({ data, theme }: { data: DashboardAnalytics['graph']; theme: Theme }): JSX.Element {
    const container = useRef<HTMLDivElement>(null);
    const [size, setSize] = useState<GraphSize>({ width: 480, height: 190 });
    const [selected, setSelected] = useState<string | null>(null);
    const dark = theme === 'dark';
    const coreModules = coreModuleOrder
        .map((id) => data.nodes.find((node) => node.id === id))
        .filter((node): node is MarketingNode => node !== undefined);
    const modules = [...coreModules, ...data.nodes.filter((node) => !coreModuleOrder.includes(node.id))];
    const nodeWidthLimit = size.width < 320 ? 104 : 128;
    const maxNodeWidth = Math.max(Math.min(92, nodeWidthLimit), ...modules.map((node) => nodeWidth(node.label, nodeWidthLimit)));
    const centerX = size.width / 2;
    const centerY = size.height / 2;
    const positions = modules.map((node, index) => ({ node, point: modulePosition(index, modules.length, size.width, size.height, maxNodeWidth) }));
    const selectedModule = modules.find((node) => node.id === selected);

    useEffect(() => {
        const element = container.current;
        if (!element) return undefined;
        const observer = new ResizeObserver(([entry]) => {
            if (!entry) return;
            const next = { width: Math.max(280, entry.contentRect.width), height: Math.max(160, entry.contentRect.height) };
            setSize((previous) => previous.width === next.width && previous.height === next.height ? previous : next);
        });
        observer.observe(element);
        return () => observer.disconnect();
    }, []);

    const selectModule = (node: MarketingNode) => setSelected(node.id);
    return (
        <section className="command-os-panel command-os-graph-panel" id="marketing-graph" aria-labelledby="command-os-graph-title">
            <div className="command-os-panel-heading">
                <div className="command-os-panel-title"><span className="command-os-heading-icon command-os-graph-heading-icon"><span aria-hidden="true">✣</span></span><h2 id="command-os-graph-title">Marketing graph</h2></div>
                <span className="command-os-status-tag">{modules.length} modules</span>
            </div>
            <div className="command-os-graph-canvas" ref={container}>
                <svg
                    className="command-os-network-graphic"
                    viewBox={`0 0 ${size.width} ${size.height}`}
                    role="group"
                    aria-label="AlphaLink marketing modules connected to the workspace hub"
                    aria-describedby="command-os-graph-note"
                >
                    <defs>
                        <radialGradient id="command-os-network-hub-fill">
                            <stop offset="0%" stopColor={dark ? '#7864ff' : '#9374ff'} />
                            <stop offset="100%" stopColor={dark ? '#273a85' : '#e7e1ff'} />
                        </radialGradient>
                    </defs>
                    <g aria-hidden="true">
                        {positions.map(({ node, point }) => (
                            <line
                                className="command-os-network-spoke"
                                key={`spoke-${node.id}`}
                                x1={centerX}
                                y1={centerY}
                                x2={point[0]}
                                y2={point[1]}
                                stroke={nodeColors[node.color] ?? '#8a66ee'}
                            />
                        ))}
                    </g>
                    <g className="command-os-network-hub" aria-label="AlphaLink">
                        <circle className="command-os-network-hub-halo" cx={centerX} cy={centerY} r={36} />
                        <circle className="command-os-network-hub-disc" cx={centerX} cy={centerY} r={27} />
                        <image href={brandMark} x={centerX - 29} y={centerY - 29} width={58} height={58} />
                    </g>
                </svg>
                {positions.map(({ node, point }) => {
                    const color = nodeColors[node.color] ?? '#8a66ee';
                    const [x, y] = point;
                    const isSelected = selected === node.id;
                    const sharedProps = {
                        className: `command-os-network-node${isSelected ? ' is-selected' : ''}`,
                        key: node.id,
                        style: {
                            '--node-accent': color,
                            left: `${(x / size.width) * 100}%`,
                            top: `${(y / size.height) * 100}%`,
                            width: `${nodeWidth(node.label, nodeWidthLimit)}px`,
                        } as CSSProperties,
                        title: node.href
                            ? `Open ${node.label} · ${moduleCountLabel(node)} in this range`
                            : `${node.label}: ${moduleCountLabel(node)} in this range`,
                    };
                    const content = <><img src={iconImage(node.id, color)} alt="" aria-hidden="true" /><span>{node.label}</span></>;

                    return (
                        <button
                            {...sharedProps}
                            type="button"
                            aria-label={`Show ${node.label}: ${moduleCountLabel(node)} in this range`}
                            aria-pressed={isSelected}
                            onClick={() => selectModule(node)}
                        >
                            {content}
                        </button>
                    );
                })}
            </div>
            <p className="command-os-graph-selected" aria-live="polite">
                <span>
                    {selectedModule
                        ? `${selectedModule.label}: ${moduleCountLabel(selectedModule)} in this range.`
                        : modules.length > 0
                            ? 'Select a module to inspect its activity count.'
                            : 'No module activity is available for this date range.'}
                </span>
                {selectedModule?.href && <a className="command-os-graph-open-link" href={selectedModule.href}>Open {selectedModule.label}<span aria-hidden="true"> ↗</span></a>}
            </p>
            <ul className="command-os-sr-only" aria-label="Module activity counts">
                {data.nodes.map((node) => <li key={node.id}>{node.label}: {moduleCountLabel(node)} in this range</li>)}
            </ul>
            <p className="command-os-sr-only" id="command-os-graph-note">{data.note} The spokes connect each workspace module to the AlphaLink hub; they do not claim record-to-record attribution. Module nodes open only pages available to your workspace role.</p>
        </section>
    );
}
