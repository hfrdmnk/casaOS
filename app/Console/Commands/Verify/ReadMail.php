<?php

namespace App\Console\Commands\Verify;

use App\Verification\Scenario;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Help;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\File;

#[Signature('verify:mail {scenario : Scenario id printed by verify:scenario} {--latest : Print only the newest message}')]
#[Description('Print the mail captured for a scenario\'s addresses as JSON')]
#[Help(<<<'HELP'
In local and testing environments every message sent to a scenario address (including plus
addresses like verify-<id>+new@casaos.test) is captured, whatever the mailer, into
storage/verify/mail/<id>/. This prints those messages oldest first: subject, from, to, text, html,
links found in the body, and attachment names.

  php artisan verify:mail ab12cd34
  php artisan verify:mail ab12cd34 --latest | jq -r '.messages[0].links[0]'

Queued mail is captured only after the queue drains: `php artisan queue:work --stop-when-empty`.
HELP)]
class ReadMail extends VerifyCommand
{
    public function verify(): int
    {
        $scenario = (string) $this->argument('scenario');
        $mailbox = Scenario::mailbox($scenario);

        $messages = collect(File::isDirectory($mailbox) ? File::files($mailbox) : [])
            ->filter(fn (\SplFileInfo $file): bool => $file->getExtension() === 'json')
            ->sortBy(fn (\SplFileInfo $file): string => $file->getFilename())
            ->map(fn (\SplFileInfo $file): mixed => json_decode((string) File::get($file->getPathname()), true))
            ->values();

        if ($this->option('latest')) {
            $messages = $messages->slice(-1)->values();
        }

        $this->json([
            'scenario' => $scenario,
            'mailbox' => $mailbox,
            'count' => $messages->count(),
            'messages' => $messages->all(),
        ]);

        return self::SUCCESS;
    }
}
