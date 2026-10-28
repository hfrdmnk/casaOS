<?php

namespace App\Console\Commands\Verify;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Help;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

#[Signature('verify:doctor')]
#[Description('Check that this environment is safe and ready for a verify-casaos run')]
#[Help(<<<'HELP'
Prints JSON with one entry per check and exits non-zero when any check fails. Each failing check
says how to fix it. It lists the env keys it read, never their values.

Checks: APP_ENV is local, the database answers and is migrated, a frontend build or Vite hot file
exists, the app answers GET <APP_URL>/up, every outbound mail path stays local (log/array mailer,
or SMTP to localhost), the queue connection is local (sync or database), and the session cookie
works over the APP_URL scheme.

  php artisan verify:doctor | jq '.checks[] | select(.ok == false)'

A failing check this session cannot fix (no running server, no frontend build) makes the run
`blocked`, not `fail`.
HELP)]
class Doctor extends VerifyCommand
{
    private const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1', 'mailpit'];

    public function verify(): int
    {
        $checks = [
            $this->check('environment', fn (): array => $this->laravel->environment('local')
                ? [true, 'APP_ENV is local']
                : [false, "APP_ENV is [{$this->laravel->environment()}]", 'Set APP_ENV=local in .env.']),
            $this->check('database', fn (): array => $this->database()),
            $this->check('frontend', fn (): array => is_file(public_path('hot')) || is_file(public_path('build/manifest.json'))
                ? [true, is_file(public_path('hot')) ? 'Vite dev server (public/hot)' : 'public/build/manifest.json present']
                : [false, 'No frontend build', 'Run `npm run build`, or start `npm run dev`.']),
            $this->check('app_url', fn (): array => $this->appAnswers()),
            $this->check('mail', fn (): array => $this->mail()),
            $this->check('queue', fn (): array => in_array(config('queue.default'), ['sync', 'database'], true)
                ? [true, 'Queue connection is '.config('queue.default').'; drain with `php artisan queue:work --stop-when-empty`']
                : [false, 'Queue connection is '.config('queue.default'), 'Set QUEUE_CONNECTION=database (or sync) in .env.']),
            $this->check('session_cookie', fn (): array => config('session.secure') && ! str_starts_with((string) config('app.url'), 'https://')
                ? [false, 'Secure session cookies over a non-HTTPS APP_URL; logins will not stick', 'Unset SESSION_SECURE_COOKIE or serve APP_URL over https.']
                : [true, 'Session cookie matches the APP_URL scheme']),
        ];

        $ok = collect($checks)->every(fn (array $check): bool => $check['ok']);

        $this->json([
            'outcome' => $ok ? 'ready' : 'not ready',
            'app_url' => config('app.url'),
            'checks' => $checks,
            'env_keys_read' => ['APP_ENV', 'APP_URL', 'DB_CONNECTION', 'MAIL_MAILER', 'MAIL_HOST', 'QUEUE_CONNECTION', 'SESSION_SECURE_COOKIE'],
        ]);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  callable(): array{0: bool, 1: string, 2?: string}  $probe
     * @return array{name: string, ok: bool, detail: string, fix: string|null}
     */
    private function check(string $name, callable $probe): array
    {
        try {
            [$ok, $detail, $fix] = $probe() + [2 => null];
        } catch (Throwable $exception) {
            [$ok, $detail, $fix] = [false, $exception->getMessage(), null];
        }

        return ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'fix' => $ok ? null : $fix];
    }

    /**
     * @return array{0: bool, 1: string, 2?: string}
     */
    private function database(): array
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            return [false, 'Cannot connect to the '.config('database.default').' database', 'Start the database (in an orb: `.agents/resume`) and check DB_* in .env.'];
        }

        return Schema::hasTable('users') && Schema::hasTable('sessions')
            ? [true, 'Connected to '.config('database.default').' and migrated']
            : [false, 'Database is not migrated', 'Run `php artisan migrate`.'];
    }

    /**
     * @return array{0: bool, 1: string, 2?: string}
     */
    private function appAnswers(): array
    {
        $url = rtrim((string) config('app.url'), '/').'/up';

        try {
            $status = Http::timeout(5)->get($url)->status();
        } catch (Throwable) {
            $status = null;
        }

        return $status === 200
            ? [true, "GET {$url} returned 200"]
            : [false, "GET {$url} ".($status === null ? 'did not answer' : "returned {$status}"), 'Start the app (in an orb: `amp orb service start casaos --command \'php artisan serve --host=127.0.0.1 --port=8000\'`) and make APP_URL match.'];
    }

    /**
     * @return array{0: bool, 1: string, 2?: string}
     */
    private function mail(): array
    {
        $default = (string) config('mail.default');
        $unsafe = collect($this->mailers($default))
            ->reject(fn (string $mailer): bool => $this->mailerIsLocal($mailer))
            ->values();

        return $unsafe->isEmpty()
            ? [true, "Mailer [{$default}] stays local; scenario mail is captured for `verify:mail`"]
            : [false, 'Mailer ['.$unsafe->implode(', ').'] can reach a real inbox', 'Set MAIL_MAILER=log (or SMTP to a local trap on 127.0.0.1) in .env.'];
    }

    /**
     * Every mailer the default mailer can send through, following failover and round-robin.
     *
     * @return list<string>
     */
    private function mailers(string $mailer): array
    {
        $transport = config("mail.mailers.{$mailer}.transport");

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            /** @var list<string> $children */
            $children = config("mail.mailers.{$mailer}.mailers", []);

            return array_merge(...array_map(fn (string $child): array => $this->mailers($child), $children));
        }

        return [$mailer];
    }

    private function mailerIsLocal(string $mailer): bool
    {
        return match (config("mail.mailers.{$mailer}.transport")) {
            'log', 'array' => true,
            'smtp' => in_array(config("mail.mailers.{$mailer}.host"), self::LOCAL_HOSTS, true),
            default => false,
        };
    }
}
