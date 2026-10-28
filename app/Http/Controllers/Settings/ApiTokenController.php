<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Mcp\Tools\Finance\FinanceTool;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Personal access tokens for the MCP servers. A token is read-only (`finance:read`) or read and
 * write (`finance:read` and `finance:write`). Its plain value is flashed once after creation and
 * never stored; revoking deletes it, so it gets 401 from then on.
 */
class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/api-tokens', [
            'tokens' => $user->tokens()->latest('id')->get()
                ->map(fn (PersonalAccessToken $token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'access' => $token->can(FinanceTool::WRITE) ? 'write' : 'read',
                    'created_at_diff' => $token->created_at?->diffForHumans(),
                    'last_used_at_diff' => $token->last_used_at?->diffForHumans(),
                ])->all(),
            'mcpUrl' => url('/mcp/finance'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'access' => ['required', 'in:read,write'],
        ], [
            'name.required' => 'Name the token after the agent that uses it, e.g. "Claude Code on laptop".',
            'access.in' => 'Choose read only or read and write.',
        ]);

        $abilities = $data['access'] === 'write' ? [FinanceTool::READ, FinanceTool::WRITE] : [FinanceTool::READ];
        $token = $user->createToken(trim($data['name']), $abilities);

        Inertia::flash('token', ['name' => $token->accessToken->name, 'plain_text' => $token->plainTextToken]);

        return to_route('api-tokens.index');
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->tokens()->whereKey($token)->firstOrFail()->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Token revoked.')]);

        return to_route('api-tokens.index');
    }
}
