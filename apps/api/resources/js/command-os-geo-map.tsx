/// <reference path="./world-atlas.d.ts" />
import { useEffect, useMemo, useRef } from 'react';
import type { JSX } from 'react';
import { AttributionControl, Map as MapLibreMap, NavigationControl, Popup, setWorkerUrl } from 'maplibre-gl';
import type { Feature, FeatureCollection, Geometry } from 'geojson';
import { feature } from 'topojson-client';
import { getAlpha2Code, getName, getSimpleAlpha2Code, isValid, numericToAlpha2, registerLocale } from 'i18n-iso-countries';
import englishLocale from 'i18n-iso-countries/langs/en.json';
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';
import 'maplibre-gl/dist/maplibre-gl.css';
import worldAtlas from 'world-atlas/countries-110m.json';
import type { DashboardAnalytics, GeoCountry } from './command-os';

registerLocale(englishLocale);
setWorkerUrl(workerUrl);

type CountryProperties = {
    iso2: string;
    name: string;
    deliveries: number;
    bounces: number;
    engagement: number;
    opens: number;
    clicks: number;
    leads: number;
};

type MapTheme = 'light' | 'dark';

function alpha2(country: string): string | null {
    const value = country.trim();
    if (value.length === 2 && isValid(value.toUpperCase())) {
        return value.toUpperCase() === 'UK' ? 'GB' : value.toUpperCase();
    }

    const code = getAlpha2Code(value, 'en') ?? getSimpleAlpha2Code(value, 'en');
    return code && isValid(code) ? code : null;
}

function countryName(code: string, provided?: string): string {
    return getName(code, 'en') ?? provided ?? code;
}

function createCountryCollection(countries: GeoCountry[]): FeatureCollection<Geometry, CountryProperties> {
    const metrics = new Map<string, GeoCountry>();
    for (const row of countries) {
        const code = alpha2(row.country);
        if (!code) continue;
        const current = metrics.get(code);
        if (current) {
            current.opens += row.opens;
            current.clicks += row.clicks;
            current.leads += row.leads;
            current.unsubscribes += row.unsubscribes;
            current.deliveries += row.deliveries;
            current.bounces += row.bounces;
        } else {
            metrics.set(code, { ...row });
        }
    }

    const topologyFeatureCollection = feature(
        worldAtlas as never,
        (worldAtlas.objects as { countries: never }).countries,
    ) as unknown as FeatureCollection<Geometry, Record<string, unknown>>;

    return {
        type: 'FeatureCollection',
        features: topologyFeatureCollection.features.map((item: Feature<Geometry, Record<string, unknown>>) => {
            const code = numericToAlpha2(String(item.id ?? '').padStart(3, '0')) ?? '';
            const stat = metrics.get(code);
            const engagement = stat ? stat.opens + stat.clicks + stat.leads + stat.unsubscribes : 0;

            return {
                ...item,
                properties: {
                    iso2: code,
                    name: countryName(code, stat?.country),
                    deliveries: stat?.deliveries ?? 0,
                    bounces: stat?.bounces ?? 0,
                    engagement,
                    opens: stat?.opens ?? 0,
                    clicks: stat?.clicks ?? 0,
                    leads: stat?.leads ?? 0,
                },
            };
        }),
    };
}

function formatCount(value: number): string {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
}

function popupContent(properties: CountryProperties): HTMLElement {
    const content = document.createElement('div');
    content.className = 'command-os-map-popup';
    const heading = document.createElement('strong');
    heading.textContent = properties.name;
    content.append(heading);
    const details = document.createElement('span');
    details.textContent = `${formatCount(properties.deliveries)} delivery feedback · ${formatCount(properties.bounces)} bounces`;
    content.append(details);
    const activity = document.createElement('span');
    activity.textContent = `${formatCount(properties.opens)} opens · ${formatCount(properties.clicks)} clicks · ${formatCount(properties.leads)} leads`;
    content.append(activity);
    return content;
}

function topCountries(countries: GeoCountry[]): { code: string; name: string; row: GeoCountry }[] {
    const combined = new Map<string, GeoCountry>();
    for (const row of countries) {
        const code = alpha2(row.country);
        if (!code) continue;
        const existing = combined.get(code);
        if (existing) {
            existing.opens += row.opens;
            existing.clicks += row.clicks;
            existing.leads += row.leads;
            existing.unsubscribes += row.unsubscribes;
            existing.deliveries += row.deliveries;
            existing.bounces += row.bounces;
        } else {
            combined.set(code, { ...row });
        }
    }
    return [...combined.entries()]
        .map(([code, row]) => ({ code, name: countryName(code, row.country), row }))
        .sort((left, right) => (right.row.deliveries + right.row.opens + right.row.clicks) - (left.row.deliveries + left.row.opens + left.row.clicks))
        .slice(0, 4);
}

