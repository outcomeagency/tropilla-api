<?php

use App\Http\Controllers\Api\V1\Agregados\GobernanzaController;
use App\Http\Controllers\Api\V1\Agregados\TableroController;
use App\Http\Controllers\Api\V1\Archivos\ArchivoController;
use App\Http\Controllers\Api\V1\Archivos\FotoController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\DispositivoController;
use App\Http\Controllers\Api\V1\Busco\BuscoController;
use App\Http\Controllers\Api\V1\Busco\ProfesionalController;
use App\Http\Controllers\Api\V1\Campos\AvisoCampoController;
use App\Http\Controllers\Api\V1\Campos\MercadoCampoController;
use App\Http\Controllers\Api\V1\Campos\ModeracionCampoController;
use App\Http\Controllers\Api\V1\Catalogos\CatalogoController;
use App\Http\Controllers\Api\V1\Catalogos\ParametroController;
use App\Http\Controllers\Api\V1\Clima\ClimaController;
use App\Http\Controllers\Api\V1\Clima\EscenarioClimaticoController;
use App\Http\Controllers\Api\V1\Comercial\ConsultaController;
use App\Http\Controllers\Api\V1\Comercial\FaenaController;
use App\Http\Controllers\Api\V1\Comercial\OfertaController;
use App\Http\Controllers\Api\V1\Comercial\OperacionController;
use App\Http\Controllers\Api\V1\Comercial\PrecioController;
use App\Http\Controllers\Api\V1\Comercial\SimulacionController;
use App\Http\Controllers\Api\V1\Comprador\CompradorController;
use App\Http\Controllers\Api\V1\Contrapartes\ContraparteController;
use App\Http\Controllers\Api\V1\Contratos\ContratoController;
use App\Http\Controllers\Api\V1\Cuenta\NotificacionController;
use App\Http\Controllers\Api\V1\Cuenta\TelegramController;
use App\Http\Controllers\Api\V1\Documentos\BorradorController;
use App\Http\Controllers\Api\V1\Documentos\DocumentoController;
use App\Http\Controllers\Api\V1\Establecimientos\EstablecimientoController;
use App\Http\Controllers\Api\V1\Establecimientos\InvitacionController;
use App\Http\Controllers\Api\V1\Establecimientos\MembresiaController;
use App\Http\Controllers\Api\V1\Establecimientos\OnboardingController;
use App\Http\Controllers\Api\V1\Establecimientos\ProductorController;
use App\Http\Controllers\Api\V1\Fletes\ConciergeFletesController;
use App\Http\Controllers\Api\V1\Fletes\PedidoFleteController;
use App\Http\Controllers\Api\V1\Forraje\BriefForrajeController;
use App\Http\Controllers\Api\V1\Hacienda\AnimalController;
use App\Http\Controllers\Api\V1\Hacienda\CorreccionController;
use App\Http\Controllers\Api\V1\Hacienda\EventoController;
use App\Http\Controllers\Api\V1\Hacienda\ImportacionController;
use App\Http\Controllers\Api\V1\Hacienda\ImportacionIdentificacionController;
use App\Http\Controllers\Api\V1\Hacienda\LoteController;
use App\Http\Controllers\Api\V1\Hacienda\PotreroController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Organizaciones\OrganizacionController;
use App\Http\Controllers\Api\V1\Perfil\PerfilController;
use App\Http\Controllers\Api\V1\Proveedores\ProveedorController;
use App\Http\Controllers\Api\V1\Publico\ConsultaPublicaController;
use App\Http\Controllers\Api\V1\Publico\ContratoPublicoController;
use App\Http\Controllers\Api\V1\Publico\DirectorioController;
use App\Http\Controllers\Api\V1\Publico\EnlacePublicoController;
use App\Http\Controllers\Api\V1\Publico\OfertaPublicaController;
use App\Http\Controllers\Api\V1\Publico\PerfilPublicoController;
use App\Http\Controllers\Api\V1\Publico\VerificacionController;
use App\Http\Controllers\Api\V1\Reproduccion\ReproduccionController;
use App\Http\Controllers\Api\V1\Reproduccion\ReproductorExternoController;
use App\Http\Controllers\Api\V1\Sanidad\PlanSanitarioController;
use App\Http\Controllers\Api\V1\Sanidad\ProductoSanitarioController;
use App\Http\Controllers\Api\V1\Sanidad\SanidadController;
use App\Http\Controllers\Api\V1\Sanidad\SanidadOficialController;
use App\Http\Controllers\Api\V1\Sanidad\TareaController;
use App\Http\Controllers\Api\V1\Sistema\AuditoriaController;
use App\Http\Controllers\Api\V1\Sync\SyncController;
use App\Http\Controllers\Api\V1\Telegram\WebhookController;
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
        Route::get('directorio/proveedores', [ProveedorController::class, 'buscar'])->name('directorio.proveedores');
        Route::get('directorio/proveedores/rubros', [ProveedorController::class, 'rubros'])->name('directorio.proveedores.rubros');
        Route::get('proveedores/{slug}', [ProveedorController::class, 'publico'])->name('proveedores.show');
        Route::get('sitemap', [DirectorioController::class, 'sitemap'])->name('sitemap');
        Route::get('establecimientos/{slug}', [PerfilPublicoController::class, 'show'])->name('establecimientos.show');
        Route::get('establecimientos/{slug}/ofertas', [PerfilPublicoController::class, 'ofertas'])->name('establecimientos.ofertas');
        Route::get('establecimientos/{slug}/contratos', [PerfilPublicoController::class, 'contratos'])->name('establecimientos.contratos');
        Route::get('establecimientos/{slug}/{bloque}', [PerfilPublicoController::class, 'bloque'])->where('bloque', 'reproductores|composicion|indicadores|galeria')->name('establecimientos.bloque');
        Route::get('contratos/{codigo}', [ContratoPublicoController::class, 'show'])->where('codigo', '[0-9A-Za-z]{10}')->name('contratos.show');
        Route::get('buscos', [BuscoController::class, 'publicos'])->name('buscos.index');
        Route::get('profesionales/{slug}', [ProfesionalController::class, 'publico'])->where('slug', '[a-z0-9-]{3,60}')->name('profesionales.show');
        Route::get('enlaces/{token}', EnlacePublicoController::class)->where('token', '[A-Za-z0-9_-]{20,100}')->name('enlaces.show');
        Route::post('consultas', ConsultaPublicaController::class)->middleware('throttle:consultas')->name('consultas.store');
    });
    // Fotos (bugfix TPA-54): públicas por hash si algo publicado las referencia; privadas con URL firmada (la firma es la credencial).
    Route::get('public/fotos/{sha256}', [FotoController::class, 'publica'])->where('sha256', '[0-9a-fA-F]{64}')->middleware('throttle:fotos')->name('public.fotos.show');
    Route::get('fotos/{sha256}', [FotoController::class, 'firmada'])->where('sha256', '[0-9a-fA-F]{64}')->middleware('throttle:fotos')->name('fotos.firmada');
    // Subida diferida con almacén local (dev/tests): la URL firmada es la credencial (como una URL prefirmada de S3).
    Route::put('archivos/subidas/{subida}/partes/{numero}', [ArchivoController::class, 'parte'])->middleware('signed')->whereNumber('numero')->name('archivos.parte');
    Route::get('public/invitaciones/{token}', [InvitacionController::class, 'publica'])->middleware('throttle:publico')->name('public.invitaciones.show');
    // Bot de Telegram (TPA-77): sin token de usuario; lo autentica el secret_token de setWebhook.
    Route::post('telegram/webhook', WebhookController::class)->middleware('throttle:telegram')->name('telegram.webhook');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        // Comprador y toma de tokens (TPA-57)
        Route::get('me/comprador', [CompradorController::class, 'perfil'])->name('me.comprador');
        Route::put('me/comprador', [CompradorController::class, 'guardarPerfil'])->name('me.comprador.guardar');
        Route::post('contratos/{codigo}/tomas', [CompradorController::class, 'tomar'])->where('codigo', '[0-9A-Za-z]{10}')->name('contratos.tomar');
        Route::get('comprador/tomas', [CompradorController::class, 'tomas'])->name('comprador.tomas');
        Route::post('comprador/tomas/{toma}/pago-informado', [CompradorController::class, 'pagoInformado'])->name('comprador.tomas.pago');
        Route::post('comprador/tomas/{toma}/devolucion-confirmada', [CompradorController::class, 'devolucionConfirmada'])->name('comprador.tomas.devolucion');
        Route::post('comprador/tomas/{toma}/entrega/confirmar', [CompradorController::class, 'confirmarEntrega'])->name('comprador.tomas.entrega');
        Route::get('seguimientos', [CompradorController::class, 'seguimientos'])->name('seguimientos.index');
        Route::post('seguimientos', [CompradorController::class, 'seguir'])->name('seguimientos.store');
        Route::delete('seguimientos/{slug}', [CompradorController::class, 'dejar'])->where('slug', '[a-z0-9-]{3,60}')->name('seguimientos.destroy');

        // «Busco» y perfil de profesional (TPA-58)
        Route::get('comprador/buscos', [BuscoController::class, 'index'])->name('comprador.buscos.index');
        Route::post('comprador/buscos', [BuscoController::class, 'store'])->name('comprador.buscos.store');
        Route::patch('comprador/buscos/{busco}', [BuscoController::class, 'update'])->name('comprador.buscos.update');
        Route::post('comprador/buscos/{busco}/cerrar', [BuscoController::class, 'cerrar'])->name('comprador.buscos.cerrar');
        Route::get('comprador/buscos/{busco}/respuestas', [BuscoController::class, 'respuestas'])->name('comprador.buscos.respuestas');
        Route::get('me/profesional', [ProfesionalController::class, 'show'])->name('me.profesional');
        Route::put('me/profesional', [ProfesionalController::class, 'update'])->name('me.profesional.guardar');

        // Mesa del concierge de fletes (TPA-73, solo staff)
        Route::prefix('fletes/concierge')->name('fletes.concierge.')->group(function () {
            Route::get('resumen', [ConciergeFletesController::class, 'resumen'])->name('resumen');
            Route::get('pedidos', [ConciergeFletesController::class, 'pedidos'])->name('pedidos.index');
            Route::get('pedidos/{pedido}', [ConciergeFletesController::class, 'pedido'])->name('pedidos.show');
            Route::post('pedidos/{pedido}/tomar', [ConciergeFletesController::class, 'tomar'])->name('pedidos.tomar');
            Route::post('pedidos/{pedido}/propuestas', [ConciergeFletesController::class, 'proponer'])->name('pedidos.propuestas');
            Route::post('pedidos/{pedido}/resultado', [ConciergeFletesController::class, 'resultado'])->name('pedidos.resultado');
            Route::post('propuestas/{propuesta}/retirar', [ConciergeFletesController::class, 'retirar'])->name('propuestas.retirar');
            Route::get('transportistas', [ConciergeFletesController::class, 'transportistas'])->name('transportistas.index');
            Route::post('transportistas', [ConciergeFletesController::class, 'crearTransportista'])->name('transportistas.store');
            Route::get('transportistas/{transportista}', [ConciergeFletesController::class, 'transportista'])->name('transportistas.show');
            Route::patch('transportistas/{transportista}', [ConciergeFletesController::class, 'actualizarTransportista'])->name('transportistas.update');
            Route::put('transportistas/{transportista}/documentos/{item}', [ConciergeFletesController::class, 'documento'])->where('item', '[a-z_]+')->name('transportistas.documentos');
            Route::post('transportistas/{transportista}/disponibilidades', [ConciergeFletesController::class, 'crearDisponibilidad'])->name('transportistas.disponibilidades');
            Route::get('disponibilidades', [ConciergeFletesController::class, 'disponibilidades'])->name('disponibilidades.index');
            Route::post('disponibilidades/{disponibilidad}/cerrar', [ConciergeFletesController::class, 'cerrarDisponibilidad'])->name('disponibilidades.cerrar');
        });
        // Perfil de proveedor, pedido de presupuesto y reseña con operación registrada (TPA-82).
        Route::get('me/proveedor', [ProveedorController::class, 'perfil'])->name('me.proveedor');
        Route::put('me/proveedor', [ProveedorController::class, 'guardarPerfil'])->name('me.proveedor.guardar');
        Route::get('proveedor/leads', [ProveedorController::class, 'leads'])->name('proveedor.leads');
        Route::post('proveedores/{slug}/leads', [ProveedorController::class, 'solicitar'])->name('proveedores.leads');
        Route::post('proveedores/{slug}/operaciones/{operacion}/resenas', [ProveedorController::class, 'resenar'])->name('proveedores.resenas');

        // Mercado de campos y pastajes (TPA-74)
        Route::get('campos/condiciones', [MercadoCampoController::class, 'condiciones'])->name('campos.condiciones');
        Route::get('campos', [MercadoCampoController::class, 'index'])->name('campos.index');
        Route::get('campos/{aviso}', [MercadoCampoController::class, 'show'])->name('campos.show');
        Route::post('campos/{aviso}/consultas', [MercadoCampoController::class, 'consultar'])->middleware('throttle:campos')->name('campos.consultar');
        Route::post('campos/{aviso}/disputas', [MercadoCampoController::class, 'reportar'])->middleware('throttle:campos')->name('campos.reportar');
        Route::get('staff/campos/disputas', [ModeracionCampoController::class, 'disputas'])->name('staff.campos.disputas');
        Route::post('staff/campos/disputas/{disputa}/resolver', [ModeracionCampoController::class, 'resolver'])->name('staff.campos.disputas.resolver');
        Route::put('staff/campos/avisos/{aviso}/verificacion', [ModeracionCampoController::class, 'verificar'])->name('staff.campos.verificar');

        // Organizaciones y adhesión con consentimiento (TPA-51)
        Route::get('organizaciones', [OrganizacionController::class, 'index'])->name('organizaciones.index');
        Route::post('organizaciones', [OrganizacionController::class, 'store'])->name('organizaciones.store');
        Route::get('organizaciones/{organizacion}', [OrganizacionController::class, 'show'])->name('organizaciones.show');
        Route::post('organizaciones/{organizacion}/miembros', [OrganizacionController::class, 'miembro'])->name('organizaciones.miembros');
        Route::get('organizaciones/{organizacion}/miembros', [OrganizacionController::class, 'miembros'])->name('organizaciones.miembros.index');
        Route::patch('organizaciones/{organizacion}/miembros/{usuario}', [OrganizacionController::class, 'cambiarRol'])->name('organizaciones.miembros.update');
        Route::delete('organizaciones/{organizacion}/miembros/{usuario}', [OrganizacionController::class, 'quitarMiembro'])->name('organizaciones.miembros.destroy');
        Route::get('organizaciones/{organizacion}/adheridos', [OrganizacionController::class, 'adheridos'])->name('organizaciones.adheridos');
        Route::get('productores/{productor}/adhesiones', [OrganizacionController::class, 'adhesiones'])->name('productores.adhesiones');
        Route::post('productores/{productor}/adhesiones', [OrganizacionController::class, 'adherir'])->name('productores.adhesiones.store');
        Route::post('productores/{productor}/adhesiones/{adhesion}/consentimiento', [OrganizacionController::class, 'consentimiento'])->name('productores.adhesiones.consentimiento');
        Route::post('productores/{productor}/adhesiones/{adhesion}/revocar', [OrganizacionController::class, 'revocar'])->name('productores.adhesiones.revocar');

        // Tablero agregado con gobernanza (TPA-75): solo datos sintéticos hasta que el gate habilite el opt-in real.
        Route::get('agregados/contexto', [TableroController::class, 'contexto'])->name('agregados.contexto');
        Route::get('agregados/tablero', [TableroController::class, 'tablero'])->name('agregados.tablero');
        Route::get('agregados/tablero/exportar', [TableroController::class, 'exportar'])->name('agregados.exportar');
        Route::get('agregados/consultas', [TableroController::class, 'consultas'])->name('agregados.consultas');
        Route::get('agregados/gobernanza', [GobernanzaController::class, 'show'])->name('agregados.gobernanza');
        Route::post('agregados/politicas', [GobernanzaController::class, 'politica'])->name('agregados.politicas.store');
        Route::get('agregados/pruebas', [GobernanzaController::class, 'pruebas'])->name('agregados.pruebas.index');
        Route::post('agregados/pruebas', [GobernanzaController::class, 'ejecutarPrueba'])->name('agregados.pruebas.store');
        Route::get('agregados/pruebas/{prueba}', [GobernanzaController::class, 'prueba'])->name('agregados.pruebas.show');
        Route::post('agregados/revisiones', [GobernanzaController::class, 'revisar'])->name('agregados.revisiones.store');
        Route::get('agregados/clientes', [GobernanzaController::class, 'clientes'])->name('agregados.clientes.index');
        Route::post('agregados/clientes', [GobernanzaController::class, 'crearCliente'])->name('agregados.clientes.store');
        Route::patch('agregados/clientes/{cliente}', [GobernanzaController::class, 'actualizarCliente'])->name('agregados.clientes.update');
        Route::get('agregados/clientes/{cliente}/miembros', [GobernanzaController::class, 'miembros'])->name('agregados.clientes.miembros.index');
        Route::post('agregados/clientes/{cliente}/miembros', [GobernanzaController::class, 'agregarMiembro'])->name('agregados.clientes.miembros.store');
        Route::delete('agregados/clientes/{cliente}/miembros/{usuario}', [GobernanzaController::class, 'quitarMiembro'])->name('agregados.clientes.miembros.destroy');

        // Notificaciones (TPA-53)
        Route::get('me/notificaciones', [NotificacionController::class, 'index'])->name('me.notificaciones');
        Route::post('me/notificaciones/{notificacion}/leer', [NotificacionController::class, 'leer'])->name('me.notificaciones.leer');
        Route::get('me/preferencias-notificacion', [NotificacionController::class, 'preferencias'])->name('me.preferencias');
        Route::put('me/preferencias-notificacion', [NotificacionController::class, 'guardarPreferencias'])->name('me.preferencias.guardar');

        // Bot de Telegram (TPA-77): vínculo del usuario
        Route::get('me/telegram', [TelegramController::class, 'show'])->name('me.telegram');
        Route::post('me/telegram/codigo', [TelegramController::class, 'codigo'])->middleware('throttle:telegram-codigo')->name('me.telegram.codigo');
        Route::patch('me/telegram', [TelegramController::class, 'update'])->name('me.telegram.update');
        Route::delete('me/telegram', [TelegramController::class, 'destroy'])->name('me.telegram.destroy');

        // Establecimientos (TPA-10, 12, 13)
        Route::post('onboarding', OnboardingController::class)->name('onboarding');
        Route::get('productores/{productor}', [ProductorController::class, 'show'])->name('productores.show');
        Route::patch('productores/{productor}', [ProductorController::class, 'update'])->name('productores.update');
        Route::get('establecimientos', [EstablecimientoController::class, 'index'])->name('establecimientos.index');
        Route::post('establecimientos', [EstablecimientoController::class, 'store'])->name('establecimientos.store');
        Route::get('establecimientos/{establecimiento}', [EstablecimientoController::class, 'show'])->name('establecimientos.show');
        Route::patch('establecimientos/{establecimiento}', [EstablecimientoController::class, 'update'])->name('establecimientos.update');
        Route::get('establecimientos/{establecimiento}/inicio', [EstablecimientoController::class, 'inicio'])->name('establecimientos.inicio');

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
            Route::get('reproductores-externos', [ReproductorExternoController::class, 'index'])->name('reproductores-externos.index');
            Route::post('reproductores-externos', [ReproductorExternoController::class, 'store'])->name('reproductores-externos.store');
            Route::patch('reproductores-externos/{reproductor}', [ReproductorExternoController::class, 'update'])->name('reproductores-externos.update');
            Route::delete('reproductores-externos/{reproductor}', [ReproductorExternoController::class, 'destroy'])->name('reproductores-externos.destroy');
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
            Route::post('animales/sugerencias/descartar', [AnimalController::class, 'descartarSugerencias'])->name('animales.sugerencias.descartar');
            Route::get('animales/identificador', [AnimalController::class, 'identificador'])->name('animales.identificador');
            Route::get('animales/{animal}', [AnimalController::class, 'show'])->name('animales.show');
            Route::get('animales/{animal}/eventos', [AnimalController::class, 'eventos'])->name('animales.eventos');
            Route::get('animales/{animal}/pesajes', [AnimalController::class, 'pesajes'])->name('animales.pesajes');
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
            // Pruebas, certificados y estatus de brucelosis/TBC (TPA-66)
            Route::get('sanidad/oficial', [SanidadOficialController::class, 'resumen'])->name('sanidad.oficial');
            Route::get('sanidad/oficial/resumen-veterinario', [SanidadOficialController::class, 'exportar'])->name('sanidad.oficial.exportar');
            Route::get('sanidad/protocolos', [SanidadOficialController::class, 'protocolos'])->name('sanidad.protocolos.index');
            Route::post('sanidad/protocolos', [SanidadOficialController::class, 'crearProtocolo'])->name('sanidad.protocolos.store');
            Route::get('sanidad/protocolos/{protocolo}', [SanidadOficialController::class, 'protocolo'])->name('sanidad.protocolos.show');
            Route::post('sanidad/protocolos/{protocolo}/anular', [SanidadOficialController::class, 'anularProtocolo'])->name('sanidad.protocolos.anular');
            Route::post('sanidad/estatus', [SanidadOficialController::class, 'crearEstatus'])->name('sanidad.estatus.store');
            Route::post('sanidad/estatus/{estatus}/anular', [SanidadOficialController::class, 'anularEstatus'])->name('sanidad.estatus.anular');
            Route::post('sanidad/saneamiento', [SanidadOficialController::class, 'crearPlan'])->name('sanidad.saneamiento.store');
            Route::patch('sanidad/saneamiento/{plan}', [SanidadOficialController::class, 'actualizarPlan'])->name('sanidad.saneamiento.update');
            Route::get('animales/{animal}/pruebas', [SanidadOficialController::class, 'pruebasAnimal'])->name('animales.pruebas');
            Route::get('sanidad/libro-tratamientos', [SanidadController::class, 'libroTratamientos'])->name('sanidad.libro-tratamientos');
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

            // Importación de identificaciones electrónicas y planilla de caravanas (TPA-63)
            Route::get('identificaciones/importaciones', [ImportacionIdentificacionController::class, 'index'])->name('identificaciones.importaciones.index');
            Route::get('identificaciones/importaciones/{importacion}', [ImportacionIdentificacionController::class, 'show'])->name('identificaciones.importaciones.show');
            Route::put('identificaciones/importaciones/{importacion}', [ImportacionIdentificacionController::class, 'update'])->name('identificaciones.importaciones.update');

            // Borradores de documento desde foto (TPA-61/62)
            Route::get('borradores', [BorradorController::class, 'index'])->name('borradores.index');
            Route::post('borradores', [BorradorController::class, 'store'])->name('borradores.store');
            Route::get('borradores/{borrador}', [BorradorController::class, 'show'])->name('borradores.show');
            Route::patch('borradores/{borrador}/campos', [BorradorController::class, 'campos'])->name('borradores.campos');
            Route::post('borradores/{borrador}/confirmar', [BorradorController::class, 'confirmar'])->name('borradores.confirmar');
            Route::post('borradores/{borrador}/reextraer', [BorradorController::class, 'reextraer'])->name('borradores.reextraer');
            Route::post('borradores/{borrador}/descartar', [BorradorController::class, 'descartar'])->name('borradores.descartar');

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
            // Resultado de faena y conciliación de venta (TPA-67)
            Route::get('faena/resultados', [FaenaController::class, 'index'])->name('faena.index');
            Route::post('faena/resultados', [FaenaController::class, 'store'])->name('faena.store');
            Route::post('faena/planilla', [FaenaController::class, 'leerPlanilla'])->name('faena.planilla');
            Route::get('faena/resultados/{resultado}', [FaenaController::class, 'show'])->name('faena.show');
            Route::patch('faena/resultados/{resultado}', [FaenaController::class, 'update'])->name('faena.update');
            Route::post('faena/resultados/{resultado}/anular', [FaenaController::class, 'anular'])->name('faena.anular');
            Route::get('faena/resultados/{resultado}/exportar', [FaenaController::class, 'exportar'])->name('faena.exportar');

            // Historial de precios propio (TPA-47)
            Route::get('precios/historial', [PrecioController::class, 'historial'])->name('precios.historial');
            Route::get('precios-referencia', [PrecioController::class, 'referencias'])->name('precios-referencia.index');
            Route::post('precios-referencia', [PrecioController::class, 'crearReferencia'])->name('precios-referencia.store');

            // Contratos a futuro con token (TPA-56)
            Route::post('contratos/calcular', [ContratoController::class, 'calcular'])->name('contratos.calcular');
            Route::get('contratos', [ContratoController::class, 'index'])->name('contratos.index');
            Route::post('contratos', [ContratoController::class, 'store'])->name('contratos.store');
            Route::get('contratos/{contrato}', [ContratoController::class, 'show'])->name('contratos.show');
            Route::get('contratos/{contrato}/preview', [ContratoController::class, 'preview'])->name('contratos.preview');
            Route::post('contratos/{contrato}/publicar', [ContratoController::class, 'publicar'])->name('contratos.publicar');
            Route::post('contratos/{contrato}/terminos', [ContratoController::class, 'terminos'])->name('contratos.terminos');
            Route::post('contratos/{contrato}/cancelar', [ContratoController::class, 'cancelar'])->name('contratos.cancelar');
            Route::post('contratos/{contrato}/incumplido', [ContratoController::class, 'incumplido'])->name('contratos.incumplido');
            Route::post('contratos/{contrato}/tomas/{toma}/confirmar-pago', [ContratoController::class, 'confirmarPago'])->name('contratos.tomas.confirmar');
            Route::post('contratos/{contrato}/tomas/{toma}/devolucion-informada', [ContratoController::class, 'devolucionInformada'])->name('contratos.tomas.devolucion');
            Route::post('contratos/{contrato}/entrega', [ContratoController::class, 'entrega'])->name('contratos.entrega');
            Route::post('contratos/{contrato}/entrega/confirmar', [ContratoController::class, 'confirmarEntrega'])->name('contratos.entrega.confirmar');
            Route::get('apalancamiento', [ContratoController::class, 'apalancamiento'])->name('apalancamiento');

            // Mercado de campos (TPA-74): avisos propios, consultas y descargo
            Route::get('campos/avisos', [AvisoCampoController::class, 'index'])->name('campos.avisos.index');
            Route::post('campos/avisos', [AvisoCampoController::class, 'store'])->name('campos.avisos.store');
            Route::get('campos/avisos/{aviso}', [AvisoCampoController::class, 'show'])->name('campos.avisos.show');
            Route::patch('campos/avisos/{aviso}', [AvisoCampoController::class, 'update'])->name('campos.avisos.update');
            Route::post('campos/avisos/{aviso}/{accion}', [AvisoCampoController::class, 'estado'])->whereIn('accion', ['publicar', 'pausar', 'cerrar'])->name('campos.avisos.estado');
            Route::post('campos/avisos/{aviso}/disputas/{disputa}/descargo', [AvisoCampoController::class, 'descargo'])->name('campos.avisos.descargo');
            Route::get('campos/consultas/{lado}', [AvisoCampoController::class, 'consultas'])->whereIn('lado', ['recibidas', 'enviadas'])->name('campos.consultas.index');
            Route::patch('campos/consultas/{lado}/{consulta}', [AvisoCampoController::class, 'avanzar'])->whereIn('lado', ['recibidas', 'enviadas'])->name('campos.consultas.update');

            // Demandas «Busco» y profesionales vinculados (TPA-58)
            Route::get('demandas', [BuscoController::class, 'demandas'])->name('demandas.index');
            Route::post('demandas/{busco}/respuestas', [BuscoController::class, 'responder'])->name('demandas.responder');
            Route::post('profesionales', [ProfesionalController::class, 'autorizar'])->name('profesionales.autorizar');

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

            // Alertas climáticas (TPA-70)
            Route::get('clima', [ClimaController::class, 'show'])->name('clima.show');
            Route::get('clima/escenarios', [EscenarioClimaticoController::class, 'show'])->name('clima.escenarios');
            Route::get('clima/reglas', [ClimaController::class, 'reglas'])->name('clima.reglas');
            Route::put('clima/reglas/{codigo}', [ClimaController::class, 'actualizarRegla'])->where('codigo', '[a-z0-9_]+')->name('clima.reglas.update');

            // Matching de camiones jaula: pedidos del productor (TPA-73)
            Route::get('fletes/opciones', [PedidoFleteController::class, 'opciones'])->name('fletes.opciones');
            Route::get('fletes/pedidos', [PedidoFleteController::class, 'index'])->name('fletes.pedidos.index');
            Route::post('fletes/pedidos', [PedidoFleteController::class, 'store'])->name('fletes.pedidos.store');
            Route::get('fletes/pedidos/{pedido}', [PedidoFleteController::class, 'show'])->name('fletes.pedidos.show');
            Route::post('fletes/pedidos/{pedido}/cancelar', [PedidoFleteController::class, 'cancelar'])->name('fletes.pedidos.cancelar');
            Route::post('fletes/pedidos/{pedido}/resultado', [PedidoFleteController::class, 'resultado'])->name('fletes.pedidos.resultado');
            Route::put('fletes/pedidos/{pedido}/checklist/{item}', [PedidoFleteController::class, 'checklist'])->where('item', '[a-z_]+')->name('fletes.pedidos.checklist');
            Route::post('fletes/pedidos/{pedido}/propuestas/{propuesta}/aceptar', [PedidoFleteController::class, 'aceptar'])->name('fletes.propuestas.aceptar');
            Route::post('fletes/pedidos/{pedido}/propuestas/{propuesta}/rechazar', [PedidoFleteController::class, 'rechazar'])->name('fletes.propuestas.rechazar');

            // Chequeo de contraparte por CUIT (TPA-72)
            Route::get('contrapartes/fuentes', [ContraparteController::class, 'fuentes'])->name('contrapartes.fuentes');
            Route::get('contrapartes/cheques/bancos', [ContraparteController::class, 'bancos'])->name('contrapartes.cheques.bancos');
            Route::post('contrapartes/cheques/verificar', [ContraparteController::class, 'verificarCheque'])->name('contrapartes.cheques.verificar');
            Route::get('contrapartes/consultas', [ContraparteController::class, 'index'])->name('contrapartes.consultas.index');
            Route::post('contrapartes/consultas', [ContraparteController::class, 'store'])->name('contrapartes.consultas.store');
            Route::get('contrapartes/consultas/{consulta}', [ContraparteController::class, 'show'])->name('contrapartes.consultas.show');
            Route::put('contrapartes/consultas/{consulta}/checklist/{item}', [ContraparteController::class, 'checklist'])->where('item', '[a-z_]+')->name('contrapartes.consultas.checklist');

            // Brief preventivo de forraje y carga (TPA-71)
            Route::get('forraje/insumos', [BriefForrajeController::class, 'insumos'])->name('forraje.insumos');
            Route::post('forraje/briefs/calcular', [BriefForrajeController::class, 'calcular'])->name('forraje.briefs.calcular');
            Route::get('forraje/briefs', [BriefForrajeController::class, 'index'])->name('forraje.briefs.index');
            Route::post('forraje/briefs', [BriefForrajeController::class, 'store'])->name('forraje.briefs.store');
            Route::get('forraje/briefs/{brief}', [BriefForrajeController::class, 'show'])->name('forraje.briefs.show');
            Route::post('forraje/briefs/{brief}/revisiones', [BriefForrajeController::class, 'revisar'])->name('forraje.briefs.revisiones');

            // Potreros (TPA-14)
            Route::get('potreros', [PotreroController::class, 'index'])->name('potreros.index');
            Route::post('potreros', [PotreroController::class, 'store'])->name('potreros.store');
            Route::get('potreros/{potrero}', [PotreroController::class, 'show'])->name('potreros.show');
            Route::patch('potreros/{potrero}', [PotreroController::class, 'update'])->name('potreros.update');
            Route::post('potreros/{potrero}/archivar', [PotreroController::class, 'archivar'])->name('potreros.archivar');
        });
    });
});
