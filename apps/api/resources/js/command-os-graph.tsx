import { useEffect, useRef, useState } from 'react';
import type { CSSProperties, JSX } from 'react';
import {
    Activity,
    Building2,
    Flame,
    Globe2,
    Layers3,
    Network,
    Send,
    Server,
    Tag,
    Target,
    UsersRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { DashboardAnalytics, MarketingNode } from './command-os';

type Theme = 'light' | 'dark';
type GraphSize = { width: number; height: number };
type GraphPosition = 'orbit' | 'columns';

const nodeColors: Record<string, string> = {
    blue: '#347cff',
    violet: '#9146f3',
    pink: '#df429f',
    cyan: '#12aee6',
    indigo: '#7052ef',
};

const moduleOrder = ['campaigns', 'servers', 'isps', 'drops', 'warmups', 'offers', 'tracking', 'lists', 'domains', 'sponsors'];
const moduleIcons: Record<string, LucideIcon> = {
    campaigns: Send,
    servers: Server,
    isps: Globe2,
    drops: Layers3,
    warmups: Flame,
    offers: Tag,
    tracking: Activity,
    lists: UsersRound,
    domains: Globe2,
    sponsors: Target,
    'workspace-activity': Building2,
};
const brandMark = '/console-assets/alphalink-command-os-mark.png';

function formatCount(value: number): string {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
}

function formatCompactCount(value: number): string {
    return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
}

function nodeWidth(label: string, maxWidth = 112): number {
    return Math.max(Math.min(88, maxWidth), Math.min(maxWidth, Math.ceil(label.length * 5.8 + 50)));
}

function modulePosition(
    index: number,
    count: number,
    width: number,
    height: number,
    maxNodeWidth: number,
    layout: GraphPosition,
): [number, number] {
    if (count <= 1) return [width / 2, Math.max(34, height * 0.17)];

    if (layout === 'columns') {
        const columns = Math.ceil(count / 2);
        const column = index < columns ? 0 : 1;
        const row = index < columns ? index : index - columns;
        const centerX = column === 0 ? maxNodeWidth / 2 + 3 : width - maxNodeWidth / 2 - 3;
        const top = Math.max(14, Math.min(30, height * 0.12));
        const availableHeight = Math.max(1, height - top * 2);
        const y = columns <= 1 ? height / 2 : top + (row / (columns - 1)) * availableHeight;

        return [centerX, y];
    }

    const angle = -Math.PI / 2 + (index * Math.PI * 2) / count;
    const radiusX = Math.max(42, width / 2 - maxNodeWidth / 2 - 7);
    const radiusY = Math.max(46, height / 2 - 24);

    return [width / 2 + Math.cos(angle) * radiusX, height / 2 + Math.sin(angle) * radiusY];
}

function moduleCountLabel(node: MarketingNode): string {
    return `${formatCount(node.count)} ${node.id === 'tracking' || node.id === 'workspace-activity' ? 'tracked events' : 'entities with activity'}`;
}

export default function MarketingGraph({ data, theme }: { data: DashboardAnalytics['graph']; theme: Theme }): JSX.Element {
    const container = useRef<HTMLDivElement>(null);
    const [size, setSize] = useState<GraphSize>({ width: 480, height: 150 });
    const [selected, setSelected] = useState<string | null>(null);
    const [hovered, setHovered] = useState<string | null>(null);
    const dark = theme === 'dark';
    const orderedModules = moduleOrder
        .map((id) => data.nodes.find((node) => node.id === id))
        .filter((node): node is MarketingNode => node !== undefined);
    const orderedIds = new Set(orderedModules.map((node) => node.id));
    const modules = [...orderedModules, ...data.nodes.filter((node) => !orderedIds.has(node.id))];
    const nodeWidthLimit = size.width < 520 && modules.length > 5
        ? Math.max(76, Math.min(112, size.width / 2 - 29))
        : size.width < 520
            ? Math.min(112, size.width / 2 - 9)
            : 112;
    const maxNodeWidth = Math.max(72, ...modules.map((node) => nodeWidth(node.label, nodeWidthLimit)));
    const layout: GraphPosition = size.width < 520 && modules.length > 5 ? 'columns' : 'orbit';
    const centerX = size.width / 2;
    const centerY = size.height / 2;
    const positions = modules.map((node, index) => ({
        node,
        point: modulePosition(index, modules.length, size.width, size.height, maxNodeWidth, layout),
    }));
    const selectedModule = modules.find((node) => node.id === selected);
    const focusedId = hovered ?? selected;

    useEffect(() => {
        const element = container.current;
        if (!element) return undefined;
        const observer = new ResizeObserver(([entry]) => {
            if (!entry) return;
            const next = { width: Math.max(260, entry.contentRect.width), height: Math.max(140, entry.contentRect.height) };
            setSize((previous) => previous.width === next.width && previous.height === next.height ? previous : next);
        });
        observer.observe(element);
        return () => observer.disconnect();
    }, []);

    const selectModule = (node: MarketingNode) => setSelected(node.id);
    const renderModuleButton = (node: MarketingNode, point: [number, number]) => {
        const color = nodeColors[node.color] ?? '#8a66ee';
        const Icon = moduleIcons[node.id] ?? Network;
        const isSelected = selected === node.id;
        const isFocused = focusedId === node.id;
        const isMuted = focusedId !== null && !isFocused;
        const style: CSSProperties = {
            '--node-accent': color,
            left: `${(point[0] / size.width) * 100}%`,
            top: `${(point[1] / size.height) * 100}%`,
            width: `${nodeWidth(node.label, nodeWidthLimit)}px`,
        } as CSSProperties;
        const className = `command-os-network-node${isSelected ? ' is-selected' : ''}${isFocused ? ' is-focused' : ''}${isMuted ? ' is-muted' : ''}`;
        const countLabel = moduleCountLabel(node);
        const title = node.href ? `Open ${node.label} · ${countLabel} in this range` : `${node.label}: ${countLabel} in this range`;
        const label = node.href ? `Open ${node.label}: ${countLabel} in this range` : `Show ${node.label}: ${countLabel} in this range`;
        const content = <><Icon className="command-os-network-node-icon" size={13} strokeWidth={2.1} aria-hidden="true" /><span className="command-os-network-node-label">{node.label}</span><span className="command-os-network-node-count" aria-hidden="true">{formatCompactCount(node.count)}</span></>;
        const common = {
            className,
            key: node.id,
            style,
            title,
            'aria-label': label,
            onClick: () => selectModule(node),
            onPointerEnter: () => setHovered(node.id),
            onPointerLeave: () => setHovered((current) => current === node.id ? null : current),
            onFocus: () => setHovered(node.id),
            onBlur: () => setHovered(null),
        };

        if (node.href) {
            return <a {...common} href={node.href}>{content}</a>;
        }

        return <button {...common} type="button" aria-pressed={isSelected}>{content}</button>;
    };

    return (
        <section className="command-os-panel command-os-graph-panel" id="marketing-graph" aria-labelledby="command-os-graph-title">
            <div className="command-os-panel-heading">
                <div className="command-os-panel-title"><span className="command-os-heading-icon command-os-graph-heading-icon"><Network size={16} aria-hidden="true" /></span><h2 id="command-os-graph-title">Marketing graph</h2></div>
                <span className="command-os-status-tag">{modules.length} modules</span>
            </div>
            <p className="command-os-graph-intro">A connected view of campaigns, audiences, deliverability and infrastructure.</p>
            <div className={`command-os-graph-canvas${layout === 'columns' ? ' is-compact-network' : ''}`} ref={container}>
                <svg
                    className="command-os-network-graphic"
                    viewBox={`0 0 ${size.width} ${size.height}`}
                    role="group"
                    aria-label="AlphaLink workspace modules connected to the central hub"
                    aria-describedby="command-os-graph-note"
                >
                    <defs>
                        <linearGradient id="command-os-network-hub-fill" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stopColor={dark ? '#a95bff' : '#8a46f4'} />
                            <stop offset="100%" stopColor="#0879ec" />
                        </linearGradient>
                        <radialGradient id="command-os-network-hub-glow">
                            <stop offset="0%" stopColor={dark ? '#8852ff' : '#8955ff'} stopOpacity=".22" />
                            <stop offset="100%" stopColor={dark ? '#8852ff' : '#8955ff'} stopOpacity="0" />
                        </radialGradient>
                    </defs>
                    <circle cx={centerX} cy={centerY} r={layout === 'columns' ? 54 : 87} fill="url(#command-os-network-hub-glow)" aria-hidden="true" />
                    <g aria-hidden="true">
                        {positions.map(({ node, point }) => (
                            <line
                                className={`command-os-network-spoke${focusedId === node.id ? ' is-focused' : ''}${focusedId !== null && focusedId !== node.id ? ' is-muted' : ''}`}
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
                        <circle className="command-os-network-hub-ring command-os-network-hub-ring-outer" cx={centerX} cy={centerY} r={layout === 'columns' ? 26 : 54} />
                        <circle className="command-os-network-hub-ring command-os-network-hub-ring-inner" cx={centerX} cy={centerY} r={layout === 'columns' ? 19 : 39} />
                        <rect className="command-os-network-hub-tile" x={centerX - (layout === 'columns' ? 16 : 22)} y={centerY - (layout === 'columns' ? 16 : 22)} width={layout === 'columns' ? 32 : 44} height={layout === 'columns' ? 32 : 44} rx={layout === 'columns' ? 10 : 13} transform={`rotate(45 ${centerX} ${centerY})`} />
                        <image className="command-os-network-hub-logo" href={brandMark} x={centerX - (layout === 'columns' ? 10 : 13)} y={centerY - (layout === 'columns' ? 10 : 13)} width={layout === 'columns' ? 20 : 26} height={layout === 'columns' ? 20 : 26} />
                    </g>
                </svg>
                {positions.map(({ node, point }) => renderModuleButton(node, point))}
            </div>
            <p className="command-os-graph-selected" aria-live="polite">
                <span>
                    {selectedModule
                        ? `${selectedModule.label}: ${moduleCountLabel(selectedModule)} in this range.`
                        : modules.length > 0
                            ? 'Select a module to inspect its count, or open it if your role permits.'
                            : 'No module activity is available for this date range.'}
                </span>
                {selectedModule && !selectedModule.href && <span className="command-os-graph-open-link">No direct destination for {selectedModule.label}</span>}
            </p>
            <ul className="command-os-sr-only" aria-label="Module activity counts">
                {data.nodes.map((node) => <li key={node.id}>{node.label}: {moduleCountLabel(node)} in this range</li>)}
            </ul>
            <p className="command-os-sr-only" id="command-os-graph-note">{data.note} Every module is connected to the AlphaLink hub. Spokes describe the module map and do not imply record-to-record attribution. Module links open only destinations permitted for your workspace role.</p>
        </section>
    );
}
