{{--
    Deliverability by GEO — dashboard section.

    1. Include this partial anywhere in your dashboard Blade:
         @include('dashboard.partials.geo-deliverability')

    2. Register the JS entry in vite.config.js (or import it from app.js):
         input: ['resources/css/app.css', 'resources/js/app.js',
                 'resources/js/dashboard/geo-deliverability.js']

    3. Load it with the Vite directive:
         @vite(['resources/js/dashboard/geo-deliverability.js'])
--}}

<section class="geo-card" id="geo-deliverability"
         data-endpoint="{{ route('dashboard.geo-deliverability') }}">
    <header class="geo-card__header">
        <span class="geo-card__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6">
                <circle cx="12" cy="12" r="9"/>
                <path d="M3 12h18M12 3c2.5 2.6 3.9 5.7 3.9 9S14.5 18.4 12 21c-2.5-2.6-3.9-5.7-3.9-9S9.5 5.6 12 3z"/>
            </svg>
        </span>
        <h3>Deliverability by GEO</h3>
    </header>

    <div class="geo-card__body">
        <div id="geo-map" class="geo-card__map" role="img" aria-label="World map of recipient locations"></div>
        <ul id="geo-list" class="geo-card__list"></ul>
    </div>
</section>

<style>
    .geo-card {
        background: #ffffff;
        border: 1px solid #ececf4;
        border-radius: 14px;
        padding: 20px 24px;
        box-shadow: 0 1px 3px rgba(16, 24, 40, .05);
        font-family: inherit;
    }
    .geo-card__header {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
    }
    .geo-card__icon {
        display: grid;
        place-items: center;
        width: 30px;
        height: 30px;
        border-radius: 9px;
        color: #7c3aed;
        background: #f3efff;
    }
    .geo-card__header h3 {
        margin: 0;
        font-size: 15px;
        font-weight: 600;
        color: #17172b;
    }
    .geo-card__body {
        display: flex;
        align-items: center;
        gap: 24px;
    }
    .geo-card__map {
        flex: 1 1 60%;
        min-width: 0;
        height: 260px;
    }
    .geo-card__list {
        flex: 0 0 260px;
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 14px;
    }
    .geo-item {
        display: grid;
        grid-template-columns: 26px 1fr auto;
        align-items: center;
        column-gap: 10px;
        row-gap: 5px;
    }
    .geo-item__flag {
        width: 22px;
        height: 16px;
        border-radius: 3px;
        object-fit: cover;
        box-shadow: 0 0 0 1px rgba(0, 0, 0, .06);
    }
    .geo-item__name {
        font-size: 13px;
        font-weight: 500;
        color: #3c3c55;
    }
    .geo-item__pct {
        font-size: 13px;
        font-weight: 600;
        color: #17172b;
        font-variant-numeric: tabular-nums;
    }
    .geo-item__bar {
        grid-column: 1 / -1;
        height: 6px;
        border-radius: 999px;
        background: #eef0f6;
        overflow: hidden;
    }
    .geo-item__bar > span {
        display: block;
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, #22c55e, #4ade80);
        width: 0;
        transition: width .8s cubic-bezier(.22, 1, .36, 1);
    }
    @media (max-width: 760px) {
        .geo-card__body { flex-direction: column; align-items: stretch; }
        .geo-card__list { flex-basis: auto; }
    }
</style>
