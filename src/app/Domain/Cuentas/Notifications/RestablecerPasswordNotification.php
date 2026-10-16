<?php

namespace App\Domain\Cuentas\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RestablecerPasswordNotification extends Notification implements ShouldQueue
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
        $url = config('tropilla.web_url').'/restablecer?token='.urlencode($this->token);

        return (new MailMessage)
            ->subject('Restablecé tu contraseña de TROPILLA')
            ->line('Recibimos un pedido para restablecer la contraseña de tu cuenta.')
            ->action('Elegir una contraseña nueva', $url)
            ->line('El enlace vence en '.config('tropilla.auth.restablecer_password_minutos').' minutos. Al cambiarla se cierran todas tus sesiones.');
    }
}
