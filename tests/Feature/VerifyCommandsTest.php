<?php

use App\Models\User;
use App\Verification\Scenario;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->app->useStoragePath(sys_get_temp_dir().'/casaos-verify-test-'.getmypid());
});

afterEach(function () {
    File::deleteDirectory(storage_path());
});

/**
 * @param  array<string, mixed>  $parameters
 * @return array<string, mixed>
 */
function verifyJson(string $command, array $parameters = []): array
{
    expect(Artisan::call($command, $parameters))->toBe(0);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

test('verify commands refuse to run outside local and testing', function (string $command, array $parameters) {
    $this->app->detectEnvironment(fn () => 'production');

    $this->artisan($command, $parameters)
        ->expectsOutputToContain('only run when APP_ENV is local or testing')
        ->assertFailed();

    expect(User::count())->toBe(0);
})->with([
    'doctor' => ['verify:doctor', []],
    'scenario' => ['verify:scenario', []],
    'login' => ['verify:login', ['scenario' => 'abcdefgh']],
    'inspect' => ['verify:inspect', ['scenario' => 'abcdefgh']],
    'mail' => ['verify:mail', ['scenario' => 'abcdefgh']],
    'cleanup' => ['verify:cleanup', ['--stale' => true]],
]);

test('a two-factor scenario is created and its state is readable by inspect', function () {
    $scenario = verifyJson('verify:scenario', ['states' => ['two-factor']]);

    $account = verifyJson('verify:inspect', ['scenario' => $scenario['scenario']])['account'];

    expect($scenario['email'])->toBe("verify-{$scenario['scenario']}@casaos.test")
        ->and($scenario['states']['two-factor']['recovery_codes'])->toHaveCount(8)
        ->and($account['email'])->toBe($scenario['email'])
        ->and($account['two_factor']['enabled'])->toBeTrue()
        ->and($account['two_factor']['current_code'])->toMatch('/^\d{6}$/');

    $this->post('/login', ['email' => $scenario['email'], 'password' => $scenario['password']])
        ->assertRedirect('/two-factor-challenge');
});

test('an unknown scenario state is rejected and creates no user', function () {
    $this->artisan('verify:scenario', ['states' => ['two-factor', 'missing']])
        ->expectsOutputToContain('Unknown state [missing]')
        ->assertFailed();

    expect(User::count())->toBe(0);
});

test('the login url logs in the scenario user and lands on the requested path', function () {
    $scenario = verifyJson('verify:scenario');
    $login = verifyJson('verify:login', ['scenario' => $scenario['scenario'], '--path' => '/settings/profile']);

    $this->get(parse_url($login['url'], PHP_URL_PATH).'?'.parse_url($login['url'], PHP_URL_QUERY))
        ->assertRedirect('/settings/profile');

    $this->assertAuthenticatedAs(User::findOrFail($scenario['user_id']));
});

test('the login url is refused when tampered with or pointing at a non-scenario user', function () {
    $scenario = verifyJson('verify:scenario');
    $login = verifyJson('verify:login', ['scenario' => $scenario['scenario']]);
    $owner = User::factory()->create(['email' => 'owner@example.com']);

    $this->get(str_replace('path=%2F', 'path=%2Fsettings', parse_url($login['url'], PHP_URL_PATH).'?'.parse_url($login['url'], PHP_URL_QUERY)))
        ->assertForbidden();

    $this->get(URL::temporarySignedRoute('verify.login', now()->addMinute(), ['user' => $owner->id], absolute: false))
        ->assertNotFound();

    $this->assertGuest();
});

test('mail sent to a scenario address is captured for verify:mail', function () {
    $scenario = verifyJson('verify:scenario');

    $this->post('/forgot-password', ['email' => $scenario['email']])->assertSessionHasNoErrors();

    $mail = verifyJson('verify:mail', ['scenario' => $scenario['scenario'], '--latest' => true]);

    expect($mail['count'])->toBe(1)
        ->and($mail['messages'][0]['to'])->toBe([$scenario['email']])
        ->and($mail['messages'][0]['subject'])->toBe('Reset your password')
        ->and(collect($mail['messages'][0]['links'])->filter(fn (string $link) => str_contains($link, '/reset-password/'))->values()->all())
        ->toHaveCount(1)
        ->each->toMatch('#^http://localhost(:\d+)?/reset-password/[0-9a-f]{64}\?email=verify-[a-z0-9]{8}%40casaos\.test$#');
});

test('cleanup removes the scenario user, sessions and mail but never other users', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com', 'created_at' => now()->subDay()]);
    $scenario = verifyJson('verify:scenario');
    DB::table('sessions')->insert(['id' => 'scenario-session', 'user_id' => $scenario['user_id'], 'payload' => '', 'last_activity' => time()]);
    File::ensureDirectoryExists(Scenario::mailbox($scenario['scenario']));

    $preview = verifyJson('verify:cleanup', ['--stale' => true, '--hours' => 0, '--dry-run' => true]);

    expect(User::count())->toBe(2)
        ->and(collect($preview['users'])->pluck('email')->all())->toBe([$scenario['email']]);

    verifyJson('verify:cleanup', ['--stale' => true, '--hours' => 0]);

    expect(User::pluck('email')->all())->toBe([$owner->email])
        ->and(DB::table('sessions')->where('user_id', $scenario['user_id'])->exists())->toBeFalse()
        ->and(File::isDirectory(Scenario::mailbox($scenario['scenario'])))->toBeFalse();
});

