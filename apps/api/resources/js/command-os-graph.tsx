import { useEffect, useRef, useState } from 'react';
import type { JSX } from 'react';
import { init, use } from 'echarts/core';
import { GraphChart } from 'echarts/charts';
import { TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import type { EChartsType } from 'echarts/core';
import type { DashboardAnalytics } from './command-os';

use([GraphChart, TooltipComponent, CanvasRenderer]);

type Theme = 'light' | 'dark';

const nodeColors: Record<string, string> = {
    blue: '#347cff',
    violet: '#9146f3',
    pink: '#df429f',
    cyan: '#12aee6',
    indigo: '#7052ef',
};

const moduleIcons: Record<string, string> = {
    campaigns: '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
    drops: '<path d="m12 3 8.5 4.5L12 12 3.5 7.5 12 3Z"/><path d="m3.5 12 8.5 4.5 8.5-4.5M3.5 16.5 12 21l8.5-4.5"/>',
    warmups: '<path d="M8.5 14.5c1.6-1.3 2.1-3.1 1.7-5.5 3.5 2 5.2 4.5 4.7 7.2-.3 1.6-1.5 2.8-3.2 2.8-2.2 0-3.7-1.7-3.2-4.5Z"/><path d="M12 22a8 8 0 0 0 8-8c0-4-2.5-7.3-6.5-10 .4 3.2-.7 5.2-2.3 6.6C10.1 6.6 8.4 4.7 6 3c.7 4-2 6.4-2 11a8 8 0 0 0 8 8Z"/>',
    servers: '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01M11 7.5h6M11 16.5h6"/>',
    domains: '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10Z"/>',
    lists: '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 8v6M23 11h-6"/>',
    offers: '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3.4 13.4A2 2 0 0 1 2.8 12V5a2 2 0 0 1 2-2h7a2 2 0 0 1 1.4.6l7.4 7a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r=".8"/>',
    sponsors: '<path d="M16 11 12 15l-2-2"/><path d="M3 12V6l7-3 4 2 5-1 2 4-3 7-4 4-3-2-2 2-4-3-2-4Z"/><path d="m3 6 4 1 3-2m7 2 3 1"/>',
    isps: '<path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
    tracking: '<path d="M3 3v18h18"/><path d="m18 17-6-6-4 4-3-3"/><path d="M14 17h4v-4"/>',
};

function svgDataUri(content: string): string {
    return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(content)}`;
}

function iconImage(id: string, color: string): string {
    const paths = moduleIcons[id] ?? moduleIcons.domains;
    return svgDataUri(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="${color}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${paths}</svg>`);
}

const brandMark = '/console-assets/alphalink-command-os-mark.png';

function formatCount(value: number): string {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
}

function moduleWidth(label: string): number {
    return Math.min(116, Math.max(76, label.length * 5.55 + 38));
}

function radialPosition(index: number, count: number, width: number, height: number): [number, number] {
    const angle = -Math.PI / 2 + (index * Math.PI * 2) / count;
    const radiusX = Math.max(72, width / 2 - 65);
    const radiusY = Math.max(32, height / 2 - 25);
    return [width / 2 + Math.cos(angle) * radiusX, height / 2 + Math.sin(angle) * radiusY];
}

