<?php

namespace App\Console\Commands\Verify;

use App\Mcp\Tools\Finance\FinanceTool;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Help;
use Illuminate\Console\Attributes\Signature;

#[Signature('verify:mcp-token {scenario : Scenario id printed by verify:scenario} {--read-only : Only the finance:read ability (default: finance:read and finance:write)} {--name=verify : Token name shown in Settings → API tokens} {--base= : Base URL to prefix the MCP URL with (defaults to APP_URL)}')]
#[Description('Mint an API token for a scenario user and print it with the MCP server URL')]
#[Help(<<<'HELP'
Creates a Sanctum personal access token for the scenario user, as Settings → API tokens does, and
prints JSON with the plain `token` (shown only here), its `abilities`, and the finance MCP `url`.
The token is listed on the user's API tokens page and can be revoked there.

  php artisan verify:mcp-token ab12cd34
  php artisan verify:mcp-token ab12cd34 --read-only
  TOKEN=$(php artisan verify:mcp-token ab12cd34 | jq -r .token)
  curl -s -X POST http://localhost:8000/mcp/finance -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
    -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'

The token goes when the scenario user is deleted by verify:cleanup.
HELP)]
class McpToken extends VerifyCommand
{
    public function verify(): int
    {
        $user = $this->scenarioUser((string) $this->argument('scenario'));

        if ($user === null) {
            return self::FAILURE;
        }

        $abilities = $this->option('read-only') ? [FinanceTool::READ] : [FinanceTool::READ, FinanceTool::WRITE];
        $token = $user->createToken((string) $this->option('name'), $abilities);

        $this->json([
            'scenario' => $this->argument('scenario'),
            'user_id' => $user->id,
            'token_id' => $token->accessToken->getKey(),
            'name' => $token->accessToken->name,
            'abilities' => $abilities,
            'token' => $token->plainTextToken,
            'url' => rtrim((string) ($this->option('base') ?: config('app.url')), '/').'/mcp/finance',
        ]);

        return self::SUCCESS;
    }
}
