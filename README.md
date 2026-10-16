# tropilla-api

API REST de TROPILLA — plataforma integral del productor ganadero del NEA (bovinos + búfalos).
Laravel 13 · PHP 8.4 · Sanctum · Pest · PostgreSQL 16 + PostGIS · Redis 7 · MinIO · Gotenberg · Docker.

Se acopla con `tropilla-next` y `tropilla-flutter` **solo por HTTP** (`/api/v1`).
Convenciones de ramas, commits y código: [`CONTRIBUTING.md`](CONTRIBUTING.md).

## Levantar en local

```bash
docker compose up -d --build      # el entrypoint hace composer install, .env, key, migrate y seed
curl localhost:8090/api/v1/health # 200 si DB, PostGIS y Redis responden
docker compose exec app php artisan test
```

Artisan, Composer y Pest se corren **dentro del contenedor** (`docker compose exec app ...`):
el PHP del host no trae `pdo_pgsql`.

## Servicios y puertos (host)

| Servicio (contenedor) | Qué | Puerto host |
|---|---|---|
| `web` (`tropilla_web`) | nginx → API | **8090** |
| `app` (`tropilla_app`) | php-fpm 8.4 + entrypoint (migra y seedea) | — |
| `queue` (`tropilla_queue`) | `queue:work redis` | — |
| `scheduler` (`tropilla_scheduler`) | `schedule:work` | — |
| `postgres` (`tropilla_postgres`) | PostGIS 16 (`tropilla` / `tropilla_test`) | **5442** |
| `redis` (`tropilla_redis`) | Redis 7 (cache, colas, sesiones, locks) | **6389** |
| `minio` (`tropilla_minio`) | S3 API / consola (bucket `tropilla`, versionado) | **9010** / **9011** |
| `gotenberg` (`tropilla_gotenberg`) | HTML → PDF | **3010** |

Los puertos se cambian con `API_PORT`, `FORWARD_DB_PORT`, `FORWARD_REDIS_PORT`, `FORWARD_MINIO_PORT`,
`FORWARD_MINIO_CONSOLE_PORT`, `FORWARD_GOTENBERG_PORT` en un `.env` en la raíz del repo.
Si tu UID no es 1000: `HOST_UID` / `HOST_GID`.

Credenciales de dev: Postgres `tropilla` / `secret` · MinIO `tropilla` / `secretsecret` ·
usuario seed `dev@tropilla.local` / `password` (solo `APP_ENV=local`).

## Datos demo — «La Esperanza»

En `APP_ENV=local` el seeder carga (idempotente) el establecimiento demo del brief de diseño:
**Agropecuaria Don Ramón S.A.** · **La Esperanza** (General Paz, Corrientes, 2.400 ha), potreros Bañado Norte,
Loma del Monte y Malezal 3, **1.240 bovinos y 380 bubalinos** en lotes por categoría, la búfala Murrah **U-0142**
(preñada, parto probable 12/01/2027), la vaca Braford **1187**, 30 bubillos Mediterráneos en venta (468 kg promedio),
4 partos probables esta semana y 8 recategorizaciones sugeridas. Todo entra como **eventos reales** (hasheados y encadenados).

| Usuario | Contraseña | Rol |
|---|---|---|
| `demo@tropilla.ar` | `tropilla-demo` | Dueño de La Esperanza |

```bash
docker compose exec app php artisan db:seed --class=DemoLaEsperanzaSeeder   # cargarlo a mano
curl -s localhost:8090/api/v1/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"demo@tropilla.ar","password":"tropilla-demo","cliente":"web"}'
```

Contrato de la API: [`docs/openapi-v1.yaml`](docs/openapi-v1.yaml). Avance por ticket: [`docs/PROGRESS.md`](docs/PROGRESS.md).
