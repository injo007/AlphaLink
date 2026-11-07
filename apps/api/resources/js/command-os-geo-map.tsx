/// <reference path="./world-atlas.d.ts" />
import { useEffect, useMemo, useRef } from 'react';
import type { JSX } from 'react';
import { AttributionControl, Map as MapLibreMap, NavigationControl, Popup, setWorkerUrl } from 'maplibre-gl';
import type { Feature, FeatureCollection, Geometry, Point } from 'geojson';
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

function collectCoordinates(value: unknown, coordinates: [number, number][]): void {
    if (!Array.isArray(value)) return;
    if (typeof value[0] === 'number' && typeof value[1] === 'number') {
        coordinates.push([value[0], value[1]]);
        return;
    }
    value.forEach((item) => collectCoordinates(item, coordinates));
}

function geometryCoordinates(geometry: Geometry): [number, number][] {
    if (geometry.type === 'GeometryCollection') {
        return geometry.geometries.flatMap(geometryCoordinates);
    }
    const coordinates: [number, number][] = [];
    collectCoordinates(geometry.coordinates, coordinates);
    return coordinates;
}

function createActivityMarkers(countries: FeatureCollection<Geometry, CountryProperties>): FeatureCollection<Point, CountryProperties> {
    const features = countries.features.flatMap((country): Feature<Point, CountryProperties>[] => {
        const properties = country.properties;
        if (properties.deliveries + properties.bounces + properties.engagement === 0) return [];
        const coordinates = geometryCoordinates(country.geometry);
        if (coordinates.length === 0) return [];

        const longitudes = coordinates.map(([longitude]) => longitude);
        const latitudes = coordinates.map(([, latitude]) => latitude);
        const minLongitude = Math.min(...longitudes);
        const maxLongitude = Math.max(...longitudes);
        // Skip dateline-spanning geometries rather than placing a marker far from the country.
        if (maxLongitude - minLongitude > 120) return [];

        return [{
            type: 'Feature',
            geometry: {
                type: 'Point',
                coordinates: [(minLongitude + maxLongitude) / 2, (Math.min(...latitudes) + Math.max(...latitudes)) / 2],
            },
            properties,
        }];
    });

    return { type: 'FeatureCollection', features };
}

function countryDotPattern(color: string): ImageData {
    const size = 8;
    const [red, green, blue] = [1, 3, 5].map((start) => Number.parseInt(color.slice(start, start + 2), 16));
    const pixels = new Uint8ClampedArray(size * size * 4);
    for (let y = 0; y < size; y += 1) {
        for (let x = 0; x < size; x += 1) {
            const dx = x - 3.5;
            const dy = y - 3.5;
            if (dx * dx + dy * dy > 4.5) continue;
            const index = (y * size + x) * 4;
            pixels[index] = red;
            pixels[index + 1] = green;
            pixels[index + 2] = blue;
            pixels[index + 3] = 232;
        }
    }
    return new ImageData(pixels, size, size);
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
        .sort((left, right) => {
            const leftActivity = left.row.opens + left.row.clicks + left.row.leads + left.row.unsubscribes;
            const rightActivity = right.row.opens + right.row.clicks + right.row.leads + right.row.unsubscribes;
            return (rightActivity + right.row.deliveries + right.row.bounces) - (leftActivity + left.row.deliveries + left.row.bounces);
        })
        .slice(0, 4);
}

