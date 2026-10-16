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
