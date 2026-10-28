<?php

namespace App\Verification;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Copies every message sent to a scenario address into the scenario's mailbox as JSON,
 * where `verify:mail` reads it. Registered only in local and testing environments.
 */
class CaptureScenarioMail
{
    public function handle(MessageSent $event): void
    {
        $message = $event->message;

        $scenarioIds = collect($message->getTo())
            ->map(fn (Address $address): ?string => Scenario::idFromEmail($address->getAddress()))
            ->filter()
            ->unique();

        if ($scenarioIds->isEmpty()) {
            return;
        }

        $captured = $this->capture($message);

        foreach ($scenarioIds as $scenarioId) {
            $mailbox = Scenario::mailbox($scenarioId);

            File::ensureDirectoryExists($mailbox);
            File::put(
                $mailbox.'/'.now()->format('Ymd-His-u').'-'.Str::lower(Str::random(4)).'.json',
                (string) json_encode($captured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );
        }
    }

    /**
     * @return array{sent_at: string, subject: string|null, from: array<string>, to: array<string>, text: string|null, html: string|null, links: list<string>, attachments: array<string|null>}
     */
    private function capture(Email $message): array
    {
        $text = $this->body($message->getTextBody());
        $html = $this->body($message->getHtmlBody());

        preg_match_all('#https?://[^\s"\'<>()\[\]]+#', ($html ?? '').' '.($text ?? ''), $matches);

        return [
            'sent_at' => now()->toIso8601String(),
            'subject' => $message->getSubject(),
            'from' => array_map(fn (Address $address): string => $address->getAddress(), $message->getFrom()),
            'to' => array_map(fn (Address $address): string => $address->getAddress(), $message->getTo()),
            'text' => $text,
            'html' => $html,
            'links' => array_values(array_unique(array_map(fn (string $link): string => html_entity_decode($link), $matches[0]))),
            'attachments' => array_map(fn (DataPart $part): ?string => $part->getFilename(), $message->getAttachments()),
        ];
    }

    /**
     * @param  resource|string|null  $body
     */
    private function body(mixed $body): ?string
    {
        if (is_resource($body)) {
            return (string) stream_get_contents($body);
        }

        return is_string($body) ? $body : null;
    }
}
