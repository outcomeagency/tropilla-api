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

## TPA-13 · Datos del establecimiento y zonas sanitarias · ✅ (parte API)

**Endpoints:** `PATCH /establecimientos/{establecimiento}` (+ `GET` de TPA-10).

**Comentario del dev:**
- PATCH parcial con `row_version` obligatorio (409 `conflicto_row_version`), lock de la cadena + fila, auditoría
  antes/después y `cambios` (baja por sync). Zonas sanitarias se mergean (mandar solo `rabia_endemica` no borra el resto).
- Garrapata sugerida por departamento (Corrientes) al crear; la sugerencia por departamento también sale en `/catalogos` (TPA-15).
  Ubicación por punto (PostGIS) y RENSPA privados: `null` sin `establecimiento.ver_privados`; la auditoría no guarda la ubicación exacta.
- Interdicción: no es un campo; `interdiccion_vigente` queda `null` hasta que exista el evento `establecimiento.interdiccion` (TPA-22).
- Trampa: cambiar `provincia_codigo` exige mandar `departamento_codigo` coherente. Pendiente: impedir quitar una especie con animales activos (cuando exista hacienda).
- Verificado: 6 tests (edición completa + auditoría sin ubicación, merge de zonas, 409, 422, 403 por rol, 404 ajeno, admin).

## TPA-14 · Potreros · ✅ (parte API)

**Endpoints:** `GET|POST …/potreros`, `GET|PATCH …/potreros/{p}`, `POST …/potreros/{p}/archivar`.

**Comentario del dev:**
- Tabla `potreros` (maestro sincronizable, `row_version`, archivado en vez de borrado) con `poligono geography(Polygon,4326)`
  + índice GiST, y nombre único case-insensitive entre no archivados (índice parcial sobre `lower(nombre)`).
- `GuardarPotrero` (crear/editar/archivar) es la Action que también usará el push de maestros (TPA-43): alta idempotente
  por `id` del cliente (reintento → 200 con el existente), 409 por `row_version`, auditoría y `cambios` (`archivar` = op propia).
- **Desvío aditivo del contrato:** `poligono` (GeoJSON Polygon) en `Potrero`/`PotreroInput` (ya en `docs/openapi-v1.yaml`);
  si no se declara superficie se calcula con `ST_Area`. Coordenadas del GeoJSON como números (estándar GeoJSON; no se hashean).
- Ocupación (`cabezas`, `lotes`) se calcula en `OcupacionPotreros`; devuelve 0 hasta que existan las proyecciones (TPA-20/22).
- Verificado: 5 tests (idempotencia por id, polígono y superficie calculada ~100 ha, PATCH/409/archivar/nombre liberado, 422, 403 lectura, 404 ajeno).

## TPA-15 · Catálogos y parámetros por especie · ✅

**Endpoints:** `GET /catalogos` (público, ETag/304), `GET /sync/catalogos`, `GET …/parametros?especie&raza`,
`PUT …/parametros/{clave}`, `POST …/catalogo-propio`.

**Comentario del dev:**
- Tablas de `modelo-datos §3.1` (especies, razas, categorías, categorías de faena Res. 470/2012, reglas de recategorización
  con el DSL, parámetros con vigencia, tipos de evento + `tipos_evento_versiones` con el JSON Schema v1, tipos de documento,
  tipos de prueba, `catalogo_items`, plantillas sanitarias, `catalogo_versiones`). Seeders idempotentes con los valores de §3.3
  (bovinos `validado_por_vet=false`); la versión de catálogos sube solo si cambia el hash del contenido.
- Los JSON Schemas salen de `database/data/eventos-payload-schemas.json` (copia del brain) con los `$defs` embebidos por tipo.
  `opis/json-schema` agregado para validar payloads (TPA-22). `requiere_sexo` por tipo (parto/aborto/tacto… H; castración M) es dato.
- `Parametros::valor()` resuelve establecimiento+raza → establecimiento → raza → especie → global; el PUT agrega fila
  `vigente_desde=hoy` (no pisa histórico) y valida el tipo contra el global. Catálogo propio con códigos `X_<ESP>_<SLUG>` / `x_<slug>`, idempotente.
- **Sin `if especie`**: validaciones de especie/actividades/instalaciones/ambientes leen las tablas de catálogo (se reemplazaron las constantes de TPA-10/14).
  Agregado de datos: enfermedades `tuberculosis`, `parasitos_internos`, `parasitos_externos` (las plantillas las necesitan).
- Trampa: la regla 95 (vaca por preñez) usa una hoja `{param, op, valor}` en el DSL (criterio del productor); el evaluador la soporta en TPA-25.
- Verificado: 7 tests (paquete, i18n en/es, 304 por versión y ETag, idempotencia del seeder, resolución por especificidad incl. override del establecimiento, 404/422/403, catálogo propio).

## TPA-39 · Cadena de hashes de eventos y documentos · ✅

**Piezas:** `App\Domain\Integridad\CanonicalJson` (TROPILLA-CJ/1), `Merkle` (RFC 6962), tabla `registros_integridad`
(append-only), Action `RegistrarEnCadena`, comando `integridad:verificar-cadenas` (programado 02:30 ART).

**Comentario del dev:**
- Canonicalizador PHP = referencia de `sync-e-integridad §4.6`; decodifica siempre con objetos (`{}` ≠ `[]`), rechaza
  floats/enteros > 2^53/claves no ASCII/UTF-8 inválido con `CanonicalizacionInvalida` (código de push `canonicalizacion`).
  **Los 6 vectores, los 6 negativos, la cadena de 5 eslabones y el Merkle (raíces 1/3/5 hojas + las 5 pruebas) dan idénticos**
  a `vectores-hash-v1.json` (copiado a `tests/fixtures/`, mismo archivo que usa Flutter).
- Huella (cliente) ≠ eslabón (servidor): `RegistrarEnCadena` exige la cadena bloqueada (`SELECT … FOR UPDATE`) dentro de la
  transacción del registro; `seq` por cadena + `UNIQUE (sujeto_tipo, sujeto_id)`. La verificación recalcula la huella desde el
  **sobre guardado** (`FuentesSobre`, `jsonb::text`) y los eslabones; ante diferencias loguea `critical` y sale con error.
- Trigger `tropilla_inmutable` en `registros_integridad` (+ `cambios`, `auditoria` de TPA-10): UPDATE/DELETE/TRUNCATE → error de Postgres.
- La verificación de la huella enviada por el dispositivo se aplica en el push (TPA-43) con estas mismas piezas.
- Verificado: 15 tests unitarios de vectores + 7 de feature (cadena = vectores, cadenas independientes, sujeto único, append-only ×3, detección de fila alterada con trigger deshabilitado).