test('doctor fails when mail could reach a real inbox and does not print the mail host', function () {
    Http::fake([url('/up') => Http::response('ok')]);
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.postmarkapp.com',
    ]);

    $this->artisan('verify:doctor')
        ->expectsOutputToContain('Mailer [smtp] can reach a real inbox')
        ->doesntExpectOutputToContain('postmarkapp')
        ->assertFailed();
});

test('a finance-imported scenario is readable by inspect and cleanup removes its finance rows', function () {
    $scenario = verifyJson('verify:scenario', ['states' => ['finance-imported']]);

    $finance = verifyJson('verify:inspect', ['scenario' => $scenario['scenario'], '--only' => ['finance']])['finance'];

    expect($finance['accounts'][0])->toMatchArray(['name' => 'Verify Checking', 'balance_today' => '2934.60', 'transactions' => ['completed' => 3, 'pending' => 1]])
        ->and($finance['batches'][0])->toMatchArray(['created' => 4, 'undone' => false])
        ->and($finance['categories'])->toHaveCount(12);

    verifyJson('verify:cleanup', ['scenario' => $scenario['scenario']]);

    expect(DB::table('transactions')->count())->toBe(0)
        ->and(DB::table('accounts')->count())->toBe(0)
        ->and(DB::table('import_batches')->count())->toBe(0);
});

test('a finance-rules scenario is categorized by its rule and cleanup removes its uploads', function () {
    Storage::fake('local');
    $scenario = verifyJson('verify:scenario', ['states' => ['finance-rules']]);
    Storage::disk('local')->put("finance-imports/{$scenario['user_id']}/upload/file.csv", 'x');
    Storage::disk('local')->put('finance-imports/999999/upload/file.csv', 'x');

    $finance = verifyJson('verify:inspect', ['scenario' => $scenario['scenario'], '--only' => ['finance']])['finance'];

    expect($finance['transactions'])->toMatchArray(['uncategorized' => 3, 'category_source' => ['rule' => 1], 'payee_source' => ['rule' => 1], 'by_category' => ['Groceries' => 1]])
        ->and($finance['payees'])->toBe([['id' => $scenario['states']['finance-rules']['payee_id'], 'name' => 'Migros', 'transactions' => 1]])
        ->and($finance['rules'][0])->toMatchArray(['name' => 'Migros', 'enabled' => true])
        ->and(array_column($finance['rules'][0]['actions'], 'label'))->toBe(['Groceries', 'Migros']);

    $cleanup = verifyJson('verify:cleanup', ['scenario' => $scenario['scenario']]);

    expect($cleanup['uploads'])->toHaveCount(1)
        ->and(Storage::disk('local')->directoryExists("finance-imports/{$scenario['user_id']}"))->toBeFalse()
        ->and(Storage::disk('local')->directoryExists('finance-imports/999999'))->toBeTrue()
        ->and(DB::table('rules')->count())->toBe(0)
        ->and(DB::table('payees')->count())->toBe(0);
});
