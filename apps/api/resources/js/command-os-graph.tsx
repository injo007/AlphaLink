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

const nodePositions: Record<string, [number, number]> = {
    campaigns: [400, 32],
    drops: [585, 65],
    warmups: [660, 165],
    servers: [585, 266],
    domains: [400, 300],
    lists: [215, 266],
    sponsors: [140, 165],
    offers: [215, 65],
    isps: [400, 88],
    tracking: [400, 235],
    'workspace-activity': [400, 88],
};

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

export default function MarketingGraph({ data, theme }: { data: DashboardAnalytics['graph']; theme: Theme }): JSX.Element {
    const container = useRef<HTMLDivElement>(null);
    const [selected, setSelected] = useState<string | null>(null);

    useEffect(() => {
        if (!container.current) return undefined;
        const dark = theme === 'dark';
        const muted = dark ? '#b6c2e6' : '#536284';
        const line = dark ? 'rgba(91, 130, 237, .7)' : 'rgba(105, 122, 183, .42)';
        const chart: EChartsType = init(container.current, undefined, { renderer: 'canvas' });
        const center = { id: 'alphalink', name: 'AlphaLink', value: '', x: 400, y: 165, symbolSize: 58, itemStyle: { color: '#7b4bf3', borderColor: dark ? '#d2c5ff' : '#fff', borderWidth: 2, shadowBlur: 18, shadowColor: 'rgba(123, 75, 243, .42)' }, label: { show: true, color: '#fff', fontWeight: 750, fontSize: 14 } };
        const modules = data.nodes.map((node) => {
            const [x, y] = nodePositions[node.id] ?? [400, 165];
            return {
                id: node.id,
                name: node.label,
                value: node.count,
                x,
                y,
                symbol: 'roundRect',
                symbolSize: [Math.max(92, Math.min(136, node.label.length * 8 + 40)), 34],
                itemStyle: { color: dark ? '#17264b' : '#fff', borderColor: nodeColors[node.color] ?? '#7b4bf3', borderWidth: 1.5, shadowBlur: 8, shadowColor: `${nodeColors[node.color] ?? '#7b4bf3'}44` },
                label: { show: true, color: muted, fontSize: 10, fontWeight: 650, formatter: `${node.label}\n${formatCount(node.count)}`, lineHeight: 12 },
            };
        });
        const moduleIds = data.nodes.map((node) => node.id);
        chart.setOption({
            animationDuration: 300,
            tooltip: {
                trigger: 'item',
                backgroundColor: dark ? '#101a35' : '#fff',
                borderColor: dark ? '#384c7c' : '#d8def1',
                textStyle: { color: dark ? '#eff3ff' : '#202b4d', fontSize: 11 },
                formatter: (params: { dataType?: string; data?: { id?: string; name?: string; value?: number | string } }) => {
                    if (params.dataType !== 'node' || !params.data) return '';
                    return params.data.id === 'alphalink' ? 'AlphaLink workspace' : `${params.data.name}: ${formatCount(Number(params.data.value ?? 0))} records`;
                },
            },
            series: [{
                type: 'graph',
                layout: 'none',
                data: [center, ...modules],
                links: moduleIds.map((id) => ({ source: 'alphalink', target: id })),
                roam: true,
                draggable: true,
                scaleLimit: { min: 0.75, max: 1.8 },
                edgeSymbol: ['none', 'none'],
                lineStyle: { color: line, width: 1.2, curveness: 0.04 },
                emphasis: { focus: 'adjacency', lineStyle: { width: 2.3 } },
                blur: { lineStyle: { opacity: 0.18 } },
            }],
        });
        chart.on('click', (params) => {
            if (params.dataType !== 'node' || !params.data || typeof params.data !== 'object') return;
            const item = params.data as { id?: unknown; name?: unknown; value?: unknown };
            if (typeof item.id === 'string' && item.id !== 'alphalink' && typeof item.name === 'string') {
                setSelected(`${item.name}: ${formatCount(Number(item.value ?? 0))} activity in this range`);
            }
        });
        const observer = new ResizeObserver(() => chart.resize());
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
                <span className="command-os-status-tag">Workspace view</span>
            </div>
            <div className="command-os-graph-canvas" ref={container} role="application" aria-label="Interactive AlphaLink module graph. Drag nodes or pan and zoom; select a module to hear its stored activity count." />
            <p className="command-os-graph-selected" aria-live="polite">{selected ?? 'Select a module to inspect its activity.'}</p>
            <ul className="command-os-sr-only" aria-label="Module activity counts">
                {data.nodes.map((node) => <li key={node.id}>{node.label}: {formatCount(node.count)} activity in this range</li>)}
            </ul>
            <p className="command-os-graph-note">{data.note}</p>
        </section>
    );
}
