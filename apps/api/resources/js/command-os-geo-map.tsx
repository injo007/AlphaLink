/// <reference path="./world-atlas.d.ts" />
import { useEffect, useMemo, useRef, useState } from 'react';
import type { JSX } from 'react';
import { AttributionControl, Map as MapLibreMap, NavigationControl, Popup, setWorkerUrl } from 'maplibre-gl';
import type { Feature, FeatureCollection, Geometry, Point } from 'geojson';
import type { MapLayerMouseEvent } from 'maplibre-gl';
import { Globe2 } from 'lucide-react';
import { feature } from 'topojson-client';
import { getAlpha2Code, getName, getSimpleAlpha2Code, isValid, numericToAlpha2, registerLocale } from 'i18n-iso-countries';
import englishLocale from 'i18n-iso-countries/langs/en.json';
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';
import 'maplibre-gl/dist/maplibre-gl.css';
import worldAtlas from 'world-atlas/countries-110m.json';
import type { DashboardAnalytics, DashboardGeoMetric, GeoCountry } from './command-os';

registerLocale(englishLocale);
setWorkerUrl(workerUrl);

type CountryProperties = {
    iso2: string;
    name: string;
    hasData: boolean;
    deliveries: number;
    bounces: number;
    engagement: number;
    opens: number;
    clicks: number;
    leads: number;
    unsubscribes: number;
};

type MapTheme = 'light' | 'dark';
type GeoMetric = DashboardGeoMetric;

const geoMetrics: { key: GeoMetric; label: string; source: string }[] = [
    { key: 'deliveries', label: 'Deliveries', source: 'Send-country feedback' },
    { key: 'opens', label: 'Opens', source: 'Observed event country' },
    { key: 'clicks', label: 'Clicks', source: 'Observed event country' },
    { key: 'leads', label: 'Leads', source: 'Attributed conversion country' },
    { key: 'bounces', label: 'Bounces', source: 'Send-country feedback' },
    { key: 'unsubscribes', label: 'Unsubscribes', source: 'Observed event country' },
];

function metricValue(row: GeoCountry, metric: GeoMetric): number {
    return row[metric];
}

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
                    hasData: stat !== undefined,
                    deliveries: stat?.deliveries ?? 0,
                    bounces: stat?.bounces ?? 0,
                    engagement,
                    opens: stat?.opens ?? 0,
                    clicks: stat?.clicks ?? 0,
                    leads: stat?.leads ?? 0,
                    unsubscribes: stat?.unsubscribes ?? 0,
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

function popupContent(properties: CountryProperties, metric: GeoMetric): HTMLElement {
    const content = document.createElement('div');
    content.className = 'command-os-map-popup';
    const heading = document.createElement('strong');
    heading.textContent = properties.name;
    content.append(heading);
    const value = properties[metric];
    if (value === 0) {
        const empty = document.createElement('span');
        empty.textContent = `No ${geoMetrics.find((item) => item.key === metric)?.label.toLowerCase()} were recorded for this country in the selected range.`;
        content.append(empty);
        return content;
    }
    const activeMetric = document.createElement('span');
    activeMetric.textContent = `${formatCount(value)} ${geoMetrics.find((item) => item.key === metric)?.label.toLowerCase()} · ${geoMetrics.find((item) => item.key === metric)?.source.toLowerCase()}`;
    content.append(activeMetric);
    const breakdown = document.createElement('span');
    breakdown.textContent = `${formatCount(properties.deliveries)} deliveries · ${formatCount(properties.opens)} opens · ${formatCount(properties.clicks)} clicks · ${formatCount(properties.leads)} leads · ${formatCount(properties.bounces)} bounces · ${formatCount(properties.unsubscribes)} unsubscribes`;
    content.append(breakdown);
    return content;
}

function combineCountries(countries: GeoCountry[]): { code: string; name: string; row: GeoCountry }[] {
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
    return [...combined.entries()].map(([code, row]) => ({ code, name: countryName(code, row.country), row }));
}

