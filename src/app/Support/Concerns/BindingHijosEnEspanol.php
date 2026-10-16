<?php

namespace App\Support\Concerns;

use Doctrine\Inflector\InflectorFactory;
use Illuminate\Support\Str;

/**
 * Binding con scope ({establecimiento}/{invitacion}) en español: la relación hija se busca por el
 * plural español del parámetro (invitacion → invitaciones(), animal → animales()).
 * No se cambia el Pluralizer global para no romper tablas de paquetes (personal_access_tokens).
 */
trait BindingHijosEnEspanol
{
    protected function childRouteBindingRelationshipName($childType): string
    {
        static $inflector;
        $inflector ??= InflectorFactory::createForLanguage('spanish')->build();

        return Str::camel($inflector->pluralize($childType));
    }
}
