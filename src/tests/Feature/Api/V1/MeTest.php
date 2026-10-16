<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('devuelve el usuario del token Sanctum', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]]);
});

it('rechaza sin token con 401 JSON', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('acepta un token Bearer real', function () {
    $token = User::factory()->create()->createToken('test')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/me')->assertOk();
});
