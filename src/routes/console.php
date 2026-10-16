<?php

use Illuminate\Support\Facades\Schedule;

/*
| Tareas programadas (contenedor `scheduler`). Horario de Argentina.
*/

// sync-e-integridad.md §5.4: verificación nocturna de las cadenas de integridad.
Schedule::command('integridad:verificar-cadenas')->dailyAt('02:30')->timezone('America/Argentina/Buenos_Aires')->withoutOverlapping();
