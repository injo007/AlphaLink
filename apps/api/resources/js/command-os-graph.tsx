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
    blue: '#398aff',
    violet: '#9c49fa',
    pink: '#e348a4',
    cyan: '#23b9ed',
    indigo: '#7563fa',
};

function formatCount(value: number): string {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
}

function moduleWidth(label: string): number {
    return Math.min(96, Math.max(68, label.length * 5.8 + 24));
}

function radialPosition(index: number, count: number, width: number, height: number): [number, number] {
    const angle = -Math.PI / 2 + (index * Math.PI * 2) / count;
    const radiusX = Math.max(72, width / 2 - 55);
    const radiusY = Math.max(32, height / 2 - 19);
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
                name: 'A',
                value: '',
                x: width / 2,
                y: height / 2,
                symbolSize: 42,
                itemStyle: {
                    color: '#7b4bf3',
                    borderColor: dark ? '#d2c5ff' : '#fff',
                    borderWidth: 2,
                    shadowBlur: 17,
                    shadowColor: 'rgba(123, 75, 243, .48)',
                },
                label: { show: true, color: '#fff', fontWeight: 800, fontSize: 17 },
            };
            const modules = data.nodes.map((node, index) => {
                const [x, y] = radialPosition(index, data.nodes.length, width, height);
                const color = nodeColors[node.color] ?? '#7b4bf3';
                return {
                    id: node.id,
                    name: node.label,
                    value: node.count,
                    x,
                    y,
                    symbol: 'roundRect',
                    symbolSize: [moduleWidth(node.label), 25],
                    itemStyle: {
                        color: dark ? '#17264b' : '#fff',
                        borderColor: color,
                        borderWidth: 1.4,
                        shadowBlur: 7,
                        shadowColor: `${color}40`,
                    },
                    label: { show: true, color: muted, fontSize: 9, fontWeight: 700, formatter: node.label },
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
                            opacity: dark ? 0.84 : 0.5,
                            width: 1.3,
                        },
                    })),
                    roam: false,
                    draggable: false,
                    edgeSymbol: ['none', 'none'],
                    lineStyle: { width: 1.2, curveness: 0.025 },
                    emphasis: { focus: 'adjacency', lineStyle: { width: 2 } },
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
                <div className="command-os-panel-title"><span className="command-os-heading-icon"><span aria-hidden="true">✣</span></span><h2 id="command-os-graph-title">Marketing graph</h2></div>
                <span className="command-os-status-tag">Network view</span>
            </div>
            <div className="command-os-graph-canvas" ref={container} role="application" aria-label="Interactive AlphaLink module graph. Select a module to inspect its stored activity count." />
            <p className="command-os-graph-selected" aria-live="polite">{selected}</p>
            <ul className="command-os-sr-only" aria-label="Module activity counts">
                {data.nodes.map((node) => <li key={node.id}>{node.label}: {formatCount(node.count)} activity in this range</li>)}
            </ul>
            <p className="command-os-graph-note" title={data.note}>Connections show module membership, not record links. Select a node for its activity count.</p>
        </section>
    );
}
