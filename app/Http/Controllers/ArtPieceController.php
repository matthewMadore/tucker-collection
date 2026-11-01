<?php

namespace App\Http\Controllers;

use App\Models\ArtPiece;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ArtPieceController extends Controller
{
    public function store(Request $request)
    {
        $artPiece = ArtPiece::create([
            'name' => $request->input('name'),
            'description' => $request->input('description', ''),
            'price_cents' => (int) round($request->input('price') * 100),
        ]);

        foreach ($request->input('images', []) as $position => $url) {
            if (trim($url) === '') {
                continue;
            }

            $artPiece->images()->create([
                'url' => $url,
                'position' => $position,
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Art piece added.')]);

        return redirect()->back();
    }
}
