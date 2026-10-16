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

## TPA-22 · Eventos de hacienda · ✅

**Endpoints:** `POST …/eventos` (web: el servidor arma el sobre), `GET …/eventos` (actividad, cursor; filtros tipo/modulo/desde/hasta/autor_id/lote_id).
**Comandos:** `proyecciones:reconstruir {establecimiento?}`.

**Comentario del dev:**
- Tablas `eventos` + `evento_animales` (append-only con trigger y guard en el modelo; CHECKs de versión de §4.4, índice único
  parcial de versión vigente, FK diferida a la raíz), vista `eventos_vigentes`, `animales` (identidad), `lotes` (maestro mínimo,
  endpoints en TPA-20), `animal_identificaciones`, `animales_estado`, `lotes_estado`, `lote_conteos` (sin CHECK ≥ 0, revisión
  `conteo_negativo`), `existencias` y `revisiones`.
- **Una sola Action `RegistrarEvento`** para web y push (§7.4 en orden): canonicalización/huella → estructura y pertenencia →
  idempotencia (duplicado / `id_reutilizado`) → lock de la cadena → ability por módulo del tipo → JSON Schema del payload
  (`opis/json-schema` contra `tipos_evento_versiones`) → sujetos admitidos (dato del tipo) → referencias (`dependencia_faltante`)
  → reglas bloqueantes desde catálogos (sexo requerido por tipo, categoría/raza de la especie y sexo, lote de otra especie,
  RFID 15 dígitos, especie declarada en el establecimiento) → conflicto de versión → coherencia (pesaje atípico con parámetros,
  retiro vigente, interdicción, evento posterior a baja, identificación duplicada, tatuaje sin prefijo de raza).
  En la web las advertencias devuelven `422 requiere_confirmacion` hasta `confirmar_advertencias: true`; online, «posterior a baja»
  es `regla_bloqueante` e identificación duplicada `409`; por push se aceptan con observación + revisión.
- **Proyector**: recalcula cada animal afectado **desde cero** re-aplicando sus eventos vigentes en orden (más simple que
  incremental y equivalente; test «reconstruir ≡ en línea» fila por fila). Cubre alta/ingreso/nacimiento/parto (crías con madre),
  traslado, cambio de lote, identificación (colocación/reposición/retiro, una vigente por tipo), pesaje + GDP, CC, recategorización,
  castración, dentición, ficha, bajas (estado + fecha), reproducción (servida/preñada/vacía/parida, FPP con gestación por
  especie/raza/establecimiento) y sanidad (retiro con snapshot de días; reactor). Lotes anónimos: conteo por categoría
  (+/−/movimientos), potrero del último traslado, peso promedio y GDP. Ocupación de potreros ya real (TPA-14).
- Trampas: el sobre se guarda y se hashea desde el stdClass (nunca `json_decode(…, true)`): `{}` ≠ `[]`; el body web se lee con
  `$request->getContent()`. Fechas solo-día → 12:00 de la zona del establecimiento. Parámetros seed con `vigente_desde = 2000-01-01`
  (con 2026 los eventos históricos no encontraban gestación).
- Verificado: 14 tests de feature (alta + cadena + verificación de huella desde el sobre guardado, lote anónimo + traslado +
  movimientos + ocupación, GDP y advertencia, baja y bloqueo posterior, reglas por especie/sexo, parto con FPP Murrah 303 d,
  422 de schema/sujetos, 409 identificación, 403/404, idempotencia, append-only, reconstrucción, actividad con cursor, interdicción).

## TPA-16 · Alta rápida de animal desde el celular · ✅ (parte API) + seeder DEMO

**Endpoints:** `POST …/animales` (AltaRapidaInput → 201 Animal), `GET …/animales/identificador?valor&tipo`, `GET …/animales/{a}` (ficha, base de TPA-17).
**Seeder:** `DemoLaEsperanzaSeeder` (corre solo en `local`; `demo@tropilla.ar` / `tropilla-demo`, documentado en el README).

**Comentario del dev:**
- Alta rápida = atajo que arma un `hacienda.alta` (`motivo: alta_rapida`) con un solo animal y lo pasa por `RegistrarEvento`
  (mismo sobre/huella/cadena/proyección que el push offline de la app). `animal_id` opcional (si falta lo genera el servidor);
  campos opcionales en `null` se omiten (el payload no admite null). 409 `identificacion_duplicada` online.
- El chequeo de duplicado usa el valor normalizado (mayúsculas, sin espacios/guiones/puntos): `05-00` encuentra `0500`; el RFID
  se valida con 15 dígitos **normalizados** (`032 000123456789` es válido).
