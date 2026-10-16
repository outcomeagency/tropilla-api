<?php

namespace App\Domain\Establecimientos\Enums;

/**
 * Rol de membresía y su matriz de abilities (modelo-datos.md §13.1).
 * Es la única fuente de la matriz: Policies, Resources (`abilities`) y `GET /auth/me` la leen de acá.
 */
enum Rol: string
{
    case Dueno = 'dueno';
    case Admin = 'admin';
    case Encargado = 'encargado';
    case Asesor = 'asesor';
    case Lectura = 'lectura';

    /** ability => roles que la tienen. */
    public const MATRIZ = [
        'establecimiento.ver' => ['dueno', 'admin', 'encargado', 'asesor', 'lectura'],
        'establecimiento.editar' => ['dueno', 'admin'],
        'establecimiento.ver_privados' => ['dueno', 'admin'],
        'establecimiento.archivar' => ['dueno'],
        'usuarios.ver' => ['dueno', 'admin', 'encargado', 'asesor', 'lectura'],
        'usuarios.gestionar' => ['dueno', 'admin'],
        'titularidad.transferir' => ['dueno'],
        'maestros.editar' => ['dueno', 'admin', 'encargado', 'asesor'],
        'hacienda.crear' => ['dueno', 'admin', 'encargado', 'asesor'],
        'hacienda.corregir' => ['dueno', 'admin', 'encargado', 'asesor'],
        'reproduccion.crear' => ['dueno', 'admin', 'encargado', 'asesor'],
        'reproduccion.corregir' => ['dueno', 'admin', 'encargado', 'asesor'],
        'sanidad.crear' => ['dueno', 'admin', 'encargado', 'asesor'],
        'sanidad.corregir' => ['dueno', 'admin', 'encargado', 'asesor'],
        'plan.editar' => ['dueno', 'admin', 'encargado', 'asesor'],
        'tareas.gestionar' => ['dueno', 'admin', 'encargado', 'asesor'],
        'establecimiento_eventos.crear' => ['dueno', 'admin', 'encargado', 'asesor'],
        'hacienda.ver' => ['dueno', 'admin', 'encargado', 'asesor', 'lectura'],
        'reproduccion.ver' => ['dueno', 'admin', 'encargado', 'asesor', 'lectura'],
        'sanidad.ver' => ['dueno', 'admin', 'encargado', 'asesor', 'lectura'],
        'importaciones.gestionar' => ['dueno', 'admin'],
        'documentos.ver' => ['dueno', 'admin', 'encargado', 'asesor', 'lectura'],
        'documentos.crear' => ['dueno', 'admin', 'encargado', 'asesor'],
        'documentos.versionar' => ['dueno', 'admin', 'encargado', 'asesor'], // E/As: solo propios (Policy)
        'documentos.fiscal' => ['dueno', 'admin'],
        'documentos.publicar' => ['dueno', 'admin'],
        'documentos.compartir' => ['dueno', 'admin'],
        'comercial.precios' => ['dueno', 'admin'],
        'ofertas.ver' => ['dueno', 'admin', 'asesor'],
        'ofertas.gestionar' => ['dueno', 'admin'],
        'ofertas.publicar' => ['dueno', 'admin'],
        'consultas.ver' => ['dueno', 'admin'],
        'consultas.gestionar' => ['dueno', 'admin'],
        'contratos.ver' => ['dueno', 'admin', 'asesor'],
        'contratos.gestionar' => ['dueno', 'admin'],
        'busco.ver_coincidencias' => ['dueno', 'admin'],
        'busco.responder' => ['dueno', 'admin'],
        'perfil.ver_preview' => ['dueno', 'admin', 'asesor'],
        'perfil.editar' => ['dueno', 'admin'],
        'perfil.publicar' => ['dueno', 'admin'],
        'organizaciones.adherir' => ['dueno', 'admin'],
        'consentimientos.gestionar' => ['dueno', 'admin'],
        'revisiones.resolver' => ['dueno', 'admin', 'encargado', 'asesor'], // E/As: solo no comerciales
        'auditoria.ver' => ['dueno', 'admin'],
        'exportar.todo' => ['dueno'],
        'sync.pull' => ['dueno', 'admin', 'encargado', 'asesor', 'lectura'],
        'sync.snapshot' => ['dueno', 'admin', 'encargado', 'asesor', 'lectura'],
        'sync.push' => ['dueno', 'admin', 'encargado', 'asesor'],
    ];

    /** @return list<string> */
    public function abilities(): array
    {
        return array_keys(array_filter(self::MATRIZ, fn (array $roles) => in_array($this->value, $roles, true)));
    }

    public function puede(string $ability): bool
    {
        return in_array($this->value, self::MATRIZ[$ability] ?? [], true);
    }

    /** Roles que se pueden asignar por invitación o cambio de rol (el dueño solo por transferencia). */
    public static function invitables(): array
    {
        return ['admin', 'encargado', 'asesor', 'lectura'];
    }
}
