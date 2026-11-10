<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AppGlobalPreference;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalNavigationPreferenceController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isOwner(), 403);

        $data = $request->validate([
            'groups' => ['required', 'array', 'max:40'],
            'groups.*' => ['required', 'string', 'max:80', 'regex:/^nav-[a-z0-9-]+$/', 'distinct'],
            'items' => ['required', 'array', 'max:40'],
            'items.*' => ['array', 'max:100'],
            'items.*.*' => ['required', 'string', 'max:120', 'regex:/^nav-item-[a-z0-9-]+$/', 'distinct'],
        ]);

        AppGlobalPreference::query()->updateOrCreate(
            ['name' => 'navigation_order'],
            ['value' => [
                'groups' => array_values($data['groups']),
                'items' => $data['items'],
            ]],
        );

        return response()->json(['message' => 'Navigation saved for all users.']);
    }
}