export default function DeliverabilityGeoMap({ data, theme }: { data: DashboardAnalytics['geo']; theme: MapTheme }): JSX.Element {
    const container = useRef<HTMLDivElement>(null);
    const collection = useMemo(() => createCountryCollection(data.countries), [data.countries]);
    const summary = useMemo(() => topCountries(data.countries), [data.countries]);
    const countryCount = useMemo(() => new Set(data.countries.map((row) => alpha2(row.country)).filter((code): code is string => code !== null)).size, [data.countries]);

    useEffect(() => {
        const element = container.current;
        if (!element) return undefined;

        const dark = theme === 'dark';
        const base = dark ? '#111a36' : '#edf1ff';
        const countryFill = dark ? '#202c50' : '#dce4fb';
        const boundary = dark ? '#485b91' : '#b1bfe5';
        const map = new MapLibreMap({
            container: element,
            center: [0, 18],
            zoom: 0.72,
            minZoom: 0.55,
            maxZoom: 4,
            maxPitch: 0,
            attributionControl: false,
            style: {
                version: 8,
                sources: { countries: { type: 'geojson', data: collection } },
                layers: [
                    { id: 'map-background', type: 'background', paint: { 'background-color': base } },
                    {
                        id: 'country-fill',
                        type: 'fill',
                        source: 'countries',
                        paint: {
                            'fill-color': [
                                'case',
                                ['>', ['get', 'deliveries'], 0],
                                ['interpolate', ['linear'], ['get', 'deliveries'], 1, '#1878ff', 1000, '#8c43f5'],
                                ['>', ['get', 'engagement'], 0], dark ? '#0756a7' : '#88aaf8',
                                countryFill,
                            ],
                            'fill-opacity': 0.94,
                        },
                    },
                    {
                        id: 'country-outline',
                        type: 'line',
                        source: 'countries',
                        paint: { 'line-color': boundary, 'line-width': 0.65, 'line-opacity': 0.78 },
                    },
                ],
            },
        });
        map.addControl(new NavigationControl({ showCompass: false, visualizePitch: false }), 'top-right');
        map.addControl(new AttributionControl({ compact: true, customAttribution: 'Country boundaries: Natural Earth' }), 'bottom-right');
        const popup = new Popup({ closeButton: true, closeOnClick: true, maxWidth: '260px' });
        map.on('click', 'country-fill', (event) => {
            const properties = event.features?.[0]?.properties as CountryProperties | undefined;
            if (!properties || !event.lngLat) return;
            popup.setLngLat(event.lngLat).setDOMContent(popupContent(properties)).addTo(map);
        });
        map.on('mouseenter', 'country-fill', () => { map.getCanvas().style.cursor = 'pointer'; });
        map.on('mouseleave', 'country-fill', () => { map.getCanvas().style.cursor = ''; });

        return () => {
            popup.remove();
            map.remove();
        };
    }, [collection, theme]);

    return (
        <section className="command-os-panel command-os-geo-panel" id="deliverability-geo" aria-labelledby="command-os-geo-title">
            <div className="command-os-panel-heading">
                <div className="command-os-panel-title"><span className="command-os-heading-icon"><span aria-hidden="true">◎</span></span><h2 id="command-os-geo-title">Deliverability by GEO</h2></div>
                <span className="command-os-status-tag">{countryCount} countries</span>
            </div>
            <div className="command-os-geo-legend" aria-label="Map legend">
                <span><i className="command-os-legend-delivery" aria-hidden="true" />PMTA delivery feedback</span>
                <span><i className="command-os-legend-engagement" aria-hidden="true" />Observed engagement</span>
            </div>
            <div className="command-os-map-frame">
                <div className="command-os-map" ref={container} role="application" aria-label="Interactive world map. Use zoom controls and select a country to inspect delivery feedback and engagement." />
            </div>
            <div className="command-os-geo-countries" aria-label="Highest activity countries">
                {summary.length === 0 ? <p role="status">No country-coded activity is recorded for this date range.</p> : summary.map(({ code, name, row }) => <div key={code}>
                    <strong>{name}</strong>
                    <span>{formatCount(row.deliveries)} deliveries</span>
                    <span>{formatCount(row.opens + row.clicks + row.leads)} engagements</span>
                </div>)}
            </div>
            <p className="command-os-geo-note">{data.feedback_note}</p>
        </section>
    );
}
