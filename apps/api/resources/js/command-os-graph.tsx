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

const featuredModuleOrder = ['campaigns', 'servers', 'isps', 'drops', 'warmups', 'offers', 'tracking', 'lists', 'domains', 'sponsors'];
const additionalModuleOrder: string[] = [];
const brandMark = '/console-assets/alphalink-command-os-mark.png';

function formatCount(value: number): string {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
}

function nodeWidth(label: string, maxWidth = 128): number {
    return Math.max(Math.min(88, maxWidth), Math.min(maxWidth, Math.ceil(label.length * 6.4 + 40)));
}

function modulePosition(index: number, count: number, width: number, height: number, maxNodeWidth: number): [number, number] {
    if (count <= 1) return [width / 2, height * 0.19];
    if (width < 560 && count > 5) count = 5;
    const visibleIndex = index % count;
    const angle = -Math.PI / 2 + (visibleIndex * Math.PI * 2) / count;
    const radiusX = Math.max(44, width / 2 - maxNodeWidth / 2 - 10);
    const radiusY = Math.max(42, height / 2 - 18);
    return [width / 2 + Math.cos(angle) * radiusX, height / 2 + Math.sin(angle) * radiusY];
}

function moduleCountLabel(node: MarketingNode): string {
    return `${formatCount(node.count)} ${node.id === 'tracking' || node.id === 'workspace-activity' ? 'tracked events' : 'entities with activity'}`;
}

