<?php

use App\Mcp\Tools\Finance\FinanceTool;
use App\Models\User;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('the API tokens page needs a confirmed password', function () {
    $this->actingAs($this->user)->get(route('api-tokens.index'))->assertRedirect(route('password.confirm'));

    $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('api-tokens.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/api-tokens')
            ->where('tokens', [])
            ->where('mcpUrl', url('/mcp/finance')));
});

test('a created token is flashed once in plain text and authenticates the MCP server with its abilities', function (string $access, array $abilities) {
    $this->actingAs($this->user)
        ->post(route('api-tokens.store'), ['name' => ' Claude Code ', 'access' => $access])
        ->assertRedirect(route('api-tokens.index'));

    $flashed = session(SessionKey::FLASH_DATA)['token'];
    $token = $this->user->tokens()->sole();

    expect($flashed['name'])->toBe('Claude Code')
        ->and($token->abilities)->toBe($abilities);

    $this->withSession(['auth.password_confirmed_at' => time()])->get(route('api-tokens.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tokens.0.name', 'Claude Code')
            ->where('tokens.0.access', $access)
            ->where('tokens.0.last_used_at_diff', null));

    $this->app['auth']->forgetGuards();
    $this->postJson('/mcp/finance', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'create_category', 'arguments' => ['name' => 'Pets']],
    ], ['Authorization' => "Bearer {$flashed['plain_text']}", 'MCP-Protocol-Version' => '2025-06-18'])
        ->assertOk()
        ->assertJsonPath('result.isError', $access === 'read');

    expect($this->user->categories()->where('name', 'Pets')->exists())->toBe($access === 'write')
        ->and($token->fresh()->last_used_at)->not->toBeNull();
})->with([
    'read only' => ['read', [FinanceTool::READ]],
    'read and write' => ['write', [FinanceTool::READ, FinanceTool::WRITE]],
]);

test('a token needs a name and a known access level', function () {
    $this->actingAs($this->user)
        ->post(route('api-tokens.store'), ['name' => '', 'access' => 'admin'])
        ->assertSessionHasErrors([
            'name' => 'Name the token after the agent that uses it, e.g. "Claude Code on laptop".',
            'access' => 'Choose read only or read and write.',
        ]);

    expect($this->user->tokens()->count())->toBe(0);
});

test('revoking deletes the token, and another user\'s token is not found', function () {
    $token = $this->user->createToken('agent', [FinanceTool::READ]);
    $other = User::factory()->create()->createToken('theirs', [FinanceTool::READ]);

    $this->actingAs($this->user)->delete(route('api-tokens.destroy', $other->accessToken->id))->assertNotFound();
    $this->actingAs($this->user)->delete(route('api-tokens.destroy', $token->accessToken->id))->assertRedirect(route('api-tokens.index'));

    expect($this->user->tokens()->count())->toBe(0)
        ->and($other->accessToken->fresh())->not->toBeNull();
});

test('deleting a user deletes their tokens', function () {
    $this->user->createToken('agent', [FinanceTool::READ]);

    $this->user->delete();

    expect(PersonalAccessToken::query()->count())->toBe(0);
});
