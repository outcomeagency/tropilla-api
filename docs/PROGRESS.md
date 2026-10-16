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
