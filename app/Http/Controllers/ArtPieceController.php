<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreArtPieceRequest;
use App\Models\ArtPiece;
use App\Models\ArtPieceImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

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

        return Inertia::render('Dashboard', [
            'artPieces' => $artPieces,
            'imageUploadLimits' => StoreArtPieceRequest::imageUploadLimits(),
        ]);
    }

    public function show(ArtPiece $artPiece): Response
    {
        $artPiece->load(['images' => fn ($query) => $query->orderBy('position')]);

        return Inertia::render('art-pieces/Show', [
            'artPiece' => [
                'id' => $artPiece->id,
                'name' => $artPiece->name,
                'description' => $artPiece->description,
                'price_cents' => $artPiece->price_cents,
                'status' => $artPiece->status,
                'images' => $artPiece->images->map(fn (ArtPieceImage $image) => [
                    'id' => $image->id,
                    'url' => $image->url,
                ])->all(),
            ],
        ]);
    }

    public function store(StoreArtPieceRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        /** @var list<UploadedFile> $uploadedImages */
        $uploadedImages = array_values($validated['images'] ?? []);

        /** @var list<string> $storedPaths */
        $storedPaths = [];

        try {
            foreach ($uploadedImages as $image) {
                $path = $image->store('art-pieces', ArtPieceImage::DISK);

                if ($path === false) {
                    throw new RuntimeException('Failed to store an uploaded art piece image.');
                }

                $storedPaths[] = $path;
            }

            DB::transaction(function () use ($validated, $storedPaths) {
                $artPiece = ArtPiece::create([
                    'name' => $validated['name'],
                    'description' => $validated['description'] ?? '',
                    'price_cents' => (int) round($validated['price'] * 100),
                ]);

                foreach ($storedPaths as $position => $path) {
                    $artPiece->images()->create([
                        'path' => $path,
                        'position' => $position,
                    ]);
                }
            });
        } catch (Throwable $exception) {
            Storage::disk(ArtPieceImage::DISK)->delete($storedPaths);

            throw $exception;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Art piece added.')]);

        return redirect()->back();
    }
}
