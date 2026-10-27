<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('it creates a user with the entered password', function () {
    $this->artisan('user:create', ['name' => 'Dominik', 'email' => 'dominik@example.com'])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->assertSuccessful();

    $user = User::where('email', 'dominik@example.com')->sole();

    expect($user->name)->toBe('Dominik')
        ->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue();
});

test('it generates a password when run non-interactively', function () {
    $this->artisan('user:create', [
        'name' => 'Dominik',
        'email' => 'dominik@example.com',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Generated password')
        ->assertSuccessful();

    expect(User::where('email', 'dominik@example.com')->exists())->toBeTrue();
});

test('it rejects an email that is already taken', function () {
    User::factory()->create(['email' => 'dominik@example.com']);

    $this->artisan('user:create', ['name' => 'Dominik', 'email' => 'dominik@example.com'])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsOutputToContain('The email has already been taken.')
        ->assertFailed();

    expect(User::count())->toBe(1);
});
