<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Catálogos globales (idempotentes) cargados una vez por corrida con RefreshDatabase. */
    protected $seed = true;
}