export default function MarketingGraph({ data, theme }: { data: DashboardAnalytics['graph']; theme: Theme }): JSX.Element {
    const container = useRef<HTMLDivElement>(null);
    const [selected, setSelected] = useState<string | null>(null);

    useEffect(() => {
        if (!container.current) return undefined;
        const dark = theme === 'dark';
        const muted = dark ? '#b6c2e6' : '#536284';
        const chart: EChartsType = init(container.current, undefined, { renderer: 'canvas' });

        const renderGraph = () => {
            const width = chart.getWidth();
            const height = chart.getHeight();
            const center = {
                id: 'alphalink',
                name: 'AlphaLink',
                value: '',
                x: width / 2,
                y: height / 2,
                symbolSize: 52,
                itemStyle: {
                    color: dark ? '#101d43' : '#ffffff',
                    borderColor: dark ? '#9a63ff' : '#8254f5',
                    borderWidth: 1.5,
                    shadowBlur: 30,
                    shadowColor: dark ? 'rgba(105, 65, 255, .9)' : 'rgba(106, 73, 234, .5)',
                },
                label: {
                    show: true,
                    formatter: '{brand| }',
                    rich: { brand: { width: 38, height: 38, backgroundColor: { image: brandMark } } },
                },
            };
            const modules = data.nodes.map((node, index) => {
                const [x, y] = radialPosition(index, data.nodes.length, width, height);
                const color = nodeColors[node.color] ?? '#7b4bf3';
                const icon = iconImage(node.id, color);
                return {
                    id: node.id,
                    name: node.label,
                    value: node.count,
                    x,
                    y,
                    symbol: 'roundRect',
                    symbolSize: [moduleWidth(node.label), 27],
                    itemStyle: {
                        color: dark ? `${color}24` : '#fff',
                        borderColor: color,
                        borderWidth: 1.5,
                        shadowBlur: dark ? 17 : 11,
                        shadowColor: `${color}77`,
                    },
                    label: {
                        show: true,
                        color: muted,
                        fontSize: 9.5,
                        fontWeight: 700,
                        formatter: `{icon| } {label|${node.label}}`,
                        rich: {
                            icon: { width: 13, height: 13, backgroundColor: { image: icon } },
                            label: { color: muted, fontSize: 9.5, fontWeight: 700 },
                        },
                    },
                };
            });

            chart.setOption({
                animationDuration: 260,
                tooltip: {
                    trigger: 'item',
                    backgroundColor: dark ? '#101a35' : '#fff',
                    borderColor: dark ? '#384c7c' : '#d8def1',
                    textStyle: { color: dark ? '#eff3ff' : '#202b4d', fontSize: 11 },
                    formatter: (params: { dataType?: string; data?: { id?: string; name?: string; value?: number | string } }) => {
                        if (params.dataType !== 'node' || !params.data) return '';
                        return params.data.id === 'alphalink' ? 'AlphaLink workspace' : `${params.data.name}: ${formatCount(Number(params.data.value ?? 0))} activity`;
                    },
                },
                series: [{
                    type: 'graph',
                    layout: 'none',
                    left: 50,
                    right: 50,
                    top: 18,
                    bottom: 18,
                    data: [center, ...modules],
                    links: data.nodes.map((node) => ({
                        source: 'alphalink',
                        target: node.id,
                        lineStyle: {
                            color: nodeColors[node.color] ?? '#7b4bf3',
                            opacity: dark ? 0.82 : 0.64,
                            width: 1.6,
                            shadowBlur: 9,
                            shadowColor: `${nodeColors[node.color] ?? '#7b4bf3'}99`,
                        },
                    })),
                    roam: false,
                    draggable: false,
                    edgeSymbol: ['none', 'none'],
                    lineStyle: { width: 1.4, curveness: 0.045 },
                    emphasis: { focus: 'adjacency', lineStyle: { width: 2.4, opacity: 1 } },
                    blur: { lineStyle: { opacity: 0.12 } },
                }],
            }, { notMerge: true });
        };

        renderGraph();
        chart.on('click', (params) => {
            if (params.dataType !== 'node' || !params.data || typeof params.data !== 'object') return;
            const item = params.data as { id?: unknown; name?: unknown; value?: unknown };
            if (typeof item.id === 'string' && item.id !== 'alphalink' && typeof item.name === 'string') {
                setSelected(`${item.name}: ${formatCount(Number(item.value ?? 0))} activity in this range`);
            }
        });
        const observer = new ResizeObserver(() => {
            chart.resize();
            renderGraph();
        });
        observer.observe(container.current);

        return () => {
            observer.disconnect();
            chart.dispose();
        };
    }, [data, theme]);

    return (
        <section className="command-os-panel command-os-graph-panel" id="marketing-graph" aria-labelledby="command-os-graph-title">
            <div className="command-os-panel-heading">
                <div className="command-os-panel-title"><span className="command-os-heading-icon command-os-graph-heading-icon"><span aria-hidden="true">✣</span></span><h2 id="command-os-graph-title">Marketing graph</h2></div>
                <span className="command-os-status-tag">Network view</span>
            </div>
            <div className="command-os-graph-canvas" ref={container} role="application" aria-describedby="command-os-graph-note" aria-label="Interactive AlphaLink module graph. Select a module to inspect its stored activity count." />
            <p className="command-os-graph-selected" aria-live="polite">{selected}</p>
            <ul className="command-os-sr-only" aria-label="Module activity counts">
                {data.nodes.map((node) => <li key={node.id}>{node.label}: {formatCount(node.count)} activity in this range</li>)}
            </ul>
            <p className="command-os-sr-only" id="command-os-graph-note">{data.note} Select a node for its activity count.</p>
        </section>
    );
}