- Sugerencia de categoría (base de TPA-25): un animal que entra con categoría no-cría queda `destetado` (dato `es_cria`) y el
  sugeridor **nunca sugiere volver a una categoría anterior** (orden del catálogo) — sin esto una vaca de inventario sin partos
  registrados se sugería «ternera».
- DEMO: todo por los caminos reales (onboarding, `GuardarPotrero`, eventos): 112 eventos, existencias exactas del brief
  (BOV 1.240 = vacas 620, vaquillonas 180, terneros/as 310, novillos 110, toros 20; BUB 380 = búfalas 190, bubillas 60,
  bucerros/as 95, bubillos 30, toros 5). U-0142: tacto 25/09/2026 con 194 días → FPP **12/01/2027** (el brief decía «210 días»:
  son los días al día de hoy; con 210 al tacto la FPP daría 27/12/2026). 4 búfalas con parto en ≤ 7 días (relativo a la fecha de
  carga), 8 bucerras destetadas con sugerencia a bubilla, bubillos 4 dientes a 468 kg → clase faena «BBO joven pesado».
- Verificado: 4 tests de alta rápida (201 + identificador normalizado, id del cliente, 409, 422 ×3, 403/404) + test del demo
  (login, totales 1.240/380, U-0142, 8 sugerencias, promedio 468, `integridad:verificar-cadenas` OK, idempotencia).

## TPA-17 · Ficha del animal con timeline · ✅ (parte API)

**Endpoints:** `GET …/animales/{a}` (Animal), `GET …/animales/{a}/eventos?tipo&modulo&incluir_anulados` (cursor),
`GET …/animales/{a}/genealogia`, `GET …/animales/{a}/documentos`.

**Comentario del dev:**
- Ficha = `animales_estado` + nombres (lote, potrero efectivo = propio o el de su lote, categoría traducida), identificaciones
  vigentes y retiradas, madre/padre, edad y retiro vigente calculados al leer, **clase de faena** derivada de `categorias_faena`
  (categoría + dentición + peso: datos, no código) y `confianza_resumen` (eventos / con integridad registrada).
- Timeline: eventos vigentes del animal + los grupales del lote anónimo del que se individualizó, anteriores a la
  individualización (`heredado_de_lote: true`); cada ítem con autor por rol, `confianza`, `corregido`/`anulado`; pestañas
  Sanidad/Reproducción = filtro `modulo`. Anulados ocultos salvo `incluir_anulados=true`.
- `documentos` devuelve `[]` hasta la carpeta documental (TPA-36); queda el endpoint y su permiso (`documentos.ver`).
- Verificado: 5 tests (encabezado completo con clase BBO joven pesado, timeline con filtros, herencia del lote, genealogía madre/crías, 404 ajeno, lectura puede ver).

## TPA-18 · Listado de hacienda con búsqueda y filtros · ✅ (parte API)

**Endpoints:** `GET …/animales?q&especie&categoria&lote_id&potrero_id&estado&estado_reproductivo&sexo&sanidad&sugerencia_categoria&orden&limit&cursor`.

**Comentario del dev:**
- Búsqueda por **cualquier** identificador vigente o no (caravana, RFID, tatuaje, marca, registro), parcial y normalizada
  (mayúsculas, sin espacios/guiones/puntos) sobre `animal_identificaciones.valor_normalizado` (índice GIN trigram).
- Filtros persistentes (los guarda el cliente): especie, categoría, lote, potrero efectivo (propio o el del lote), sexo, estado
  (default `activo`; `todos`), estado reproductivo, `sanidad=retiro|reactor` (`pendiente` llega con tareas, TPA-33), sugerencia de categoría.
- Paginado server-side con orden elegible (`identificacion`, `categoria`, `peso`, `edad`, `reciente`): como el orden es por
  columnas nulas, el cursor opaco es posición + huella de filtros (`CursorOffset`); un cursor con otros filtros/orden → 400
  `request_invalido` (api-v1 §4). Selección múltiple para eventos grupales = del lado del cliente (`animal_ids`).
- Verificado: 5 tests (búsqueda caravana/RFID parcial, 8 filtros, orden + paginado + cursor inválido, estados, lectura/404).

## TPA-19 · Identificaciones múltiples por animal · ✅ (parte API)

**Endpoints:** vía eventos (`POST …/eventos` tipo `hacienda.identificacion`, o `identificaciones[]` en altas) + lectura en la ficha
y en `GET …/animales/identificador`.

