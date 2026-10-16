<?php

namespace App\Domain\Comercial\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Aviso al dueño/admin de una consulta nueva (el detalle con datos del comprador se ve en el panel). */
class NuevaConsultaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $establecimientoId, public readonly string $consultaId, public readonly string $nombre, public readonly string $pais, public readonly string $idioma) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Nueva consulta de {$this->nombre} ({$this->pais})")
            ->line("Recibiste una consulta en TROPILLA (idioma del comprador: {$this->idioma}).")
            ->action('Ver y responder', config('tropilla.web_url')."/panel/consultas/{$this->consultaId}")
            ->line('Respondé rápido: los compradores valoran la primera respuesta.');
    }
}
