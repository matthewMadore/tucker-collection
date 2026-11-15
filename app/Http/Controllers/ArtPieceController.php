<?php

namespace App\Http\Controllers;

use App\Enums\ArtPieceStatus;
use App\Http\Requests\StoreArtPieceRequest;
use App\Http\Requests\UpdateArtPieceStatusRequest;
use App\Models\ArtPiece;
use App\Models\ArtPieceImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Imagick;
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
                'status' => $artPiece->status->value,
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
        $hasOpenOrder = $artPiece->hasOpenOrder();

        return Inertia::render('art-pieces/Show', [
            'artPiece' => [
                'id' => $artPiece->id,
                'name' => $artPiece->name,
                'description' => $artPiece->description,
                'price_cents' => $artPiece->price_cents,
                'status' => $artPiece->status->value,
                'allowed_statuses' => $hasOpenOrder ? [] : array_map(fn (ArtPieceStatus $status) => $status->value, $artPiece->status->allowedTransitions()),
                'has_open_order' => $hasOpenOrder,
                'images' => $artPiece->images->map(fn (ArtPieceImage $image) => [
                    'id' => $image->id,
                    'url' => $image->url,
                ])->all(),
            ],
            'statuses' => ArtPieceStatus::options(),
        ]);
    }

    public function updateStatus(UpdateArtPieceStatusRequest $request, ArtPiece $artPiece): RedirectResponse
    {
        $artPiece->transitionTo($request->enum('status', ArtPieceStatus::class));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Status changed to :status.', [
            'status' => $artPiece->status->label(),
        ])]);

        return redirect()->back();
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
                $this->stripMetadata($image);
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

    /**
     * Photos are public, and phone photos carry EXIF metadata: often the GPS
     * location they were taken at (home), plus device details. Rewrites the
     * upload without it. The rotation tag goes too, so the rotation is applied
     * to the pixels first; the color profile is kept, so wide-gamut (Display
     * P3) phone photos of art don't come out washed out.
     */
    private function stripMetadata(UploadedFile $image): void
    {
        $imagick = new Imagick($image->getRealPath());
        $colorProfile = $imagick->getImageProfiles('icc')['icc'] ?? null;

        $imagick->autoOrient();
        $imagick->stripImage();

        if ($colorProfile !== null) {
            $imagick->profileImage('icc', $colorProfile);
        }

        $imagick->writeImage($image->getRealPath());
        $imagick->clear();
    }
}