**Comentario del dev:**
- Tipos del catálogo: caravana visual, oficial/CUIG, RFID (15 dígitos **normalizados**: el bastón en modo teclado puede meter
  espacios), tatuaje con prefijo de la raza (E/U/A desde `razas.prefijo_tatuaje`: si no coincide, advertencia
  `tatuaje_prefijo`, también para animales existentes), marca/señal y registro genealógico (exige `entidad`, con `tipo_registro`).
- Única vigente por tipo y establecimiento: índice único parcial + 409 online / revisión por sync (TPA-22). Colocar otra del
  mismo tipo reemplaza la anterior (salvo marca y registro, que pueden ser varios).
- Reposición exige `reemplaza` y que esa identificación esté vigente en el animal (si no, `regla_bloqueante`); reposición y retiro
  dejan la anterior **no vigente con fecha** (historial completo en la ficha); la caravana liberada puede usarse en otro animal.
- Verificado: 6 tests (todos los tipos, prefijo/RFID/entidad, historial de reposición, reglas de reposición/retiro, 409, 403/404).

## TPA-26 · Correcciones y anulaciones de eventos · ✅ (parte API)

**Endpoints:** `POST …/eventos/{e}/correcciones`, `POST …/eventos/{e}/anulaciones`, `GET …/eventos/{e}` (EventoDetalle: vigente +
versiones + integridad), `GET …/eventos/{e}/integridad`, `GET …/revisiones?estado&tipo` (cursor), `GET …/revisiones/{r}`,
`POST …/revisiones/{r}/resolver`.

**Comentario del dev:**
- La web arma la versión v+1 (`corrige_evento_id`, `hash_anterior` = huella de la corregida, `motivo` obligatorio; la anulación
  no lleva payload) y entra por `RegistrarEvento` → misma cadena, mismas reglas. Nada se borra: las versiones viejas quedan
  (`vigente: false`) y las proyecciones se recalculan desde cero (anular un pesaje revierte el peso; anular un alta deja el animal
  `anulado` y fuera de existencias). Una corrección no cambia el tipo, la especie ni el conjunto de animales que creó el alta.
- Online, corregir una versión que no es la vigente → 409 `version_no_vigente`; por sync queda `en_revision` + revisión
  `correccion_en_conflicto` (gana el servidor). Resolver: `reaplicada` crea una corrección sobre la vigente (motivo con el id de la
  revisión), `descartada`/`resuelta` cierran con motivo; la versión en revisión nunca cambia (M4). `id` opcional → idempotente.
- `IntegridadRegistro` ya devuelve huella/eslabón/seq; `anclaje` y `prueba` quedan `null` hasta TPA-40.
- Verificado: 6 tests (v2 encadenada + ver cambios + integridad, 409, anulaciones y efecto en proyecciones/stock, corrección del alta
  bloqueada por especie y válida por raza, 422/403/404, revisiones listar/resolver/cerrada).

## TPA-43 · Endpoints de sincronización · ✅

**Endpoints:** `POST /sync/push` (≤ 200 ítems, ≤ 2 MB, gzip aceptado, 60/min por token de dispositivo), `GET /sync/pull?establecimiento_id&cursor&limite`,
`GET /sync/snapshot?establecimiento_id`.

**Comentario del dev:**
- Push: cuerpo crudo decodificado **con objetos** (sin pasar por `$request->json()`), cada ítem en su propia transacción vía
  `RegistrarEvento` (canal `push`: huella recalculada y comparada, `dispositivo_id` del usuario). Resultado por ítem
  `aceptado` (seq/eslabón/registrado_en/observaciones) · `duplicado` (mismo id + misma huella) · `en_revision`
  (`conflicto_version`, `revision_id`, `version_vigente`) · `rechazado` (`huella_invalida`, `canonicalizacion`, `esquema_invalido`,
  `tipo_desconocido`*, `id_reutilizado`, `sin_permiso`, `dependencia_faltante`*, `regla_bloqueante`, `establecimiento_cerrado`; *reintentables).
  Por sync, «posterior a baja» e identificación duplicada se aceptan con observación + revisión (concurrencia offline).
- Maestros (`lote`, `potrero`): `base_row_version: 0` = alta offline idempotente; si no coincide, gana el servidor y el cambio
  queda en `revisiones` (`maestro_en_conflicto`, con diff). Nueva Action `GuardarLote` (la usan sync y TPA-20).
  `documento_version` → `tipo_desconocido` reintentable hasta TPA-36.
- Membresía revocada (§7.8): pull/snapshot → 403 `membresia_revocada`; push dentro de 72 h acepta ítems con `creado_en` ≤
  revocación (observación `carga_post_revocacion` + revisión), después 403 `establecimiento_cerrado`.
