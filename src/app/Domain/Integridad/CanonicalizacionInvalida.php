<?php

namespace App\Domain\Integridad;

use InvalidArgumentException;

/** El sobre no es canonicalizable (float, clave inválida, entero fuera de rango, UTF-8 inválido). Código de push: `canonicalizacion`. */
class CanonicalizacionInvalida extends InvalidArgumentException {}
