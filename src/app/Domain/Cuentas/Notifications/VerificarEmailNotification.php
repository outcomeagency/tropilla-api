<?php

namespace App\Domain\Cuentas\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerificarEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = config('tropilla.web_url').'/verificar-email?token='.urlencode($this->token);

        return (new MailMessage)
            ->subject('Verificá tu email en TROPILLA')
            ->greeting('¡Hola!')
            ->line('Confirmá tu dirección de email para terminar de crear tu cuenta.')
            ->action('Verificar email', $url)
            ->line('El enlace vence en '.config('tropilla.auth.verificacion_email_horas').' horas. Si no creaste una cuenta, ignorá este mensaje.');
    }
}