- Pull: `cambios.seq` por establecimiento (asignado bajo el lock de la cadena → sin huecos), dedupe por (entidad, id) dentro
  de la página, datos = **estado actual** (Evento, Animal, Lote, Potrero, Establecimiento, Membresía, Revisión); `comercial`/`fiscal`
  solo a dueño/admin; cursor `base64url("v1:"+est+":"+seq)`, de otro establecimiento → 422 `cursor_invalido`.
- Snapshot: estado completo + eventos vigentes de los últimos `snapshot_offline_dias` (400) y `cursor` leído en la misma
  transacción REPEATABLE READ. Demo: 44 animales individuales + 112 eventos en ~0,3 s.
- Verificado: 10 tests (idempotencia y seq sin huecos, 4 rechazos incl. float crudo, correcciones concurrentes → una en revisión
  y resolución «reaplicada», evento posterior a baja aceptado con revisión, maestros + conflicto, pull/cursor/422, filtro por
  visibilidad, snapshot → pull vacío, revocación 72 h / lectura 403 / 404, 422 del envío).

## TPA-20 · Lotes de manejo y lotes anónimos · ✅ (parte API)

**Endpoints:** `GET|POST …/lotes` (`?especie&archivados`), `GET|PATCH …/lotes/{l}`, `POST …/lotes/{l}/archivar`, `GET …/lotes/{l}/eventos` (cursor).

**Comentario del dev:**
- Maestro `lotes` (de TPA-22) con `GuardarLote` (de TPA-43): id del cliente idempotente, `row_version`, especie inmutable
  (un lote no mezcla especies: bloquea altas/cambios de lote de otra especie) y archivo solo sin cabezas.
- `Lote` devuelve la composición derivada de eventos: `conteo` por categoría (lote anónimo), `cabezas_individuales`/`anonimas`,
  potrero del último traslado, peso promedio + fecha y GDP promedio entre pesajes promedio.
- Lote anónimo recibe eventos grupales con `sujetos.tipo = lote` (+ `conteo` parcial); individualizar
  (`hacienda.individualizacion`) descuenta del conteo y el animal hereda en su timeline los eventos grupales previos.
- Verificado: 4 tests (ciclo completo de lote anónimo con muerte e individualización, especies, edición/archivo, 422/idempotencia/403/404).

## TPA-21 · Carga inicial de stock por planilla · ✅ (parte API)

**Endpoints:** `POST …/importaciones` (202), `GET …/importaciones/{i}`, `GET …/importaciones/{i}/filas?con_errores` (cursor),
`PATCH …/importaciones/{i}/filas/{numero}`, `POST …/importaciones/{i}/confirmar` (202), `POST …/importaciones/{i}/cancelar`,
`GET /importaciones/plantilla?especie` (CSV).

**Comentario del dev:**
- Tablas `importaciones`, `importacion_filas` y la base de `archivos` (direccionado por SHA-256, clave
  `archivos/sha256/aa/bb/<hash>`, nunca se sobrescribe; la subida diferida completa es TPA-44). CSV (`,`/`;`, BOM) y XLSX
  (`openspout/openspout`, primera hoja). **Desvío aditivo del contrato:** además de `archivo_sha256`, el POST acepta
  multipart `archivo` (documentado en `docs/openapi-v1.yaml`) — sin eso no hay forma de subir la planilla hasta TPA-44.
- Validación en cola (job; `sync` en tests) fila por fila: especie/sexo/categoría/raza por código **o nombre del catálogo**
  (datos), fecha `AAAA-MM-DD` o `DD/MM/AAAA`, dentición, RFID 15 dígitos, identificaciones repetidas en el archivo o ya
  vigentes, lote/potrero por nombre (lote de la misma especie); advertencias (tatuaje sin prefijo, sin identificación).
  `mapeo` para columnas con otros nombres. Corregir una fila la revalida; descartarla la saca del conteo.
- Confirmar = **un `hacienda.alta` por fila** (`motivo: importacion`, `importacion_id`, `origen: importacion`) — se usa `alta`
  y no `ingreso` porque el inventario inicial no es una compra (modelo-datos §12). `animal_id` y `evento_id` preasignados:
  reconfirmar no duplica. La alternativa «lote anónimo por categoría» ya existe con `hacienda.alta` sobre `sujetos.tipo = lote`.
- Verificado: 4 tests (ciclo completo con errores/corrección/descarte/confirmación idempotente, plantilla + mapeo, XLSX, cancelar/422/403/404).
