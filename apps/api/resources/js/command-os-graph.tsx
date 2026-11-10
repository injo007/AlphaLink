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
import type { DashboardAnalytics, MarketingEdge, MarketingNode } from './command-os';

type Theme = 'light' | 'dark';
type GraphSize = { width: number; height: number };
type GraphPosition = 'orbit' | 'columns';
type GraphPoint = [number, number];

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

function nodeWidth(label: string, maxWidth = 128): number {
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

function edgeBoundary(center: GraphPoint, toward: GraphPoint, width: number, height: number): GraphPoint {
    const dx = toward[0] - center[0];
    const dy = toward[1] - center[1];
    const scaleX = Math.abs(dx) > 0 ? (width / 2 + 3) / Math.abs(dx) : Number.POSITIVE_INFINITY;
    const scaleY = Math.abs(dy) > 0 ? (height / 2 + 3) / Math.abs(dy) : Number.POSITIVE_INFINITY;
    const scale = Math.min(scaleX, scaleY);

    return [center[0] + dx * scale, center[1] + dy * scale];
}

function relationGeometry(
    source: GraphPoint,
    target: GraphPoint,
    sourceWidth: number,
    targetWidth: number,
    nodeHeight: number,
    hub: GraphPoint,
    index: number,
): { path: string; label: GraphPoint } {
    const start = edgeBoundary(source, target, sourceWidth, nodeHeight);
    const end = edgeBoundary(target, source, targetWidth, nodeHeight);
    const dx = end[0] - start[0];
    const dy = end[1] - start[1];
    const distance = Math.max(1, Math.hypot(dx, dy));
    const midpoint: GraphPoint = [(start[0] + end[0]) / 2, (start[1] + end[1]) / 2];
    let radialX = midpoint[0] - hub[0];
    let radialY = midpoint[1] - hub[1];
    let radialLength = Math.hypot(radialX, radialY);

    if (radialLength < 1) {
        radialX = -dy / distance * (index % 2 === 0 ? 1 : -1);
        radialY = dx / distance * (index % 2 === 0 ? 1 : -1);
        radialLength = 1;
    }

    const bend = Math.min(46, Math.max(13, distance * 0.1));
    const controlX = midpoint[0] + (radialX / radialLength) * bend;
    const controlY = midpoint[1] + (radialY / radialLength) * bend;
    const label: GraphPoint = [
        (start[0] + 2 * controlX + end[0]) / 4,
        (start[1] + 2 * controlY + end[1]) / 4,
    ];

    return { path: `M ${start[0]} ${start[1]} Q ${controlX} ${controlY} ${end[0]} ${end[1]}`, label };
}

function relationshipText(edge: MarketingEdge, labels: Map<string, string>): string {
    return `${labels.get(edge.source) ?? edge.source} to ${labels.get(edge.target) ?? edge.target}: ${formatCount(edge.count)} distinct linked pairs with recorded activity in this range.`;
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
        ? Math.max(76, Math.min(128, size.width / 2 - 29))
        : size.width < 520
            ? Math.min(128, size.width / 2 - 9)
            : 128;
    const maxNodeWidth = Math.max(72, ...modules.map((node) => nodeWidth(node.label, nodeWidthLimit)));
    const layout: GraphPosition = size.width < 520 && modules.length > 5 ? 'columns' : 'orbit';
    const centerX = size.width / 2;
    const centerY = size.height / 2;
    const positions = modules.map((node, index) => ({
        node,
        point: modulePosition(index, modules.length, size.width, size.height, maxNodeWidth, layout),
    }));
    const positionById = new Map(positions.map(({ node, point }) => [node.id, point]));
    const nodeById = new Map(modules.map((node) => [node.id, node]));
    const labelById = new Map(modules.map((node) => [node.id, node.label]));
    const selectedModule = modules.find((node) => node.id === selected);
    const focusedId = hovered ?? selected;
    const nodeHeight = layout === 'columns' ? 26 : 34;
    const graphRelations = data.edges
        .filter((edge) => positionById.has(edge.source) && positionById.has(edge.target))
        .map((edge, index) => {
            const sourceNode = nodeById.get(edge.source);
            const targetNode = nodeById.get(edge.target);
            const source = positionById.get(edge.source);
            const target = positionById.get(edge.target);
            if (!sourceNode || !targetNode || !source || !target) return null;
            const geometry = relationGeometry(
                source,
                target,
                nodeWidth(sourceNode.label, nodeWidthLimit),
                nodeWidth(targetNode.label, nodeWidthLimit),
                nodeHeight,
                [centerX, centerY],
                index,
            );
            const isFocused = focusedId !== null && (edge.source === focusedId || edge.target === focusedId);
            const isMuted = focusedId !== null && !isFocused;

            return {
                edge,
                geometry,
                color: nodeColors[sourceNode.color] ?? '#8a66ee',
                isFocused,
                isMuted,
            };
        })
        .filter((relation): relation is NonNullable<typeof relation> => relation !== null);
    const visibleRelations = (focusedId
        ? graphRelations.filter(({ edge }) => edge.source === focusedId || edge.target === focusedId)
        : [...graphRelations].sort((left, right) => right.edge.count - left.edge.count))
        .slice(0, 3);

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
        const selectThisModule = () => selectModule(node);
        const common = {
            className,
            key: node.id,
            style,
            title,
            'aria-label': label,
            onClick: selectThisModule,
            onPointerEnter: () => setHovered(node.id),
            onPointerLeave: () => setHovered((current) => current === node.id ? null : current),
            onFocus: () => setHovered(node.id),
            onBlur: () => setHovered(null),
        };

        if (node.href) {
            return <a {...common} href={node.href}>{content}</a>;
        }

        return <button {...common} onClick={selectThisModule} type="button" aria-pressed={isSelected}>{content}</button>;
    };

    return (
        <section className="command-os-panel command-os-graph-panel" id="marketing-graph" aria-labelledby="command-os-graph-title">
            <div className="command-os-panel-heading">
                <div className="command-os-panel-title"><span className="command-os-heading-icon command-os-graph-heading-icon"><Network size={16} aria-hidden="true" /></span><h2 id="command-os-graph-title">Marketing graph</h2></div>
                <span className="command-os-status-tag">{modules.length} modules</span>
            </div>
            <p className="command-os-graph-intro">Recorded campaign, Drop and warmup links across this workspace.</p>
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
                    <g className="command-os-network-relations" aria-hidden="true">
                        {graphRelations.map(({ edge, geometry, color, isFocused, isMuted }) => (
                            <path
                                className={`command-os-network-relation${isFocused ? ' is-focused' : ''}${isMuted ? ' is-muted' : ''}`}
                                key={`${edge.source}-${edge.target}`}
                                d={geometry.path}
                                stroke={color}
                            />
                        ))}
                        {graphRelations.filter(({ isFocused }) => isFocused).map(({ edge, geometry }) => {
                            const label = formatCompactCount(edge.count);
                            const width = Math.max(19, label.length * 6 + 8);
                            return (
                                <g className="command-os-network-relation-count" key={`count-${edge.source}-${edge.target}`} transform={`translate(${geometry.label[0]} ${geometry.label[1]})`}>
                                    <rect x={-width / 2} y={-8} width={width} height={16} rx={8} />
                                    <text y={3}>{label}</text>
                                </g>
                            );
                        })}
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
            <div className="command-os-graph-relations" aria-label="Observed module relationships">
                <span className="command-os-graph-relations-label">{focusedId ? 'Linked activity' : 'Observed links'}</span>
                {visibleRelations.length > 0 ? visibleRelations.map(({ edge }) => (
                    <span className="command-os-graph-relation-chip" key={`chip-${edge.source}-${edge.target}`}>
                        {labelById.get(edge.source) ?? edge.source}<span aria-hidden="true"> ↔ </span>{labelById.get(edge.target) ?? edge.target}
                        <b>{formatCompactCount(edge.count)}</b>
                    </span>
                )) : (
                    <span className="command-os-graph-relations-empty">
                        {focusedId ? `No recorded links for ${labelById.get(focusedId) ?? focusedId} in this range.` : 'No recorded cross-module activity links in this range.'}
                    </span>
                )}
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
            <ul className="command-os-sr-only" aria-label="Recorded module relationships">
                {data.edges.map((edge) => <li key={`sr-${edge.source}-${edge.target}`}>{relationshipText(edge, labelById)}</li>)}
                {data.edges.length === 0 && <li>No cross-module activity links were recorded for this range.</li>}
            </ul>
            <p className="command-os-sr-only" id="command-os-graph-note">{data.note} Dashed spokes map available modules. Solid links are derived only from stored workspace activity dimensions. Module links open only destinations permitted for your workspace role.</p>
        </section>
    );
}
