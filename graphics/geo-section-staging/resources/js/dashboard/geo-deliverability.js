import * as echarts from 'echarts';
import worldJson from '../maps/world.json';

echarts.registerMap('world', worldJson);

/* ISO 3166-1 alpha-2 -> [longitude, latitude] */
const GEO_COORDS = {
    US: [-98.5, 39.8], CA: [-96, 56], MX: [-102, 23], BR: [-52, -10], AR: [-64, -35],
    CL: [-71, -31], CO: [-73, 4], PE: [-75, -10], VE: [-66, 7], EC: [-78.5, -1.5],
    UY: [-56, -33], PY: [-58.5, -23.5], BO: [-64.5, -17], CR: [-84, 9.9], PA: [-80.8, 8.6],
    DO: [-70.4, 18.9], GT: [-90.6, 15.7],
    GB: [-2.5, 54], IE: [-8.2, 53.2], FR: [2.5, 46.5], DE: [10.5, 51], ES: [-3.7, 40],
    PT: [-8, 39.6], IT: [12.5, 42.5], NL: [5.3, 52.2], BE: [4.7, 50.6], LU: [6.1, 49.6],
    CH: [8.2, 46.8], AT: [14.1, 47.6], SE: [15, 62.5], NO: [10, 64], DK: [10, 56],
    FI: [26, 64.5], IS: [-19, 64.8], PL: [19.4, 52], CZ: [15.5, 49.8], SK: [19.5, 48.7],
    HU: [19.5, 47.2], RO: [25, 45.8], BG: [25.5, 42.7], RS: [21, 44], HR: [15.5, 45.1],
    SI: [15, 46.1], GR: [22, 39], AL: [20, 41], LT: [24, 55.2], LV: [25, 56.9],
    EE: [25.8, 58.7], UA: [31, 48.5], RU: [90, 60], TR: [35, 39], MT: [14.4, 35.9],
    CY: [33, 35],
    NG: [8.7, 9.1], GH: [-1.2, 7.9], KE: [37.9, 0.5], TZ: [34.9, -6.4], UG: [32.4, 1.4],
    ET: [40.5, 8.6], ZA: [24.7, -29], EG: [30, 26.5], MA: [-6.5, 31.8], DZ: [2.6, 28.2],
    TN: [9.6, 34],
    IN: [79, 22], PK: [69.3, 30.4], BD: [90.3, 23.8], LK: [80.7, 7.6], NP: [84, 28.3],
    AF: [66, 33.8], IR: [53.7, 32.4], IQ: [43.7, 33], JO: [36, 31.2], LB: [35.7, 33.9],
    IL: [34.9, 31], SA: [45, 24], AE: [54, 24], QA: [51.2, 25.3], KW: [47.5, 29.3],
    BH: [50.55, 26], OM: [56, 20.6], AZ: [47.5, 40.3], GE: [43.5, 42], AM: [45, 40.3],
    KZ: [68, 48], UZ: [64, 41.5], MN: [103.8, 46.9],
    CN: [104, 35.5], JP: [138, 37], KR: [127.5, 36.5], TW: [121, 23.8], HK: [114.1, 22.3],
    MO: [113.5, 22.2], SG: [103.8, 1.35], MY: [101.9, 4.2], TH: [101, 15.5], VN: [106, 16],
    PH: [122, 12], ID: [113.5, -2], KH: [104.9, 12.6], LA: [103.8, 18.5], MM: [96, 17],
    AU: [134, -25], NZ: [172.8, -41.8],
};

const COUNTRY_NAMES = {
    US: 'United States', GB: 'United Kingdom', CA: 'Canada', AU: 'Australia', DE: 'Germany',
    FR: 'France', IN: 'India', BR: 'Brazil', MX: 'Mexico', ES: 'Spain', IT: 'Italy',
    NL: 'Netherlands', SE: 'Sweden', NO: 'Norway', DK: 'Denmark', FI: 'Finland', PL: 'Poland',
    JP: 'Japan', KR: 'South Korea', CN: 'China', SG: 'Singapore', ZA: 'South Africa',
    NG: 'Nigeria', KE: 'Kenya', EG: 'Egypt', AE: 'United Arab Emirates', SA: 'Saudi Arabia',
    IE: 'Ireland', PT: 'Portugal', BE: 'Belgium', CH: 'Switzerland', AT: 'Austria',
    CZ: 'Czechia', HU: 'Hungary', RO: 'Romania', GR: 'Greece', TR: 'Turkey', IL: 'Israel',
    RU: 'Russia', UA: 'Ukraine', AR: 'Argentina', CL: 'Chile', CO: 'Colombia', PE: 'Peru',
    NZ: 'New Zealand', TH: 'Thailand', MY: 'Malaysia', VN: 'Vietnam', PH: 'Philippines',
    ID: 'Indonesia', PK: 'Pakistan', BD: 'Bangladesh', LK: 'Sri Lanka', NP: 'Nepal',
    QA: 'Qatar', KW: 'Kuwait', BH: 'Bahrain', OM: 'Oman', JO: 'Jordan', LB: 'Lebanon',
    KZ: 'Kazakhstan', UZ: 'Uzbekistan', MA: 'Morocco', DZ: 'Algeria', TN: 'Tunisia',
    GH: 'Ghana', TZ: 'Tanzania', UG: 'Uganda', ET: 'Ethiopia', IS: 'Iceland', LU: 'Luxembourg',
    SI: 'Slovenia', HR: 'Croatia', RS: 'Serbia', BG: 'Bulgaria', SK: 'Slovakia',
    LT: 'Lithuania', LV: 'Latvia', EE: 'Estonia', MT: 'Malta', CY: 'Cyprus',
    PA: 'Panama', CR: 'Costa Rica', DO: 'Dominican Republic', GT: 'Guatemala',
    UY: 'Uruguay', PY: 'Paraguay', BO: 'Bolivia', VE: 'Venezuela', EC: 'Ecuador',
    TW: 'Taiwan', HK: 'Hong Kong', MO: 'Macao', KH: 'Cambodia', LA: 'Laos', MM: 'Myanmar',
    MN: 'Mongolia', AZ: 'Azerbaijan', GE: 'Georgia', AM: 'Armenia', AF: 'Afghanistan',
    IR: 'Iran', IQ: 'Iraq', ZW: 'Zimbabwe', AO: 'Angola', CM: 'Cameroon', SN: 'Senegal',
};

