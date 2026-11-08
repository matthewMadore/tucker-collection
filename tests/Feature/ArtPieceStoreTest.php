<?php

use App\Models\ArtPiece;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

test('storing an art piece uploads its images in order and flashes a success toast', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->create());

    $response = $this->from(route('dashboard'))->post('/art-pieces', [
        'name' => 'Charcoal Owl',
        'price' => '49.99',
        'description' => 'Study of a barn owl.',
        'images' => [
            UploadedFile::fake()->image('front.jpg'),
            UploadedFile::fake()->image('back.png'),
        ],
    ]);

    $response->assertRedirect(route('dashboard'));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'Art piece added.',
    ]);

    $piece = ArtPiece::with(['images' => fn ($query) => $query->orderBy('position')])->firstOrFail();
    expect($piece->price_cents)->toBe(4999);
    expect($piece->images->pluck('position')->all())->toBe([0, 1]);
    expect($piece->images[0]->path)->toStartWith('art-pieces/')->toEndWith('.jpg');
    expect($piece->images[1]->path)->toStartWith('art-pieces/')->toEndWith('.png');
    Storage::disk('public')->assertExists($piece->images->pluck('path')->all());
});

test('uploaded images are deleted when saving the art piece fails', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->create());
    DB::listen(function ($query) {
        if (str_starts_with($query->sql, 'insert into "art_piece_images"')) {
            throw new RuntimeException('Simulated database failure.');
        }
    });

    $this->post('/art-pieces', [
        'name' => 'Charcoal Owl',
        'price' => '49.99',
        'images' => [UploadedFile::fake()->image('front.jpg')],
    ])->assertServerError();

    expect(ArtPiece::count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('already stored images are deleted when a later image fails to store', function () {
    $disk = Storage::fake('public');
    $this->actingAs(User::factory()->create());

    $storeAttempts = 0;
    $failingDisk = Mockery::mock($disk)->makePartial();
    $failingDisk->shouldReceive('putFileAs')->andReturnUsing(
        function (...$arguments) use ($disk, &$storeAttempts) {
            return ++$storeAttempts === 2 ? false : $disk->putFileAs(...$arguments);
        },
    );
    Storage::set('public', $failingDisk);

    $this->post('/art-pieces', [
        'name' => 'Charcoal Owl',
        'price' => '49.99',
        'images' => [
            UploadedFile::fake()->image('front.jpg'),
            UploadedFile::fake()->image('back.jpg'),
        ],
    ])->assertServerError();

    expect($storeAttempts)->toBe(2);
    expect(ArtPiece::count())->toBe(0);
    expect($disk->allFiles())->toBe([]);
});

test('guests cannot store an art piece', function () {
    Storage::fake('public');

    $this->post('/art-pieces', [
        'name' => 'Charcoal Owl',
        'price' => '49.99',
        'images' => [UploadedFile::fake()->image('front.jpg')],
    ])->assertRedirect(route('login'));

    expect(ArtPiece::count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('users with an unverified email cannot store an art piece', function () {
    $this->actingAs(User::factory()->unverified()->create());

    $this->post('/art-pieces', ['name' => 'Charcoal Owl', 'price' => '49.99'])
        ->assertRedirect(route('verification.notice'));

    expect(ArtPiece::count())->toBe(0);
});

test('up to 19 images totalling up to 80 MB are accepted', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->create());

    $images = [
        ...array_fill(0, 7, UploadedFile::fake()->image('large.jpg')->size(10 * 1024)),
        ...array_fill(0, 12, UploadedFile::fake()->image('small.jpg')),
    ];

    $this->post('/art-pieces', ['name' => 'Charcoal Owl', 'price' => '49.99', 'images' => $images])
        ->assertSessionHasNoErrors();

    expect(ArtPiece::firstOrFail()->images()->count())->toBe(19);
});

test('images over the total size budget are rejected with a message naming the limit', function () {
    $this->actingAs(User::factory()->create());

    $this->from(route('dashboard'))
        ->post('/art-pieces', [
            'name' => 'Charcoal Owl',
            'price' => '49.99',
            'images' => array_fill(0, 9, UploadedFile::fake()->image('a.jpg')->size(10 * 1024)),
        ])
        ->assertSessionHasErrors(['images' => 'The images may not total more than 80 MB.']);
});

test('storing an art piece without a description saves an empty string', function () {
    $this->actingAs(User::factory()->create());

    $this->post('/art-pieces', ['name' => 'Untitled', 'price' => '10'])
        ->assertSessionHasNoErrors();

    expect(ArtPiece::firstOrFail()->description)->toBe('');
});

test('invalid art piece input is rejected with validation errors', function (array $input, string $errorField) {
    $this->actingAs(User::factory()->create());

    $valid = ['name' => 'Charcoal Owl', 'price' => '49.99'];

    $this->from(route('dashboard'))
        ->post('/art-pieces', array_merge($valid, $input))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors($errorField);

    expect(ArtPiece::count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'name too long' => [['name' => str_repeat('a', 256)], 'name'],
    'missing price' => [['price' => ''], 'price'],
    'non-numeric price' => [['price' => 'abc'], 'price'],
    'zero price' => [['price' => '0'], 'price'],
    'too many decimals' => [['price' => '1.999'], 'price'],
    'image that is not a file' => [['images' => ['https://example.com/a.jpg']], 'images.0'],
    'disallowed file type' => [['images' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]], 'images.0'],
    'svg image' => [['images' => [UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml')]], 'images.0'],
    'image over 10 MB' => [['images' => [UploadedFile::fake()->image('huge.jpg')->size(10 * 1024 + 1)]], 'images.0'],
    'more than 19 images' => [['images' => array_fill(0, 20, UploadedFile::fake()->image('a.jpg'))], 'images'],
]);
