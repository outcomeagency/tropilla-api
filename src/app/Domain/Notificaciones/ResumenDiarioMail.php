<?php

namespace App\Domain\Notificaciones;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Resumen diario por email (TPA-53): las notificaciones de las últimas 24 h de los tipos con `resumen_diario` activado. */
class ResumenDiarioMail extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<array{titulo: string, cuerpo: string}> $items */
    public function __construct(public readonly array $items) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $m = (new MailMessage)->subject('TROPILLA — tu resumen del día ('.count($this->items).')')->line('Esto es lo que tenés pendiente:');
        foreach (array_slice($this->items, 0, 30) as $i) {
            $m->line("• {$i['titulo']} — {$i['cuerpo']}");
        }

        return $m->action('Abrir TROPILLA', config('tropilla.web_url'))->line('Podés cambiar qué avisos recibís por email en tu cuenta.');
    }
}
