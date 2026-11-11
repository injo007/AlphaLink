# Deliverability by GEO — Laravel + Vite dashboard section

A drop-in "Deliverability by GEO" widget for your SaaS dashboard:
a world map with glowing geo points (one per country) plus a ranked
country list with flags and percentage bars.

```
app/Http/Controllers/Dashboard/DeliverabilityGeoController.php   ← API endpoint (aggregates recipients per country)
database/migrations/...add_country_to_recipients_table.php       ← only if you don't store country yet
resources/views/dashboard/partials/geo-deliverability.blade.php  ← the section markup + styles
resources/js/dashboard/geo-deliverability.js                     ← ECharts map + list rendering
resources/js/maps/world.json                                     ← world map geometry (~1 MB, bundled)
demo.html                                                        ← standalone preview with mock data (just open in a browser)
```

## 1. Install ECharts

```bash
npm i echarts
```

`world.json` is already included in this package — no CDN needed at runtime.

## 2. Copy the files into your Laravel app

Keep the same relative paths:

- `app/Http/Controllers/Dashboard/DeliverabilityGeoController.php`
- `resources/views/dashboard/partials/geo-deliverability.blade.php`
- `resources/js/dashboard/geo-deliverability.js`
- `resources/js/maps/world.json`

## 3. Add the route

In `routes/web.php` (inside your `auth` middleware group):

```php
use App\Http\Controllers\Dashboard\DeliverabilityGeoController;

Route::get('/dashboard/geo-deliverability', DeliverabilityGeoController::class)
    ->middleware('auth')
    ->name('dashboard.geo-deliverability');
```

## 4. Register the Vite entry

In `vite.config.js`, add the JS file to the `input` array:

```js
input: [
    'resources/css/app.css',
    'resources/js/app.js',
    'resources/js/dashboard/geo-deliverability.js', // ← add
],
```

## 5. Include the section in your dashboard Blade

```blade
@vite(['resources/js/dashboard/geo-deliverability.js'])

{{-- wherever you want the widget: --}}
@include('dashboard.partials.geo-deliverability')
```

Then run `npm run build` (or `npm run dev`).

## 6. Wire up your data

Open `DeliverabilityGeoController.php` and adjust two things:

- **Table name** — replace `recipients` with your actual table.
- **Scoping** — replace `->where('user_id', auth()->id())` with your
  multi-tenancy logic (team_id, campaign filter, etc.).

The controller expects a `country` column storing **ISO 3166-1 alpha-2**
codes (`US`, `GB`, `DE`, …). Percentage = that country's share of all
recipients in scope.

### If you don't store country yet

1. Run the included migration (edit the table name first):

   ```bash
   php artisan migrate
   ```

2. Fill the column when a recipient is created or imported. Common options:
   - **MaxMind GeoLite2** (`composer require geoip2/geoip2`) — free local DB,
     resolved from the recipient's signup/event IP.
   - A lookup API (e.g. ip-api.com) — simplest, fine at low volume.

## Customization notes

- **Deliverability rate instead of share**: if the percentage should be
  "successfully delivered %", change the controller query to aggregate
  from your sends/bounces table and compute
  `delivered / sent * 100` per country.
- **Flags** load from `flagcdn.com`. If you prefer self-hosted flags,
  download the set and change `flagUrl()` in the JS file.
- **Map point size** scales with percentage (`symbolSize` in the
  scatter series); **ripple glow** is the `effectScatter` series.
- More/fewer list rows: change `.slice(0, 6)` in `renderList()`.