export default function MarketingGraph({ data, theme }: { data: DashboardAnalytics['graph']; theme: Theme }): JSX.Element {
    const container = useRef<HTMLDivElement>(null);
    const [size, setSize] = useState<GraphSize>({ width: 480, height: 174 });
    const [selected, setSelected] = useState<string | null>(null);
    const [hovered, setHovered] = useState<string | null>(null);
    const dark = theme === 'dark';
    const orderedModules = [...featuredModuleOrder, ...additionalModuleOrder]
        .map((id) => data.nodes.find((node) => node.id === id))
        .filter((node): node is MarketingNode => node !== undefined);
    const orderedIds = new Set(orderedModules.map((node) => node.id));
    const modules = [...orderedModules, ...data.nodes.filter((node) => !orderedIds.has(node.id))];
    const featuredModules = modules.filter((node) => featuredModuleOrder.includes(node.id));
    const useFullNetwork = size.width >= 560 && modules.length <= 10;
    const primaryModules = useFullNetwork || featuredModules.length === 0 ? modules : featuredModules.slice(0, 5);
    const additionalModules = modules.filter((node) => !primaryModules.some((primary) => primary.id === node.id));
    const nodeWidthLimit = size.width < 320 ? 104 : 128;
    const maxNodeWidth = Math.max(Math.min(92, nodeWidthLimit), ...primaryModules.map((node) => nodeWidth(node.label, nodeWidthLimit)));
    const centerX = size.width / 2;
    const centerY = size.height * 0.53;
    const positions = primaryModules.map((node, index) => ({ node, point: modulePosition(index, primaryModules.length, size.width, size.height, maxNodeWidth) }));
    const selectedModule = modules.find((node) => node.id === selected);
    const focusedId = hovered ?? selected;

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
    const renderModuleButton = (node: MarketingNode, point?: [number, number]) => {
        const color = nodeColors[node.color] ?? '#8a66ee';
        const isSelected = selected === node.id;
        const isFocused = focusedId === node.id;
        const isMuted = focusedId !== null && !isFocused;
        const style: CSSProperties = { '--node-accent': color } as CSSProperties;
        if (point) {
            style.left = `${(point[0] / size.width) * 100}%`;
            style.top = `${(point[1] / size.height) * 100}%`;
            style.width = `${nodeWidth(node.label, nodeWidthLimit)}px`;
        }

        const className = `command-os-network-node${point ? '' : ' command-os-graph-module-chip'}${isSelected ? ' is-selected' : ''}${isFocused ? ' is-focused' : ''}${isMuted ? ' is-muted' : ''}`;
        const title = node.href ? `Open ${node.label} · ${moduleCountLabel(node)} in this range` : `${node.label}: ${moduleCountLabel(node)} in this range`;
        const label = node.href ? `Open ${node.label}: ${moduleCountLabel(node)} in this range` : `Show ${node.label}: ${moduleCountLabel(node)} in this range`;
        const content = <><span className="command-os-network-node-dot" aria-hidden="true" /><span>{node.label}</span></>;

        if (node.href) {
            return <a
                className={className}
                key={node.id}
                style={style}
                href={node.href}
                title={title}
                aria-label={label}
                onClick={() => selectModule(node)}
                onPointerEnter={() => setHovered(node.id)}
                onPointerLeave={() => setHovered((current) => current === node.id ? null : current)}
                onFocus={() => setHovered(node.id)}
                onBlur={() => setHovered(null)}
            >{content}</a>;
        }

        return <button
            className={className}
            key={node.id}
            style={style}
            type="button"
            title={title}
            aria-label={label}
            aria-pressed={isSelected}
            onClick={() => selectModule(node)}
            onPointerEnter={() => setHovered(node.id)}
            onPointerLeave={() => setHovered((current) => current === node.id ? null : current)}
            onFocus={() => setHovered(node.id)}
            onBlur={() => setHovered(null)}
        >{content}</button>;
    };

    return (
        <section className="command-os-panel command-os-graph-panel" id="marketing-graph" aria-labelledby="command-os-graph-title">
            <div className="command-os-panel-heading">
                <div className="command-os-panel-title"><span className="command-os-heading-icon command-os-graph-heading-icon"><span aria-hidden="true">✣</span></span><h2 id="command-os-graph-title">Marketing graph</h2></div>
                <span className="command-os-status-tag">{modules.length} modules</span>
            </div>
            <p className="command-os-graph-intro">Bring campaigns, audiences, deliverability and infrastructure together in one focused workspace.</p>
            <div className="command-os-graph-canvas" ref={container}>
                <svg
                    className="command-os-network-graphic"
                    viewBox={`0 0 ${size.width} ${size.height}`}
                    role="group"
                    aria-label="AlphaLink marketing modules connected to the workspace hub"
                    aria-describedby="command-os-graph-note"
                >
                    <defs>
                        <linearGradient id="command-os-network-hub-fill" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stopColor={dark ? '#a95bff' : '#8a46f4'} />
                            <stop offset="100%" stopColor="#0879ec" />
                        </linearGradient>
                    </defs>
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
                        <circle className="command-os-network-hub-ring command-os-network-hub-ring-outer" cx={centerX} cy={centerY} r={59} />
                        <circle className="command-os-network-hub-ring command-os-network-hub-ring-inner" cx={centerX} cy={centerY} r={42} />
                        <rect className="command-os-network-hub-tile" x={centerX - 22} y={centerY - 22} width={44} height={44} rx={13} transform={`rotate(45 ${centerX} ${centerY})`} />
                        <image className="command-os-network-hub-logo" href={brandMark} x={centerX - 13} y={centerY - 13} width={26} height={26} />
                    </g>
                </svg>
                {positions.map(({ node, point }) => renderModuleButton(node, point))}
            </div>
            {additionalModules.length > 0 && (
                <div className="command-os-graph-module-strip" role="group" aria-label="Additional workspace modules">
                    {additionalModules.map((node) => renderModuleButton(node))}
                </div>
            )}
            <p className="command-os-graph-selected" aria-live="polite">
                <span>
                    {selectedModule
                        ? `${selectedModule.label}: ${moduleCountLabel(selectedModule)} in this range.`
                        : modules.length > 0
                            ? 'Open a linked module, or select a module without a destination to inspect its activity count.'
                            : 'No module activity is available for this date range.'}
                </span>
                {selectedModule && !selectedModule.href && <span className="command-os-graph-open-link">No direct destination for {selectedModule.label}</span>}
            </p>
            <ul className="command-os-sr-only" aria-label="Module activity counts">
                {data.nodes.map((node) => <li key={node.id}>{node.label}: {moduleCountLabel(node)} in this range</li>)}
            </ul>
            <p className="command-os-sr-only" id="command-os-graph-note">{data.note} Featured modules connect radially to the AlphaLink hub; additional authorized modules remain selectable in the row below. The graph does not claim record-to-record attribution. Module buttons open only pages available to your workspace role.</p>
        </section>
    );
}
