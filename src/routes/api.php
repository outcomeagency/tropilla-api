<?php

use App\Http\Controllers\Api\V1\Archivos\ArchivoController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\DispositivoController;
use App\Http\Controllers\Api\V1\Catalogos\CatalogoController;
use App\Http\Controllers\Api\V1\Catalogos\ParametroController;
use App\Http\Controllers\Api\V1\Comercial\ConsultaController;
use App\Http\Controllers\Api\V1\Comercial\OfertaController;
use App\Http\Controllers\Api\V1\Comercial\OperacionController;
use App\Http\Controllers\Api\V1\Comercial\PrecioController;
use App\Http\Controllers\Api\V1\Comercial\SimulacionController;
use App\Http\Controllers\Api\V1\Documentos\DocumentoController;
use App\Http\Controllers\Api\V1\Establecimientos\EstablecimientoController;
use App\Http\Controllers\Api\V1\Establecimientos\InvitacionController;
use App\Http\Controllers\Api\V1\Establecimientos\MembresiaController;
use App\Http\Controllers\Api\V1\Establecimientos\OnboardingController;
use App\Http\Controllers\Api\V1\Establecimientos\ProductorController;
use App\Http\Controllers\Api\V1\Hacienda\AnimalController;
use App\Http\Controllers\Api\V1\Hacienda\CorreccionController;
use App\Http\Controllers\Api\V1\Hacienda\EventoController;
use App\Http\Controllers\Api\V1\Hacienda\ImportacionController;
use App\Http\Controllers\Api\V1\Hacienda\LoteController;
use App\Http\Controllers\Api\V1\Hacienda\PotreroController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Perfil\PerfilController;
use App\Http\Controllers\Api\V1\Publico\ConsultaPublicaController;
use App\Http\Controllers\Api\V1\Publico\DirectorioController;
use App\Http\Controllers\Api\V1\Publico\EnlacePublicoController;
use App\Http\Controllers\Api\V1\Publico\OfertaPublicaController;
use App\Http\Controllers\Api\V1\Publico\PerfilPublicoController;
use App\Http\Controllers\Api\V1\Publico\VerificacionController;
use App\Http\Controllers\Api\V1\Reproduccion\ReproduccionController;
use App\Http\Controllers\Api\V1\Sanidad\PlanSanitarioController;
use App\Http\Controllers\Api\V1\Sanidad\ProductoSanitarioController;
use App\Http\Controllers\Api\V1\Sanidad\SanidadController;
use App\Http\Controllers\Api\V1\Sanidad\TareaController;
use App\Http\Controllers\Api\V1\Sistema\AuditoriaController;
use App\Http\Controllers\Api\V1\Sync\SyncController;
use Illuminate\Support\Facades\Route;

