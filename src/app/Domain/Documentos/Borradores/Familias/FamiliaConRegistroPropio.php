<?php

namespace App\Domain\Documentos\Borradores\Familias;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Models\User;

/**
 * Familia cuya confirmación no se reduce a «eventos por categoría» (TPA-66): registra su propia entidad (protocolo,
 * resultado de faena…) por la Action de su dominio, dentro de la transacción de `ConfirmarBorrador`.
 */
interface FamiliaConRegistroPropio extends Familia
{
    /**
     * Registra lo confirmado. Lanza ApiException (422/409) si algo no cierra; todo se revierte.
     *
     * @param  list<array<string, mixed>>  $campos
     * @return list<string> ids de los eventos creados (para vincular el documento)
     */
    public function registrar(Establecimiento $est, User $usuario, object $borrador, array $campos, bool $confirmarAdvertencias): array;
}
