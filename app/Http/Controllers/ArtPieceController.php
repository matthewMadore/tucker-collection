<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreArtPieceRequest;
use App\Models\ArtPiece;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ArtPieceController extends Controller
{
    public function index(): Response
    {
        $artPieces = ArtPiece::with(['images' => fn ($query) => $query->orderBy('position')])
            ->latest('id')
            ->paginate(24)
            ->through(fn (ArtPiece $artPiece) => [
                'id' => $artPiece->id,
                'name' => $artPiece->name,
                'price_cents' => $artPiece->price_cents,
                'status' => $artPiece->status,
                'images' => $artPiece->images->pluck('url')->all(),
            ]);

        return Inertia::render('Dashboard', ['artPieces' => $artPieces]);
    }

    public function store(StoreArtPieceRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $imageUrls = array_values(array_filter($validated['images'] ?? []));

        DB::transaction(function () use ($validated, $imageUrls) {
            $artPiece = ArtPiece::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? '',
                'price_cents' => (int) round($validated['price'] * 100),
            ]);

            foreach ($imageUrls as $position => $url) {
                $artPiece->images()->create([
                    'url' => $url,
                    'position' => $position,
                ]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Art piece added.')]);

        return redirect()->back();
    }
}
