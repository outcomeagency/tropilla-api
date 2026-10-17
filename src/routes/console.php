<?php

use Illuminate\Support\Facades\Schedule;

/*
| Tareas programadas (contenedor `scheduler`). Horario de Argentina.
*/

// sync-e-integridad.md §5.4: verificación nocturna de las cadenas de integridad.
Schedule::command('integridad:verificar-cadenas')->dailyAt('02:30')->timezone('America/Argentina/Buenos_Aires')->withoutOverlapping();

// modelo-datos.md §6.4: la edad cambia sola → reevaluar sugerencias de recategorización.
Schedule::command('hacienda:sugerir-categorias')->dailyAt('04:00')->timezone('America/Argentina/Buenos_Aires')->withoutOverlapping();

// Plan sanitario (TPA-33): tareas con horizonte de 90 días.
Schedule::command('sanidad:generar-tareas')->dailyAt('04:30')->timezone('America/Argentina/Buenos_Aires')->withoutOverlapping();

// Subida diferida (TPA-44): limpiar subidas vencidas.
Schedule::command('archivos:expirar-subidas')->hourly()->withoutOverlapping();

// Anclaje de integridad (TPA-40): diario 03:00 ART + actualización cada 6 h.
Schedule::command('integridad:anclar')->dailyAt('03:00')->timezone('America/Argentina/Buenos_Aires')->withoutOverlapping();
Schedule::command('integridad:actualizar-anclajes')->everySixHours()->withoutOverlapping();

// Alertas y resumen diario (TPA-53).
Schedule::command('alertas:generar')->dailyAt('07:00')->timezone('America/Argentina/Buenos_Aires')->withoutOverlapping();

// Contratos a futuro (TPA-57): liberar reservas vencidas.
Schedule::command('contratos:liberar-tomas')->everyFifteenMinutes()->withoutOverlapping();

// «Busco» (TPA-58): vencimientos y coincidencias.
Schedule::command('busco:actualizar')->dailyAt('06:00')->timezone('America/Argentina/Buenos_Aires')->withoutOverlapping();

// Alertas climáticas (TPA-70): pronóstico y ENSO con cache; recálculo cada 6 h.
Schedule::command('clima:actualizar')->everySixHours()->withoutOverlapping();

// Bot de Telegram (TPA-77): vencer propuestas sin confirmar y podar updates/códigos viejos.
Schedule::command('telegram:limpiar')->hourly()->withoutOverlapping();

// TPA-72: depura el detalle de las consultas de contraparte vencida la retención (queda el registro de quién consultó y para qué).
Schedule::command('contrapartes:depurar')->dailyAt('05:00')->timezone('America/Argentina/Buenos_Aires')->withoutOverlapping();
