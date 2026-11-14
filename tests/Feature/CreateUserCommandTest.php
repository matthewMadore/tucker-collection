<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('it creates a verified user who can log in', function () {
    $this->artisan('app:create-user')
        ->expectsQuestion('Name', 'Partner')
        ->expectsQuestion('Email', 'partner@example.com')
        ->expectsQuestion('Password', 'a-long-password')
        ->expectsOutput('Created partner@example.com. They can log in now.')
        ->assertSuccessful();

    $user = User::where('email', 'partner@example.com')->firstOrFail();
    expect($user->name)->toBe('Partner')
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('a-long-password', $user->password))->toBeTrue();

    $this->post(route('login.store'), ['email' => 'partner@example.com', 'password' => 'a-long-password']);
    $this->assertAuthenticatedAs($user);
});

test('it refuses an email that already has an account', function () {
    User::factory()->create(['email' => 'partner@example.com']);

    $this->artisan('app:create-user')
        ->expectsQuestion('Name', 'Partner')
        ->expectsQuestion('Email', 'partner@example.com')
        ->expectsQuestion('Password', 'a-long-password')
        ->expectsOutput('The email has already been taken.')
        ->assertFailed();

    expect(User::where('email', 'partner@example.com')->count())->toBe(1);
});