export default function DeliverabilityGeoMap({ data, theme }: { data: DashboardAnalytics['geo']; theme: MapTheme }): JSX.Element {
    const container = useRef<HTMLDivElement>(null);
    const collection = useMemo(() => createCountryCollection(data.countries), [data.countries]);
    const summary = useMemo(() => topCountries(data.countries), [data.countries]);
    const markers = useMemo(() => createActivityMarkers(collection), [collection]);
    const countryCount = useMemo(() => new Set(data.countries.map((row) => alpha2(row.country)).filter((code): code is string => code !== null)).size, [data.countries]);

    useEffect(() => {
        const element = container.current;
        if (!element) return undefined;

        const dark = theme === 'dark';
        const base = dark ? '#0a1730' : '#f7f9ff';
        const countryFill = dark ? '#122a47' : '#dce6f7';
        const boundary = dark ? '#315f83' : '#a9b8d7';
        const map = new MapLibreMap({
            container: element,
            center: [0, 14],
            zoom: 0,
            minZoom: -1.5,
            maxZoom: 4,
            renderWorldCopies: false,
            maxPitch: 0,
            attributionControl: false,
            style: {
                version: 8,
                sources: {
                    countries: { type: 'geojson', data: collection },
                    'activity-markers': { type: 'geojson', data: markers },
                },
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
                                ['interpolate', ['linear'], ['get', 'deliveries'], 1, '#168dff', 1000, '#a649ff'],
                                ['>', ['get', 'engagement'], 0], dark ? '#0875c4' : '#729df6',
                                countryFill,
                            ],
                            'fill-opacity': 0.97,
                        },
                    },
                    {
                        id: 'country-outline',
                        type: 'line',
                        source: 'countries',
                        paint: { 'line-color': boundary, 'line-width': 0.55, 'line-opacity': 0.52 },
                    },
                    {
                        id: 'activity-marker-halo',
                        type: 'circle',
                        source: 'activity-markers',
                        paint: {
                            'circle-radius': ['case', ['any', ['>', ['get', 'deliveries'], 0], ['>', ['get', 'bounces'], 0]], 12, 9],
                            'circle-color': ['case', ['any', ['>', ['get', 'deliveries'], 0], ['>', ['get', 'bounces'], 0]], '#a348ff', '#05a9f5'],
                            'circle-opacity': dark ? 0.54 : 0.43,
                            'circle-blur': 0.85,
                        },
                    },
                    {
                        id: 'activity-marker-core',
                        type: 'circle',
                        source: 'activity-markers',
                        paint: {
                            'circle-radius': ['case', ['any', ['>', ['get', 'deliveries'], 0], ['>', ['get', 'bounces'], 0]], 3.8, 3.1],
                            'circle-color': ['case', ['any', ['>', ['get', 'deliveries'], 0], ['>', ['get', 'bounces'], 0]], '#a348ff', '#05a9f5'],
                            'circle-stroke-color': dark ? '#d9e4ff' : '#fff',
                            'circle-stroke-width': 1.5,
                        },
                    },
                ],
            },
        });
        let mapLoaded = false;
        const fitWorld = () => {
            if (!mapLoaded) return;
            map.resize();
            map.fitBounds([[-178, -56], [178, 78]], {
                padding: { top: 8, right: 8, bottom: 8, left: 8 },
                duration: 0,
            });
        };
        const resizeObserver = new ResizeObserver(fitWorld);
        resizeObserver.observe(element);
        map.addControl(new NavigationControl({ showCompass: false, visualizePitch: false }), 'top-right');
        map.addControl(new AttributionControl({ compact: true, customAttribution: 'Country boundaries: Natural Earth' }), 'bottom-right');
        const popup = new Popup({ closeButton: true, closeOnClick: true, maxWidth: '260px' });
        const showCountry = (event: { features?: Array<{ properties?: Record<string, unknown> | null }>; lngLat?: { lng: number; lat: number } }) => {
            const properties = event.features?.[0]?.properties as CountryProperties | undefined;
            if (!properties || !event.lngLat) return;
            popup.setLngLat(event.lngLat).setDOMContent(popupContent(properties)).addTo(map);
        };
        map.on('click', 'country-fill', showCountry);
        map.on('click', 'activity-marker-core', showCountry);
        map.on('mouseenter', 'country-fill', () => { map.getCanvas().style.cursor = 'pointer'; });
        map.on('mouseleave', 'country-fill', () => { map.getCanvas().style.cursor = ''; });
        map.once('load', () => {
            mapLoaded = true;
            const patterns: [string, string][] = dark
                ? [['country-dots-base', '#5b8bb1'], ['country-dots-activity', '#27bde7'], ['country-dots-feedback', '#b47aff']]
                : [['country-dots-base', '#7088b1'], ['country-dots-activity', '#347eef'], ['country-dots-feedback', '#9751ee']];
            patterns.forEach(([id, color]) => map.addImage(id, countryDotPattern(color)));
            map.setPaintProperty('country-fill', 'fill-pattern', [
                'case',
                ['any', ['>', ['get', 'deliveries'], 0], ['>', ['get', 'bounces'], 0]], 'country-dots-feedback',
                ['>', ['get', 'engagement'], 0], 'country-dots-activity',
                'country-dots-base',
            ]);
            fitWorld();
        });

        return () => {
            resizeObserver.disconnect();
            popup.remove();
            map.remove();
        };
    }, [collection, markers, theme]);

    const maximumEngagement = Math.max(1, ...summary.map(({ row }) => row.opens + row.clicks + row.leads + row.unsubscribes));

    return (
        <section className="command-os-panel command-os-geo-panel" id="deliverability-geo" aria-labelledby="command-os-geo-title">
            <div className="command-os-panel-heading">
                <div className="command-os-panel-title"><span className="command-os-heading-icon"><span aria-hidden="true">◎</span></span><h2 id="command-os-geo-title">Deliverability by GEO</h2></div>
                <span className="command-os-status-tag">{countryCount} countries</span>
            </div>
            <div className="command-os-geo-legend" aria-label="Map legend">
                <span><i className="command-os-legend-delivery" aria-hidden="true" />Delivery feedback</span>
                <span><i className="command-os-legend-engagement" aria-hidden="true" />Observed engagement</span>
            </div>
            <div className="command-os-geo-body">
                <div className="command-os-map-frame">
                    <div className="command-os-map" ref={container} role="application" aria-describedby="command-os-geo-note" aria-label="Interactive dotted world map. Markers represent approximate country-level activity aggregates, not recipient locations. Use zoom controls and select a country for its delivery feedback and engagement totals." />
                </div>
                <div className="command-os-geo-countries" aria-label="Countries with recorded activity">
                    {summary.length === 0 ? <div className="command-os-geo-empty" role="status">
                        <strong>No country data</strong>
                        <span>Country-coded activity will appear here.</span>
                    </div> : summary.map(({ code, name, row }) => {
                        const engagement = row.opens + row.clicks + row.leads + row.unsubscribes;
                        const relativeActivity = Math.round((engagement / maximumEngagement) * 100);
                        return <div className="command-os-country-row" key={code}>
                            <div className="command-os-country-label"><span aria-hidden="true">{code}</span><strong>{name}</strong></div>
                            <span className="command-os-country-count">{formatCount(engagement)} events</span>
                            <div className="command-os-country-track" role="meter" aria-label={`${name} relative engagement volume`} aria-valuemin={0} aria-valuemax={100} aria-valuenow={relativeActivity} aria-valuetext={`${formatCount(engagement)} engagement events, relative to the highest-activity country`}>
                                <span style={{ width: `${relativeActivity}%` }} />
                            </div>
                            <span className="command-os-country-feedback">{formatCount(row.deliveries)} delivery feedback · {formatCount(row.bounces)} bounces</span>
                        </div>;
                    })}
                </div>
            </div>
            <p className="command-os-sr-only" id="command-os-geo-note">{data.feedback_note}</p>
        </section>
    );
}
