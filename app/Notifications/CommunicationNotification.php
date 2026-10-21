<?php

namespace App\Notifications;

use App\Enums\CommunicationCategory;
use App\Models\Communication;
use App\Models\User;
use App\Notifications\Concerns\GuardsMarketingConsent;
use App\Notifications\Concerns\HasTransactionalFooter;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * An admin communication. Deliberately not ShouldQueue: the queued unit is
 * App\Jobs\SendCommunicationEmail (one per recipient, so each has its own
 * delivery row), which sends this with sendNow().
 *
 * The body is the admin's plain text: blank lines separate paragraphs,
 * {first_name} is replaced per person, and Markdown such as **bold** or
 * [a link](https://…) is rendered by the mail template.
 */
class CommunicationNotification extends Notification
{
    use GuardsMarketingConsent, HasTransactionalFooter;

    /**
     * @param  array<int, array{path: string, name: string, mime: string}>  $files  absolute paths
     */
    public function __construct(
        public string $subject,
        public string $body,
        public CommunicationCategory $messageCategory,
        public array $files = [],
        public ?string $firstName = null,
    ) {}

    public static function for(Communication $communication): self
    {
        $disk = Storage::disk('local');

        return new self(
            $communication->subject,
            $communication->body,
            $communication->category,
            $communication->attachments->map(fn ($a) => [
                'path' => $disk->path($a->path),
                'name' => $a->original_name,
                'mime' => $a->mime,
            ])->all(),
        );
    }

    public function category(): CommunicationCategory
    {
        return $this->messageCategory;
    }

    public function via($notifiable): array
    {
        // A marketing email to someone without consent is refused here too,
        // not only by the job, so no other caller can bypass it.
        if ($notifiable instanceof User && ! $this->consentAllows($notifiable)) {
            return [];
        }

        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $firstName = $this->firstName ?? ($notifiable instanceof User ? $notifiable->first_name : null);
        $name = filled($firstName) ? $firstName : 'there';

        $mail = (new MailMessage)
            ->subject($this->subject)
            ->greeting("Hi {$name},");

        foreach (self::paragraphs(str_replace('{first_name}', $name, $this->body)) as $paragraph) {
            $mail->line($paragraph);
        }

        foreach ($this->files as $file) {
            $mail->attach($file['path'], ['as' => $file['name'], 'mime' => $file['mime']]);
        }

        return $this->withFooter($mail);
    }

    /**
     * Blank-line-separated paragraphs; a single line break inside a
     * paragraph is kept as a Markdown hard break.
     *
     * @return array<int, string>
     */
    public static function paragraphs(string $body): array
    {
        $body = str_replace(["\r\n", "\r"], "\n", trim($body));

        return array_values(array_filter(array_map(
            fn ($p) => implode("  \n", array_map('trim', explode("\n", trim($p)))),
            preg_split('/\n\s*\n/', $body) ?: [],
        ), fn ($p) => $p !== ''));
    }
}
