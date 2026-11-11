<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class DeliverabilityGeoController extends Controller
{
    /**
     * Deliverability by GEO — aggregates recipients per country and returns
     * JSON consumed by the dashboard widget (map points + ranked list).
     *
     * Expected JSON shape:
     * {
     *   "total": 15230,
     *   "countries": [
     *     { "code": "US", "count": 14986, "percent": 98.4 },
     *     ...
     *   ]
     * }
     */
    public function __invoke()
    {
        // Adjust "recipients" to your actual table name, and the scoping
        // column (user_id / team_id / campaign_id) to your multi-tenancy.
        $rows = DB::table('recipients')
            ->select('country', DB::raw('COUNT(*) as total'))
            ->where('user_id', auth()->id())          // ← your scoping
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->groupBy('country')
            ->orderByDesc('total')
            ->limit(50)
            ->get();

        $grandTotal = max(1, $rows->sum('total')); // avoid division by zero

        $countries = $rows->map(fn ($row) => [
            'code'    => strtoupper($row->country),   // store ISO 3166-1 alpha-2
            'count'   => (int) $row->total,
            'percent' => round($row->total / $grandTotal * 100, 1),
        ])->values();

        return response()->json([
            'total'     => (int) $grandTotal,
            'countries' => $countries,
        ]);
    }
}