const flagUrl = (code) => `https://flagcdn.com/w40/${code.toLowerCase()}.png`;

function renderList(listEl, countries) {
    listEl.innerHTML = countries.slice(0, 6).map((c) => `
        <li class="geo-item">
            <img class="geo-item__flag" src="${flagUrl(c.code)}" alt="${c.code} flag" loading="lazy">
            <span class="geo-item__name">${COUNTRY_NAMES[c.code] ?? c.code}</span>
            <span class="geo-item__pct">${c.percent.toFixed(1)}%</span>
            <div class="geo-item__bar"><span data-pct="${Math.min(c.percent, 100)}"></span></div>
        </li>
    `).join('');

    // Animate the bars after paint.
    requestAnimationFrame(() =>
        listEl.querySelectorAll('.geo-item__bar > span').forEach((bar) => {
            bar.style.width = `${bar.dataset.pct}%`;
        })
    );
}

function renderMap(mapEl, countries) {
    const chart = echarts.init(mapEl);

    const points = countries
        .filter((c) => GEO_COORDS[c.code])
        .slice(0, 15)
        .map((c) => ({
            name: COUNTRY_NAMES[c.code] ?? c.code,
            value: [...GEO_COORDS[c.code], c.percent],
            count: c.count,
        }));

    chart.setOption({
        geo: {
            map: 'world',
            roam: false,
            zoom: 1.15,
            top: '8%',
            bottom: '8%',
            itemStyle: {
                areaColor: '#e9edf9',      // land
                borderColor: '#ffffff',
                borderWidth: 0.6,
            },
            emphasis: { disabled: true },
            silent: true,
        },
        tooltip: {
            trigger: 'item',
            backgroundColor: '#17172b',
            borderWidth: 0,
            textStyle: { color: '#fff', fontSize: 12 },
            formatter: (p) => {
                if (p.seriesType !== 'effectScatter') return '';
                return `<b>${p.name}</b><br>${p.data.count.toLocaleString()} recipients · ${p.value[2]}%`;
            },
        },
        series: [
            {
                // Soft halo underneath each point
                type: 'scatter',
                coordinateSystem: 'geo',
                data: points,
                symbolSize: (v) => 8 + Math.min(v[2], 100) * 0.25,
                itemStyle: { color: 'rgba(124, 58, 237, 0.25)' },
                silent: true,
                zlevel: 1,
            },
            {
                // Glowing ripple point (the dots in your screenshot)
                type: 'effectScatter',
                coordinateSystem: 'geo',
                data: points,
                symbolSize: 7,
                rippleEffect: { brushType: 'stroke', scale: 3.2 },
                itemStyle: {
                    color: '#7c3aed',
                    shadowBlur: 12,
                    shadowColor: 'rgba(124, 58, 237, 0.9)',
                },
                zlevel: 2,
            },
        ],
    });

    window.addEventListener('resize', () => chart.resize());
    return chart;
}

/**
 * Boots the widget.
 *
 * @param {object}  options
 * @param {string}  options.endpoint  URL of the geo-deliverability endpoint
 * @param {Element|string} [options.mapEl]  Map container (default #geo-map)
 * @param {Element|string} [options.listEl]  List container (default #geo-list)
 */
export function initDeliverabilityGeo({ endpoint, mapEl = '#geo-map', listEl = '#geo-list' }) {
    const map = typeof mapEl === 'string' ? document.querySelector(mapEl) : mapEl;
    const list = typeof listEl === 'string' ? document.querySelector(listEl) : listEl;
    if (!map || !list) return;

    fetch(endpoint, { headers: { Accept: 'application/json' } })
        .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
        .then(({ countries }) => {
            if (!countries?.length) {
                list.innerHTML = '<li class="geo-item__name">No location data yet.</li>';
                return;
            }
            renderList(list, countries);
            renderMap(map, countries);
        })
        .catch(() => {
            list.innerHTML = '<li class="geo-item__name">Could not load location data.</li>';
        });
}

/* Auto-boot when the section is present on the page. */
document.addEventListener('DOMContentLoaded', () => {
    const section = document.getElementById('geo-deliverability');
    if (!section) return;

    initDeliverabilityGeo({
        endpoint: section.dataset.endpoint, // set as data-endpoint in the Blade partial
    });
});
