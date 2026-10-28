<?php

namespace App\Console\Commands\Verify;

use App\Actions\Finance\Import\ImportUpload;
use App\Models\User;
use App\Verification\Scenario;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Help;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

#[Signature('verify:cleanup {scenario? : Scenario id to delete} {--stale : Delete every scenario user older than --hours, and orphaned mailboxes} {--hours=2 : Age threshold for --stale} {--dry-run : Print what would be deleted and delete nothing}')]
#[Description('Delete scenario users, their sessions, reset tokens, captured mail and uploads; never evidence')]
#[Help(<<<'HELP'
Deletes only users whose email is verify-<id>@casaos.test. Removes the user (passkeys and other
user-owned rows cascade), their sessions and password reset tokens, the captured mail under
storage/verify/mail/<id>/ and leftover finance uploads under
storage/app/private/finance-imports/<user_id>/. Evidence under storage/verify/runs/ is never touched.

  php artisan verify:cleanup ab12cd34
  php artisan verify:cleanup --stale --dry-run
  php artisan verify:cleanup --stale --hours=0      # every scenario user, e.g. after failed runs

Module tables must reference users with cascadeOnDelete() so their scenario rows go too.
HELP)]
class Cleanup extends VerifyCommand
{
    public function verify(): int
    {
        $scenario = $this->argument('scenario');

        if ($scenario === null && ! $this->option('stale')) {
            $this->components->error('Pass a scenario id, or --stale to sweep scenario users left by failed runs. Add --dry-run to preview.');

            return self::FAILURE;
        }

        if ($scenario !== null) {
            $user = $this->scenarioUser((string) $scenario);

            if ($user === null) {
                return self::FAILURE;
            }

            $users = collect([$user]);
        } else {
            $users = Scenario::users()
                ->where('created_at', '<=', now()->subHours((int) $this->option('hours')))
                ->get();
        }

        $scenarioIds = $users->map(fn (User $user): ?string => Scenario::idFromEmail($user->email));

        $mailboxes = $scenarioIds->map(fn (?string $id): string => Scenario::mailbox((string) $id))
            ->merge($this->option('stale') ? $this->orphanedMailboxes() : [])
            ->filter(fn (string $path): bool => File::isDirectory($path))
            ->unique()
            ->values();

        $deleted = $users->map(fn (User $user): array => [
            'scenario' => Scenario::idFromEmail($user->email),
            'email' => $user->email,
            'sessions' => DB::table('sessions')->where('user_id', $user->id)->count(),
        ]);

        $uploads = $users->map(fn (User $user): string => "finance-imports/{$user->id}")
            ->filter(fn (string $path): bool => Storage::disk('local')->directoryExists($path))
            ->values();

        if (! $this->option('dry-run')) {
            DB::transaction(function () use ($users): void {
                foreach ($users as $user) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                    DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                    $user->delete();
                }
            });

            $mailboxes->each(fn (string $path): bool => File::deleteDirectory($path));
            $users->each(fn (User $user): ?string => ImportUpload::deleteAllFor($user));
        }

        $this->json([
            'dry_run' => (bool) $this->option('dry-run'),
            'users' => $deleted->all(),
            'mailboxes' => $mailboxes->all(),
            'uploads' => $uploads->map(fn (string $path): string => Storage::disk('local')->path($path))->all(),
            'evidence_kept' => storage_path('verify/runs'),
        ]);

        return self::SUCCESS;
    }

    /**
     * Mailboxes whose scenario user no longer exists.
     *
     * @return Collection<int, string>
     */
    private function orphanedMailboxes(): Collection
    {
        $root = storage_path('verify/mail');

        return collect(File::isDirectory($root) ? File::directories($root) : [])
            ->reject(fn (string $path): bool => Scenario::user(basename($path)) !== null)
            ->values();
    }
}
