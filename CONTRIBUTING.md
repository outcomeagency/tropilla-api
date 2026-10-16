# Convenciones de desarrollo — tropilla-api

Repos de TROPILLA: **`tropilla-api`** (este, Laravel 13 en `src/`), **`tropilla-next`** (web) y
**`tropilla-flutter`** (app de campo offline). Mismas convenciones de tickets, ramas y commits en los tres.
La fuente de verdad funcional y técnica es el brain (`tropilla/brain`).

## Tickets

- Nomenclatura **`TPA-N`**. Tipos: **feature** / **bugfix**.
- Viven en Notion («TROPILLA — Tickets (TPA)») y en `brain/tickets/TICKETS.md`.
- Cada ticket: historia de usuario, criterios de aceptación, referencias al brain y
  **comentario del dev** al cerrar (qué se hizo, decisiones, trampas, cómo se verificó).

## Ramas

| Rama | Rol |
|---|---|
| `production` | Estable / release |
| `staging` | Pre-producción / QA |
| `integration` | Integración diaria — **todo el trabajo se mergea acá** |

| Tipo | Patrón | Sale de → mergea a |
|---|---|---|
| feature | `feature/TPA-N` | `integration` → `integration` |
| bugfix | `bugfix/TPA-N` | `integration` → `integration` |

- Una rama por ticket; merge a `integration` con **`--no-ff`**.
- Promociones `integration → staging → production` = merges controlados.
- Si un ticket toca varios repos, se usa la **misma rama** `feature/TPA-N` en cada uno.

## Commits

Tres `-m`, exactamente:

```bash
git commit \
  -m "feat: Título del ticket" \
  -m "short description of the task in english" \
  -m "refs: #TPA-N"
```

- Línea 1: `feat:` (feature) o `fix:` (bugfix) + **título del ticket** tal cual.
- Línea 2: descripción corta **en inglés**.
- Línea 3: `refs: #TPA-N`.

> **Sin co-autoría.** Ni commits ni PRs llevan `Co-Authored-By`, firmas ni «Generated with».
> Si se coló: `git commit --amend` con el mensaje limpio y `git push --force-with-lease`.

## Estructura del código (`src/`)

Monolito modular por dominio:

```
app/
├── Domain/
│   ├── Establecimientos/   Hacienda/   Reproduccion/   Sanidad/   Documentos/
│   ├── Comercial/          Organizaciones/   Integridad/   Perfil/   Sync/
│   └── Sistema/            (health y utilidades transversales)
│       ├── Actions/        una operación de dominio por clase (handle())
│       ├── Models/         modelos Eloquent del dominio
│       ├── Data/           value objects / DTOs readonly
│       └── (Enums/, Policies/, Events/, Jobs/, Contracts/ a medida que hagan falta)
├── Http/
│   ├── Controllers/Api/V1/<Dominio>/   controllers finos
│   ├── Requests/V1/<Dominio>/          FormRequests (validación + authorize)
│   └── Resources/V1/<Dominio>/         API Resources (JSON snake_case)
└── Models/User.php                     identidad (transversal)
```

### Flujo de un endpoint

**Controller fino → FormRequest → Action → Resource.**

```php
public function store(RegistrarEventoRequest $request, RegistrarEvento $registrar): EventoResource
{
    $this->authorize('create', [Evento::class, $request->establecimiento()]);

    return EventoResource::make($registrar->handle($request->validated(), $request->user()));
}
```

- **Controller**: sin lógica de negocio ni queries con reglas; solo orquesta.
- **FormRequest**: toda validación de escritura.
- **Action**: una operación de dominio, dentro de `DB::transaction` cuando escribe.
- **Resource**: salida JSON; en lo público, **whitelist explícita** de campos (privado por defecto).
- **Autorización**: Policy + membresía del establecimiento con rol (no solo abilities del token).
- Rutas en `routes/api.php` bajo `Route::prefix('v1')`, un grupo por dominio.

### Invariantes que el código tiene que respetar

1. **Nada se borra**: eventos y versiones de documentos son append-only; las correcciones son eventos nuevos con motivo.
2. **Reglas por especie como datos** (catálogos/seeders), nunca `if especie` en el dominio.
3. **Privado por defecto**: CUIT, RENSPA, precios, reactores y ubicación exacta no se publican solos.
4. **Lenguaje de confianza**: nunca «certificado», «verificado oficialmente», «apto exportación».
5. IDs de eventos **UUIDv7** generados en el cliente (idempotencia de sync).
6. Drivers externos detrás de interfaces (`AnchorDriver`, `PdfRenderer`, `PushNotifier`) con stub en tests.

## Base de datos

- PostgreSQL 16 + **PostGIS** y **pgcrypto** habilitados por la primera migración.
- Migraciones **aditivas**: integration, staging y production comparten esquema; no se reescriben migraciones mergeadas.
- Seeders **idempotentes** (`updateOrCreate` / `firstOrCreate`): el entrypoint los corre en cada arranque
  (`SEED_ON_BOOT=false` para desactivarlo).
- Tests contra la base `tropilla_test` (la crea `docker/postgres/initdb` al inicializar el volumen).

## Tests y calidad

```bash
docker compose exec app php artisan test        # Pest, tiene que quedar verde
docker compose exec app ./vendor/bin/pint       # formato PSR-12 / Laravel
```

- Cada endpoint nuevo: test feature Pest (caso feliz + 403 de otro establecimiento + 422).
- Todo en el contenedor: el PHP del host no trae `pdo_pgsql`.

## Definition of done

1. Criterios de aceptación cumplidos y tests verdes; Pint limpio.
2. Mergeado a `integration` con `--no-ff`.
3. Comentario del dev en Notion y en `brain/tickets/TICKETS.md`; estado «Hecho».
4. Si cambió una decisión funcional o técnica, actualizado el doc del brain.