function topCountries(countries: GeoCountry[], metric: GeoMetric): { code: string; name: string; row: GeoCountry }[] {
    return combineCountries(countries)
        .filter(({ row }) => metricValue(row, metric) > 0)
        .sort((left, right) => metricValue(right.row, metric) - metricValue(left.row, metric))
        .slice(0, 4);
}

function applyMetricStyle(map: MapLibreMap, metric: GeoMetric, countries: GeoCountry[], dark: boolean): void {
    const maximum = Math.max(1, ...countries.map((row) => metricValue(row, metric)));
    const feedback = metric === 'deliveries' || metric === 'bounces';
    const accent = feedback ? '#a348ff' : '#079ee8';
    const low = dark ? '#214265' : '#cbd9f2';
    const high = feedback ? '#a348ff' : '#168dff';

    map.setPaintProperty('country-fill', 'fill-color', [
        'case',
        ['>', ['get', metric], 0],
        ['interpolate', ['linear'], ['get', metric], 0, low, maximum, high],
        dark ? '#122a47' : '#dce6f7',
    ]);
    map.setPaintProperty('country-fill', 'fill-pattern', [
        'case',
        ['>', ['get', metric], 0],
        feedback ? 'country-dots-feedback' : 'country-dots-activity',
        'country-dots-base',
    ]);
    map.setPaintProperty('activity-marker-halo', 'circle-radius', ['interpolate', ['linear'], ['get', metric], 0, 0, maximum, 14]);
    map.setPaintProperty('activity-marker-halo', 'circle-color', accent);
    map.setPaintProperty('activity-marker-halo', 'circle-opacity', ['case', ['>', ['get', metric], 0], dark ? 0.6 : 0.48, 0]);
    map.setPaintProperty('activity-marker-core', 'circle-radius', ['interpolate', ['linear'], ['get', metric], 0, 0, maximum, 4.2]);
    map.setPaintProperty('activity-marker-core', 'circle-color', accent);
    map.setPaintProperty('activity-marker-core', 'circle-opacity', ['case', ['>', ['get', metric], 0], 1, 0]);
}

