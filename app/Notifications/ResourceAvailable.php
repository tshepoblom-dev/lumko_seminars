<?php

namespace App\Notifications;

use App\Models\Resource;
use App\Notifications\Concerns\HasTransactionalFooter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResourceAvailable extends Notification implements ShouldQueue
{
    use HasTransactionalFooter, Queueable;

    public function __construct(public Resource $resource) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("New resource available: {$this->resource->title}")
            ->greeting("Hi {$notifiable->first_name},")
            ->line("A new resource is now available: \"{$this->resource->title}\".")
            ->action('View resources', route('resources.index'));

        return $this->withFooter($mail);
    }

    public function toArray($notifiable): array
    {
        return ['resource_id' => $this->resource->id, 'message' => "New resource available: \"{$this->resource->title}\"."];
    }
}
