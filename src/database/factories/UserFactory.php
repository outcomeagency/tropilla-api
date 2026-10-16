<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'nombre' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verificado_en' => now(),
            'idioma' => 'es',
            'password' => static::$password ??= Hash::make('password'),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verificado_en' => null]);
    }

    public function conTelefono(?string $telefono = null): static
    {
        return $this->state(fn () => [
            'email' => null,
            'email_verificado_en' => null,
            'telefono' => $telefono ?? '+54937940'.fake()->unique()->numerify('#####'),
        ]);
    }
}