export default function DeliverabilityGeoMap({ data, theme, selectedMetric, onMetricChange }: { data: DashboardAnalytics['geo']; theme: MapTheme; selectedMetric: GeoMetric; onMetricChange: (metric: GeoMetric) => void }): JSX.Element {
    const container = useRef<HTMLDivElement>(null);
    const mapRef = useRef<MapLibreMap | null>(null);
    const popupRef = useRef<Popup | null>(null);
    const mapLoadedRef = useRef(false);
    const activeCountryRef = useRef<string | null>(null);
    const activeMetricRef = useRef<GeoMetric>('deliveries');
    const selectedCountryRef = useRef<string | null>(null);
    const previousSelectedCountryRef = useRef<string | null>(null);
    const [selectedCountry, setSelectedCountry] = useState<string | null>(null);
    const [mapError, setMapError] = useState(false);
    activeMetricRef.current = selectedMetric;
    selectedCountryRef.current = selectedCountry;
    const collection = useMemo(() => createCountryCollection(data.countries), [data.countries]);
    const summary = useMemo(() => topCountries(data.countries, selectedMetric), [data.countries, selectedMetric]);
    const activeCountryCount = useMemo(
        () => combineCountries(data.countries).filter(({ row }) => metricValue(row, selectedMetric) > 0).length,
        [data.countries, selectedMetric],
    );
    const markers = useMemo(() => createActivityMarkers(collection), [collection]);
    const selectedCountryData = selectedCountry
        ? combineCountries(data.countries).find((country) => country.code === selectedCountry)?.row ?? null
        : null;
    const selectedCountryName = selectedCountry
        ? countryName(selectedCountry, selectedCountryData?.country)
        : '';
    const maximumMetric = Math.max(1, ...data.countries.map((row) => metricValue(row, selectedMetric)));

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
                    countries: { type: 'geojson', data: collection, promoteId: 'iso2' },
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
                        paint: {
                            'line-color': ['case', ['boolean', ['feature-state', 'selected'], false], '#9256ff', boundary],
                            'line-width': ['case', ['boolean', ['feature-state', 'selected'], false], 1.8, 0.55],
                            'line-opacity': ['case', ['boolean', ['feature-state', 'selected'], false], 0.98, 0.52],
                        },
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
        setMapError(false);
        map.on('error', () => setMapError(true));
        mapRef.current = map;
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
        popupRef.current = popup;
        const showCountry = (event: MapLayerMouseEvent) => {
            const properties = event.features?.[0]?.properties as CountryProperties | undefined;
            if (!properties) return;
            if (activeCountryRef.current !== properties.iso2 || activeMetricRef.current !== selectedMetric) {
                popup.setDOMContent(popupContent(properties, activeMetricRef.current));
                activeCountryRef.current = properties.iso2;
            }
            popup.setLngLat(event.lngLat).addTo(map);
        };
        const selectCountry = (event: MapLayerMouseEvent) => {
            showCountry(event);
            const properties = event.features?.[0]?.properties as CountryProperties | undefined;
            if (properties?.iso2) setSelectedCountry(properties.iso2);
        };
        map.on('click', 'country-fill', selectCountry);
        map.on('click', 'activity-marker-core', selectCountry);
        map.on('mousemove', 'country-fill', showCountry);
        map.on('mousemove', 'activity-marker-core', showCountry);
        map.on('mouseenter', 'country-fill', () => { map.getCanvas().style.cursor = 'pointer'; });
        map.on('mouseenter', 'activity-marker-core', () => { map.getCanvas().style.cursor = 'pointer'; });
        map.on('mouseleave', 'country-fill', () => { map.getCanvas().style.cursor = ''; });
        map.on('mouseleave', 'activity-marker-core', () => { map.getCanvas().style.cursor = ''; });
        map.on('mouseout', () => { map.getCanvas().style.cursor = ''; popup.remove(); activeCountryRef.current = null; });
        map.once('load', () => {
            mapLoaded = true;
            mapLoadedRef.current = true;
            const patterns: [string, string][] = dark
                ? [['country-dots-base', '#5b8bb1'], ['country-dots-activity', '#27bde7'], ['country-dots-feedback', '#b47aff']]
                : [['country-dots-base', '#7088b1'], ['country-dots-activity', '#347eef'], ['country-dots-feedback', '#9751ee']];
            patterns.forEach(([id, color]) => map.addImage(id, countryDotPattern(color)));
            applyMetricStyle(map, activeMetricRef.current, data.countries, dark);
            if (selectedCountryRef.current) {
                map.setFeatureState({ source: 'countries', id: selectedCountryRef.current }, { selected: true });
                previousSelectedCountryRef.current = selectedCountryRef.current;
            }
            fitWorld();
        });

        return () => {
            resizeObserver.disconnect();
            popup.remove();
            map.remove();
            mapRef.current = null;
            popupRef.current = null;
            mapLoadedRef.current = false;
            previousSelectedCountryRef.current = null;
        };
    }, [collection, markers, theme, data.countries]);

    useEffect(() => {
        if (!mapLoadedRef.current || !mapRef.current) return;
        applyMetricStyle(mapRef.current, selectedMetric, data.countries, theme === 'dark');
        popupRef.current?.remove();
        activeCountryRef.current = null;
    }, [selectedMetric, data.countries, theme]);

    useEffect(() => {
        const map = mapRef.current;
        if (!mapLoadedRef.current || !map) return;
        if (previousSelectedCountryRef.current) {
            map.setFeatureState({ source: 'countries', id: previousSelectedCountryRef.current }, { selected: false });
        }
        if (selectedCountry) {
            map.setFeatureState({ source: 'countries', id: selectedCountry }, { selected: true });
        }
        previousSelectedCountryRef.current = selectedCountry;
    }, [selectedCountry]);

    const selectedMetricDefinition = geoMetrics.find((metric) => metric.key === selectedMetric)!;

    return (
        <section className="command-os-panel command-os-geo-panel" id="deliverability-geo" aria-labelledby="command-os-geo-title">
            <div className="command-os-panel-heading">
                <div className="command-os-panel-title"><span className="command-os-heading-icon"><Globe2 size={16} aria-hidden="true" /></span><h2 id="command-os-geo-title">Deliverability by GEO</h2></div>
                <label className="command-os-geo-metric-filter">
                    <span className="command-os-sr-only">Map metric</span>
                    <select value={selectedMetric} onChange={(event) => {
                        const nextMetric = event.currentTarget.value;
                        if (geoMetrics.some((metric) => metric.key === nextMetric)) onMetricChange(nextMetric as GeoMetric);
                    }} aria-label="Map metric">
                        {geoMetrics.map((metric) => <option value={metric.key} key={metric.key}>{metric.label}</option>)}
                        <option value="revenue" disabled>Revenue · no country attribution</option>
                    </select>
                </label>
                <span className="command-os-status-tag">{activeCountryCount} {activeCountryCount === 1 ? 'country' : 'countries'}</span>
            </div>
            <div className="command-os-geo-legend" aria-label="Map legend">
                <span><i className="command-os-legend-delivery" aria-hidden="true" />Delivery feedback</span>
                <span><i className="command-os-legend-engagement" aria-hidden="true" />Observed engagement</span>
            </div>
            <div className="command-os-geo-body">
                <div className="command-os-map-frame">
                    <div className="command-os-map" ref={container} role="application" aria-describedby="command-os-geo-note" aria-label={`Interactive dotted world map showing ${selectedMetricDefinition.label} by country. Markers represent country-level aggregates, not recipient locations. Use zoom controls and select a country for its activity totals.`} />
                    {mapError && <div className="command-os-map-error" role="status"><strong>Map unavailable</strong><span>The map could not finish rendering. Country totals remain available in the adjacent list.</span></div>}
                </div>
                <div className={`command-os-geo-countries${summary.length === 0 ? ' command-os-geo-countries-empty' : ''}`} aria-label="Countries with recorded activity">
                    {selectedCountryData && <section className="command-os-geo-selected-country" aria-label={`${selectedCountryName} activity details`}>
                        <div><strong>{selectedCountryName}</strong><button type="button" onClick={() => setSelectedCountry(null)} aria-label="Clear selected country">Clear</button></div>
                        <b>{formatCount(metricValue(selectedCountryData, selectedMetric))} <span>{selectedMetricDefinition.label.toLowerCase()}</span></b>
                        <small>{selectedMetricDefinition.source}. Campaign, ISP, offer, list and country-level revenue joins are not present in this GEO data feed.</small>
                    </section>}
                    {summary.length === 0 ? <div className="command-os-geo-empty" role="status">
                        <strong>No {selectedMetricDefinition.label.toLowerCase()} by country</strong>
                        <span>No country-coded {selectedMetricDefinition.label.toLowerCase()} were recorded for this date range.</span>
                    </div> : summary.map(({ code, name, row }) => {
                        const relativeActivity = Math.round((metricValue(row, selectedMetric) / maximumMetric) * 100);
                        return <button className={`command-os-country-row${selectedCountry === code ? ' is-selected' : ''}`} key={code} type="button" aria-pressed={selectedCountry === code} onClick={() => setSelectedCountry(selectedCountry === code ? null : code)}>
                            <div className="command-os-country-label"><span aria-hidden="true">{code}</span><strong>{name}</strong></div>
                            <span className="command-os-country-count">{formatCount(metricValue(row, selectedMetric))} {selectedMetricDefinition.label.toLowerCase()}</span>
                            <div className="command-os-country-track" role="meter" aria-label={`${name} relative ${selectedMetricDefinition.label.toLowerCase()} volume`} aria-valuemin={0} aria-valuemax={100} aria-valuenow={relativeActivity} aria-valuetext={`${formatCount(metricValue(row, selectedMetric))} ${selectedMetricDefinition.label.toLowerCase()}, relative to the highest-activity country`}>
                                <span style={{ width: `${relativeActivity}%` }} />
                            </div>
                            <span className="command-os-country-feedback">{formatCount(row.deliveries)} delivery feedback · {formatCount(row.bounces)} bounce feedback</span>
                        </button>;
                    })}
                </div>
            </div>
            <p className="command-os-geo-note" id="command-os-geo-note">{data.feedback_note} {formatCount(data.proxy_opens_excluded)} proxy or unclassified opens excluded from GEO.</p>
        </section>
    );
}
