# PROGRESS — tropilla-api

Bitácora de tickets cerrados en este repo: estado, endpoints y **comentario del dev**
(qué se hizo, decisiones, trampas, cómo se verificó). El contrato que manda es
[`openapi-v1.yaml`](openapi-v1.yaml) (copia de `brain/technical/openapi-v1.yaml`); los desvíos se anotan
acá y en la sección «Desvíos del contrato» al final.

## Convenciones transversales (decididas en TPA-9/10, valen para todo)

- **Errores** con el formato de `api-v1.md §3` (`message`, `code`, `errors`, `advertencias`, `request_id` + header
  `X-Request-Id`), centralizado en `bootstrap/app.php`. Errores de dominio: `App\Exceptions\ApiException`.
- **Otro establecimiento → 404 `no_encontrado`** (decisión A6 del contrato: no filtrar existencia). Dentro del propio
  establecimiento, rol sin la ability → **403 `sin_permiso`**. Los tests cubren ambos casos.
- Fechas-hora `YYYY-MM-DDTHH:MM:SS.sssZ`, decimales como string canónico (`App\Support\Formato`).
- Mensajes de validación en español (`lang/es/validation.php`); idioma por `Accept-Language` / `?locale=`.

---

## TPA-9 · Registro e inicio de sesión · ✅

**Endpoints:** `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `GET|PATCH /auth/me`,
`POST /auth/email/reenviar`, `POST /auth/email/verificar`, `POST /auth/password/olvide`,
`POST /auth/password/restablecer`, `PUT /auth/dispositivos/{id}`, `POST /auth/dispositivos/{id}/revocar`.

**Comentario del dev:**
- Identidad en tabla nueva `usuarios` (uuid v7, email `citext`, teléfono E.164, `CHECK email o teléfono`); la `users` del
  esqueleto queda sin uso (migración aditiva). `personal_access_tokens.tokenable_id` se ensanchó bigint→varchar(36) (sin pérdida).
- Tokens Sanctum con ability = cliente: `web` (vence a 30 días, **deslizante** vía listener de `TokenAuthenticated`) y `app`
  (uno por dispositivo, sin vencimiento; re-login en el mismo dispositivo revoca el anterior; teléfono compartido → el
  dispositivo pasa al nuevo usuario). Verificación de email con token HMAC sin estado (incluye hash del email: cambiarlo
  invalida links viejos); restablecer contraseña con token hasheado en `restablecimientos_password` y revoca todos los tokens.
- Trampas: `olvide` por teléfono responde 204 pero **no envía nada** (no hay proveedor SMS en el MVP; queda logueado).
  `cliente` se asume `web` si falta (el front provisorio no lo manda). Rate limit 5/min/IP en register/login/olvide/restablecer/verificar.
- Se agregaron extensiones `citext`, `pg_trgm`, `unaccent` y la función `tropilla_inmutable()` para los triggers append-only.
- Verificado: 21 tests Pest nuevos (registro email/teléfono, 422s, login case-insensitive, un token por dispositivo,
  logout, bloqueo, rate limit 429, verificación/restablecer, dispositivos ajenos 404) + smoke `curl` contra :8090.

## TPA-10 · Alta de productor y establecimiento · ✅

**Endpoints:** `POST /onboarding`, `GET|PATCH /productores/{productor}`, `POST /establecimientos`, `GET /establecimientos/{establecimiento}`.

**Comentario del dev:**
- Tablas `productores`, `establecimientos` (PostGIS `geography(Point)`, `text[]` para actividades/especies/instalaciones,
  `CHECK cardinality > 0`), `membresias` (índices únicos parciales: una activa por usuario y **un solo dueño**),
  `geo_departamentos` (seed Corrientes, 25 deptos INDEC con garrapata sugerida), y las transversales que nacen con el
  campo: `cadenas` (lock por establecimiento), `cambios` (cursor de sync, append-only) y `auditoria` (append-only).
- Matriz de permisos completa de `modelo-datos §13.1` en `Rol::MATRIZ`; un Gate por ability que delega en
  `EstablecimientoPolicy` (membresía activa + rol). Sin membresía → 404; revocada → 403 `membresia_revocada`; sin ability → 403.
- CUIT: validación módulo 11, cifrado con cast `encrypted` + `cuit_hash` HMAC con pepper; solo dueño/admin lo ven (en el
  resto la clave viene `null`). RENSPA y ubicación igual (`establecimiento.ver_privados`). Garrapata se sugiere por departamento si no se declara.
- Trampas: `departamento_codigo` no tiene FK a `geo_departamentos` (solo Corrientes está cargado): si la provincia tiene
  departamentos cargados se exige que exista; si no, se valida el formato `<prov>NNN`. El CUIT no es único (no filtrar existencia).
- Verificado: 14 tests nuevos (onboarding feliz + cifrado + 422 + 401; productor CUIT por rol, 404 ajeno, 409 row_version, 403; establecimiento privados, 404/403 revocada, multi-establecimiento).

## TPA-12 · Selector de establecimiento · ✅ (parte API)

**Endpoints:** `GET /establecimientos` (+ `membresias[]` con abilities en `GET /auth/me` y en el login).

**Comentario del dev:**
- Lista los establecimientos con membresía activa y no archivados, cada uno con `mi_rol` y `abilities` calculadas
  (la membresía se precarga para evitar N+1). Orden: productor, nombre. Un usuario sin campos recibe `[]` (→ onboarding).
- La API no guarda el «establecimiento activo»: lo recuerda cada cliente (cookie en Next, `sync_estado` en la app).
  La descarga offline del activo es `GET /sync/snapshot?establecimiento_id=` (TPA-43).
- Verificado: 2 tests (filtra ajenos/revocados/archivados, privados null para asesor, lista vacía, 401).

## TPA-11 · Invitar usuarios al establecimiento con roles · ✅

**Endpoints:** `GET …/membresias?estado=`, `POST …/membresias/{m}/rol`, `POST …/membresias/{m}/revocar`,
`POST …/titularidad`, `GET|POST …/invitaciones`, `POST …/invitaciones/{i}/revocar`, `GET /public/invitaciones/{token}`,
`POST /invitaciones/{token}/aceptar`, `GET …/auditoria` (cursor).

**Comentario del dev:**
- `invitaciones` con `token_hash` (SHA-256; el token solo vive en el mail y en `url` de la respuesta de creación),
  vence a 7 días, rol nunca `dueno`. Por teléfono no hay SMS: el dueño comparte la `url` (WhatsApp). Aceptar exige
  sesión pero no que el email coincida (el token es el secreto). Matriz de `functional/04 §12` = `Rol::MATRIZ` (TPA-10).
- Cambiar rol = revocar + crear (historia en `membresias` + `auditoria`); el dueño no se degrada ni se revoca (422
  `regla_bloqueante`): primero transferir titularidad, que solo hace el dueño hacia un miembro activo; el saliente queda **admin** (decisión).
- Trampas: el binding con scope adivina la relación con el plural inglés (`invitacions`); se resolvió con el trait
  `BindingHijosEnEspanol` (inflector español local) — cambiar el Pluralizer global rompe `personal_access_tokens`.
  Paginación por cursor genérica (`CursorRespuesta`: solo `data` + `meta`), usada por la auditoría.
- Verificado: 10 tests (invitar mail/teléfono, flujo público→aceptar, vencida/revocada, 422/403/404, cambio de rol,
  protección del dueño, revocación → `membresia_revocada`, scoped 404, transferencia, auditoría paginada).
