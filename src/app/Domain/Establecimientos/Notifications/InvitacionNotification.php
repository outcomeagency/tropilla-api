<?php

namespace App\Domain\Establecimientos\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitacionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $url,
        public readonly string $establecimiento,
        public readonly string $invitadoPor,
        public readonly string $rol,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->invitadoPor} te invitó a «{$this->establecimiento}» en TROPILLA")
            ->line("{$this->invitadoPor} te invitó a sumarte al establecimiento «{$this->establecimiento}» con el rol {$this->rol}.")
            ->action('Ver invitación', $this->url)
            ->line('La invitación vence en 7 días.');
    }
}