/*
| API v1 — único contrato entre tropilla-api, tropilla-next y tropilla-flutter.
| Contrato formal: docs/openapi-v1.yaml. Cada dominio agrega su grupo acá.
*/

Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('health', HealthController::class)->name('health');

    // Auth (TPA-9)
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::post('register', [AuthController::class, 'register'])->name('register');
            Route::post('login', [AuthController::class, 'login'])->name('login');
            Route::post('email/verificar', [AuthController::class, 'verificarEmail'])->name('email.verificar');
            Route::post('password/olvide', [AuthController::class, 'olvide'])->name('password.olvide');
            Route::post('password/restablecer', [AuthController::class, 'restablecer'])->name('password.restablecer');
        });

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::patch('me', [AuthController::class, 'actualizarMe'])->name('me.actualizar');
            Route::post('email/reenviar', [AuthController::class, 'reenviarVerificacion'])->middleware('throttle:auth')->name('email.reenviar');
            Route::put('dispositivos/{dispositivo}', [DispositivoController::class, 'update'])->name('dispositivos.update');
            Route::post('dispositivos/{dispositivo}/revocar', [DispositivoController::class, 'revocar'])->name('dispositivos.revocar');
        });
    });

    // Público (sin token)
    Route::get('catalogos', CatalogoController::class)->middleware('throttle:publico')->name('catalogos');
    Route::middleware('throttle:publico')->prefix('public')->name('public.')->group(function () {
        Route::get('ofertas/{codigo}', [OfertaPublicaController::class, 'show'])->name('ofertas.show');
        Route::get('ofertas/{codigo}/pdf', [OfertaPublicaController::class, 'pdf'])->name('ofertas.pdf');
        Route::get('verificaciones/archivo/{sha256}', [VerificacionController::class, 'archivo'])->name('verificaciones.archivo');
        Route::post('verificaciones/archivo', [VerificacionController::class, 'subirArchivo'])->name('verificaciones.archivo.subir');
        Route::get('verificaciones/{huella}', [VerificacionController::class, 'show'])->name('verificaciones.show');
        Route::get('verificaciones/{huella}/prueba', [VerificacionController::class, 'prueba'])->name('verificaciones.prueba');
        Route::get('anclajes/{anclaje}/recibo', [VerificacionController::class, 'recibo'])->whereUuid('anclaje')->name('anclajes.recibo');
        Route::get('directorio', [DirectorioController::class, 'index'])->name('directorio');
        Route::get('directorio/facetas', [DirectorioController::class, 'facetas'])->name('directorio.facetas');
        Route::get('sitemap', [DirectorioController::class, 'sitemap'])->name('sitemap');
        Route::get('establecimientos/{slug}', [PerfilPublicoController::class, 'show'])->name('establecimientos.show');
        Route::get('establecimientos/{slug}/ofertas', [PerfilPublicoController::class, 'ofertas'])->name('establecimientos.ofertas');
        Route::get('establecimientos/{slug}/contratos', [PerfilPublicoController::class, 'contratos'])->name('establecimientos.contratos');
        Route::get('enlaces/{token}', EnlacePublicoController::class)->where('token', '[A-Za-z0-9_-]{20,100}')->name('enlaces.show');
        Route::post('consultas', ConsultaPublicaController::class)->middleware('throttle:consultas')->name('consultas.store');
    });
    // Subida diferida con almacén local (dev/tests): la URL firmada es la credencial (como una URL prefirmada de S3).
    Route::put('archivos/subidas/{subida}/partes/{numero}', [ArchivoController::class, 'parte'])->middleware('signed')->whereNumber('numero')->name('archivos.parte');
    Route::get('public/invitaciones/{token}', [InvitacionController::class, 'publica'])->middleware('throttle:publico')->name('public.invitaciones.show');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        // Establecimientos (TPA-10, 12, 13)
        Route::post('onboarding', OnboardingController::class)->name('onboarding');
        Route::get('productores/{productor}', [ProductorController::class, 'show'])->name('productores.show');
        Route::patch('productores/{productor}', [ProductorController::class, 'update'])->name('productores.update');
        Route::get('establecimientos', [EstablecimientoController::class, 'index'])->name('establecimientos.index');
        Route::post('establecimientos', [EstablecimientoController::class, 'store'])->name('establecimientos.store');
        Route::get('establecimientos/{establecimiento}', [EstablecimientoController::class, 'show'])->name('establecimientos.show');
        Route::patch('establecimientos/{establecimiento}', [EstablecimientoController::class, 'update'])->name('establecimientos.update');

        Route::get('sync/catalogos', CatalogoController::class)->name('sync.catalogos');
        // Archivos (TPA-44)
        Route::post('archivos/subidas', [ArchivoController::class, 'iniciar'])->name('archivos.subidas.store');
        Route::post('archivos/subidas/{subida}/completar', [ArchivoController::class, 'completar'])->name('archivos.subidas.completar');
        Route::get('archivos/{sha256}', [ArchivoController::class, 'descargar'])->where('sha256', '[0-9a-fA-F]{64}')->name('archivos.show');
        Route::get('archivos/{sha256}/estado', [ArchivoController::class, 'estado'])->where('sha256', '[0-9a-fA-F]{64}')->name('archivos.estado');
        Route::get('catalogos/productos-sugeridos', [ProductoSanitarioController::class, 'sugeridos'])->name('catalogos.productos-sugeridos');
        Route::get('importaciones/plantilla', [ImportacionController::class, 'plantilla'])->name('importaciones.plantilla');
        // Sync offline (TPA-43)
        Route::post('sync/push', [SyncController::class, 'push'])->middleware('throttle:sync-push')->name('sync.push');
        Route::get('sync/pull', [SyncController::class, 'pull'])->name('sync.pull');
        Route::get('sync/snapshot', [SyncController::class, 'snapshot'])->name('sync.snapshot');

        // Usuarios y roles (TPA-11)
        Route::post('invitaciones/{token}/aceptar', [InvitacionController::class, 'aceptar'])->name('invitaciones.aceptar');
        Route::prefix('establecimientos/{establecimiento}')->name('establecimientos.')->scopeBindings()->group(function () {
            Route::get('membresias', [MembresiaController::class, 'index'])->name('membresias.index');
            Route::post('membresias/{membresia}/rol', [MembresiaController::class, 'rol'])->name('membresias.rol');
            Route::post('membresias/{membresia}/revocar', [MembresiaController::class, 'revocar'])->name('membresias.revocar');
            Route::post('titularidad', [MembresiaController::class, 'titularidad'])->name('titularidad');
            Route::get('invitaciones', [InvitacionController::class, 'index'])->name('invitaciones.index');
            Route::post('invitaciones', [InvitacionController::class, 'store'])->name('invitaciones.store');
            Route::post('invitaciones/{invitacion}/revocar', [InvitacionController::class, 'revocar'])->name('invitaciones.revocar');
            Route::get('auditoria', AuditoriaController::class)->name('auditoria');

            // Parámetros y catálogo propio (TPA-15)
            Route::get('parametros', [ParametroController::class, 'index'])->name('parametros.index');
            Route::put('parametros/{clave}', [ParametroController::class, 'update'])->where('clave', '[a-z0-9_]+')->name('parametros.update');
            Route::post('catalogo-propio', [ParametroController::class, 'catalogoPropio'])->name('catalogo-propio');

            // Animales (TPA-16..19)
            Route::get('animales', [AnimalController::class, 'index'])->name('animales.index');
            Route::post('animales', [AnimalController::class, 'store'])->name('animales.store');
            Route::post('animales/recategorizar', [AnimalController::class, 'recategorizar'])->name('animales.recategorizar');
            Route::get('animales/identificador', [AnimalController::class, 'identificador'])->name('animales.identificador');
            Route::get('animales/{animal}', [AnimalController::class, 'show'])->name('animales.show');
            Route::get('animales/{animal}/eventos', [AnimalController::class, 'eventos'])->name('animales.eventos');
            Route::get('animales/{animal}/genealogia', [AnimalController::class, 'genealogia'])->name('animales.genealogia');
            Route::get('animales/{animal}/documentos', [AnimalController::class, 'documentos'])->name('animales.documentos');

            // Reproducción (TPA-30)
            Route::get('reproduccion/tablero', [ReproduccionController::class, 'tablero'])->name('reproduccion.tablero');
            Route::get('reproduccion/partos', [ReproduccionController::class, 'partos'])->name('reproduccion.partos');

            // Sanidad (TPA-31..35)
            Route::get('productos-sanitarios', [ProductoSanitarioController::class, 'index'])->name('productos-sanitarios.index');
            Route::post('productos-sanitarios', [ProductoSanitarioController::class, 'store'])->name('productos-sanitarios.store');
            Route::patch('productos-sanitarios/{producto}', [ProductoSanitarioController::class, 'update'])->name('productos-sanitarios.update');
            Route::post('productos-sanitarios/{producto}/archivar', [ProductoSanitarioController::class, 'archivar'])->name('productos-sanitarios.archivar');
            Route::get('plan-sanitario', [PlanSanitarioController::class, 'show'])->name('plan-sanitario.show');
            Route::post('plan-sanitario/plantillas', [PlanSanitarioController::class, 'plantillas'])->name('plan-sanitario.plantillas');
            Route::post('plan-sanitario/items', [PlanSanitarioController::class, 'storeItem'])->name('plan-sanitario.items.store');
            Route::patch('plan-sanitario/items/{item}', [PlanSanitarioController::class, 'updateItem'])->name('plan-sanitario.items.update');
            Route::post('plan-sanitario/items/{item}/archivar', [PlanSanitarioController::class, 'archivarItem'])->name('plan-sanitario.items.archivar');
            Route::get('sanidad/retiros', [SanidadController::class, 'retiros'])->name('sanidad.retiros');
            Route::get('sanidad/reactores', [SanidadController::class, 'reactores'])->name('sanidad.reactores');
            Route::get('tareas', [TareaController::class, 'index'])->name('tareas.index');
            Route::post('tareas', [TareaController::class, 'store'])->name('tareas.store');
            Route::patch('tareas/{tarea}', [TareaController::class, 'update'])->name('tareas.update');
            Route::post('tareas/{tarea}/omitir', [TareaController::class, 'omitir'])->name('tareas.omitir');

            // Documentos (TPA-36)
            Route::get('documentos', [DocumentoController::class, 'index'])->name('documentos.index');
            Route::post('documentos', [DocumentoController::class, 'store'])->name('documentos.store');
            Route::get('documentos/vencimientos', [DocumentoController::class, 'vencimientos'])->name('documentos.vencimientos');
            Route::get('documentos/{documento}', [DocumentoController::class, 'show'])->name('documentos.show');
            Route::post('documentos/{documento}/versiones', [DocumentoController::class, 'versiones'])->name('documentos.versiones');
            Route::post('documentos/{documento}/visibilidad', [DocumentoController::class, 'visibilidad'])->name('documentos.visibilidad');
            Route::get('documentos/{documento}/enlaces', [DocumentoController::class, 'enlaces'])->name('documentos.enlaces.index');
            Route::post('documentos/{documento}/enlaces', [DocumentoController::class, 'crearEnlace'])->name('documentos.enlaces.store');
            Route::post('documentos/{documento}/enlaces/{enlace}/revocar', [DocumentoController::class, 'revocarEnlace'])->name('documentos.enlaces.revocar');

            // Eventos (TPA-22, 26)
            Route::get('eventos', [EventoController::class, 'index'])->name('eventos.index');
            Route::post('eventos', [EventoController::class, 'store'])->name('eventos.store');
            Route::get('eventos/{evento}', [CorreccionController::class, 'show'])->name('eventos.show');
            Route::get('eventos/{evento}/integridad', [CorreccionController::class, 'integridad'])->name('eventos.integridad');
            Route::post('eventos/{evento}/correcciones', [CorreccionController::class, 'correccion'])->name('eventos.correcciones');
            Route::post('eventos/{evento}/anulaciones', [CorreccionController::class, 'anulacion'])->name('eventos.anulaciones');
            Route::get('revisiones', [CorreccionController::class, 'revisiones'])->name('revisiones.index');
            Route::get('revisiones/{revision}', [CorreccionController::class, 'revision'])->name('revisiones.show');
            Route::post('revisiones/{revision}/resolver', [CorreccionController::class, 'resolver'])->name('revisiones.resolver');

            // Importación de stock (TPA-21)
            Route::post('importaciones', [ImportacionController::class, 'store'])->name('importaciones.store');
            Route::get('importaciones/{importacion}', [ImportacionController::class, 'show'])->name('importaciones.show');
            Route::get('importaciones/{importacion}/filas', [ImportacionController::class, 'filas'])->name('importaciones.filas');
            Route::patch('importaciones/{importacion}/filas/{numero}', [ImportacionController::class, 'corregirFila'])->whereNumber('numero')->name('importaciones.filas.update');
            Route::post('importaciones/{importacion}/confirmar', [ImportacionController::class, 'confirmar'])->name('importaciones.confirmar');
            Route::post('importaciones/{importacion}/cancelar', [ImportacionController::class, 'cancelar'])->name('importaciones.cancelar');

            // Ofertas (TPA-48)
            Route::get('ofertas', [OfertaController::class, 'index'])->name('ofertas.index');
            Route::post('ofertas', [OfertaController::class, 'store'])->name('ofertas.store');
            Route::post('ofertas/desde-lote', [OfertaController::class, 'desdeLote'])->name('ofertas.desde-lote');
            Route::get('ofertas/{oferta}', [OfertaController::class, 'show'])->name('ofertas.show');
            Route::patch('ofertas/{oferta}', [OfertaController::class, 'update'])->name('ofertas.update');
            Route::get('ofertas/{oferta}/avisos', [OfertaController::class, 'avisos'])->name('ofertas.avisos');
            Route::get('ofertas/{oferta}/preview', [OfertaController::class, 'preview'])->name('ofertas.preview');
            Route::post('ofertas/{oferta}/publicar', [OfertaController::class, 'publicar'])->name('ofertas.publicar');
            Route::post('ofertas/{oferta}/estado', [OfertaController::class, 'estado'])->name('ofertas.estado');

            // Simulador en pie vs al gancho (TPA-45)
            Route::post('simulaciones/calcular', [SimulacionController::class, 'calcular'])->name('simulaciones.calcular');
            Route::get('simulaciones', [SimulacionController::class, 'index'])->name('simulaciones.index');
            Route::post('simulaciones', [SimulacionController::class, 'store'])->name('simulaciones.store');

            // Operaciones con trato (TPA-46)
            Route::get('operaciones', [OperacionController::class, 'index'])->name('operaciones.index');
            Route::post('operaciones', [OperacionController::class, 'store'])->name('operaciones.store');
            Route::get('operaciones/{operacion}', [OperacionController::class, 'show'])->name('operaciones.show');
            Route::post('operaciones/{operacion}/correcciones', [OperacionController::class, 'correccion'])->name('operaciones.correcciones');
            Route::post('operaciones/{operacion}/anulaciones', [OperacionController::class, 'anulacion'])->name('operaciones.anulaciones');

            // Historial de precios propio (TPA-47)
            Route::get('precios/historial', [PrecioController::class, 'historial'])->name('precios.historial');
            Route::get('precios-referencia', [PrecioController::class, 'referencias'])->name('precios-referencia.index');
            Route::post('precios-referencia', [PrecioController::class, 'crearReferencia'])->name('precios-referencia.store');

            // Consultas de compradores (TPA-49)
            Route::get('consultas', [ConsultaController::class, 'index'])->name('consultas.index');
            Route::get('consultas/{consulta}', [ConsultaController::class, 'show'])->name('consultas.show');
            Route::post('consultas/{consulta}/estado', [ConsultaController::class, 'estado'])->name('consultas.estado');

            // Perfil público (TPA-50)
            Route::get('perfil', [PerfilController::class, 'show'])->name('perfil.show');
            Route::patch('perfil', [PerfilController::class, 'update'])->name('perfil.update');
            Route::get('perfil/slug-disponible', [PerfilController::class, 'slugDisponible'])->name('perfil.slug');
            Route::get('perfil/preview', [PerfilController::class, 'preview'])->name('perfil.preview');
            Route::post('perfil/preview/aceptar', [PerfilController::class, 'aceptarPreview'])->name('perfil.preview.aceptar');
            Route::post('perfil/publicar', [PerfilController::class, 'publicar'])->name('perfil.publicar');
            Route::post('perfil/despublicar', [PerfilController::class, 'despublicar'])->name('perfil.despublicar');
            Route::post('perfil/secciones/{seccion}/congelar', [PerfilController::class, 'congelar'])->name('perfil.congelar');
            Route::post('perfil/secciones/{seccion}/descongelar', [PerfilController::class, 'descongelar'])->name('perfil.descongelar');

            // Lotes (TPA-20)
            Route::get('lotes', [LoteController::class, 'index'])->name('lotes.index');
            Route::post('lotes', [LoteController::class, 'store'])->name('lotes.store');
            Route::get('lotes/{lote}', [LoteController::class, 'show'])->name('lotes.show');
            Route::patch('lotes/{lote}', [LoteController::class, 'update'])->name('lotes.update');
            Route::post('lotes/{lote}/archivar', [LoteController::class, 'archivar'])->name('lotes.archivar');
            Route::get('lotes/{lote}/eventos', [LoteController::class, 'eventos'])->name('lotes.eventos');

            // Potreros (TPA-14)
            Route::get('potreros', [PotreroController::class, 'index'])->name('potreros.index');
            Route::post('potreros', [PotreroController::class, 'store'])->name('potreros.store');
            Route::get('potreros/{potrero}', [PotreroController::class, 'show'])->name('potreros.show');
            Route::patch('potreros/{potrero}', [PotreroController::class, 'update'])->name('potreros.update');
            Route::post('potreros/{potrero}/archivar', [PotreroController::class, 'archivar'])->name('potreros.archivar');
        });
    });
});
