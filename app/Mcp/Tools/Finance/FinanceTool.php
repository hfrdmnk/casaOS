<?php

namespace App\Mcp\Tools\Finance;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * A finance MCP tool. Every call runs as the token's user and needs a token ability: tools
 * annotated #[IsReadOnly] need `finance:read`, every other tool `finance:write`, so the annotation
 * an agent sees is the rule that is enforced.
 *
 * Tools look rows up with `ownedBy($user)->findOrFail()`: another user's id is "not found", the same
 * as an id that does not exist. Validation errors keep their field keys (e.g. `conditions.0.value`)
 * so the agent knows what to fix.
 */
abstract class FinanceTool extends Tool
{
    public const READ = 'finance:read';

    public const WRITE = 'finance:write';

    abstract protected function run(Request $request, User $user): Response|ResponseFactory;

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('Not authenticated. Connect with a casaOS API token as the bearer token.');
        }

        $ability = $this->isReadOnly() ? self::READ : self::WRITE;

        if (! $user->tokenCan($ability)) {
            return Response::error($ability === self::WRITE
                ? "Permission denied: {$this->name()} changes data and needs the finance:write ability, but this API token is read-only. Ask the user to create a \"Read and write\" token in casaOS under Settings → API tokens."
                : "Permission denied: {$this->name()} needs the finance:read ability, which this API token does not have.");
        }

        try {
            return $this->run($request, $user);
        } catch (ModelNotFoundException $exception) {
            $label = Str::lower(Str::headline(class_basename($exception->getModel())));

            return Response::error(sprintf('Not found: no %s with id %s.', $label, implode(', ', $exception->getIds())));
        } catch (ValidationException $exception) {
            return Response::error('Invalid input. '.collect($exception->errors())
                ->map(fn (array $messages, string $field): string => "{$field}: ".implode(' ', $messages))
                ->implode(' '));
        }
    }

    /**
     * "List Accounts" for `list_accounts`.
     */
    public function title(): string
    {
        return Str::headline($this->name());
    }

    public function isReadOnly(): bool
    {
        return ($this->annotations()['readOnlyHint'] ?? false) === true;
    }
}
