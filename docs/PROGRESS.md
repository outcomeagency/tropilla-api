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

## TPA-2 · bugfix: arranque en máquina limpia e imágenes de MinIO · ✅

**Comentario del dev:**
- `docker/php/entrypoint.sh` copia `.env` desde `.env.example` **antes** de `composer install`/`dump-autoload`: el
  `package:discover` del post-autoload-dump bootea Laravel y sin `.env` caía en `APP_ENV=production` → el guard del anclaje stub abortaba.
  `key:generate` sigue después de composer (necesita `vendor`).
- `compose.yaml` pasa a `pgsty/minio` / `pgsty/mc` (MinIO retiró sus imágenes): `mc ready local` y `minio-init` (bucket + versionado) andan igual.
- Trampa: el entrypoint se copia en la imagen `tropilla/php`; tras cambiarlo hace falta `docker compose up -d --build` (anotado en el README).
- Verificado: entrypoint viejo vs nuevo sobre una copia limpia de `src/` (sin `vendor` ni `.env`): el viejo reproduce el `RuntimeException`,
  el nuevo llega a esperar la base; stack de dev ya corriendo con las imágenes pgsty (healthy, init exit 0, versionado activo); suite verde.

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

### TPA-43 · bugfix: desvíos reportados por la app Flutter · ✅

**Comentario del dev:**
- `/sync/push`, `/sync/pull` y `/sync/snapshot` ahora responden con el envoltorio `{"data": …}` que ya declaraba el YAML (la respuesta
  estaba «desnuda»): `cursor`, `hay_mas` y `catalogos_version` viajan **dentro** de `data` (son parte del recurso, no `meta` de listado);
  se corrigió la descripción del parámetro `cursor` en el YAML (decía `meta.next_cursor`). Cambio de forma: la app ya lee `data`.
- `/sync/catalogos?version=<vigente>` → 304 sin cuerpo (el nombre de ruta real es `v1.sync.catalogos`; tolera `v3`, ETag débil `W/` y `*`);
  versión vieja o basura → 200. `/sync/catalogos` va `Cache-Control: private, no-cache` (lleva token); `/catalogos` sigue público.
- Paquete de catálogos: las filas por especie traen `especie_codigo`; `especie` queda como alias (la web y caches viejos no se rompen).
- `GET …/animales/identificador` tolera ceros a la izquierda por tramo numérico («142» ≡ «0142», «U142» ≡ «U-0142»); `50`/`5000` no
  coinciden con `0500`. Sin índice de expresión (filtra por establecimiento + vigente; alcanza para el volumen del MVP).
- «Prenada» → «Preñada» ya estaba resuelto en TPA-28 (aserción en `TactoTest`). Verificado: SyncTest/CatalogosTest/AltaRapidaTest ampliados; suite 205 verde.

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

### TPA-21 · bugfix: valor original de cada fila · ✅

**Comentario del dev:**
- Columna aditiva `importacion_filas.original` (jsonb, nullable) con lo que vino en la planilla, solo columnas de la plantilla y
  con el `mapeo` ya aplicado (mismas claves que acepta el PATCH). `ImportacionFila.original` sale en el listado de filas y en el PATCH.
- Decisión: `original` es del archivo y **no cambia** al corregir; lo corregido queda en `datos` (normalizado). Importaciones
  previas al cambio devuelven `null` (no se re-leen los archivos). La web puede dejar de releer el CSV en el navegador y anda con XLSX.
- Trampa: jsonb no conserva el orden de las claves; el cliente no debe depender del orden de `original`.
- Verificado: test nuevo (categoría que no corresponde a la especie, fecha inválida, sexo «X» y columna mapeada: `original` intacto
  y `datos` sin lo no entendido; PATCH no lo pisa; 422 acción inválida; 403 encargado; 404 ajeno) + suite verde.

## TPA-23 · Pesajes y ganancia diaria · ✅ (parte API)

**Endpoints:** vía `POST …/eventos` (`hacienda.pesaje`, modo `individual` | `promedio` con `cantidad`); resultados en la ficha
(`peso_kg`, `peso_fecha`, `gdp_kg_dia`), en el listado (orden por peso) y en `Lote` (`peso_promedio_kg`, `gdp_promedio_kg_dia`).

**Comentario del dev:**
- GDP individual = (peso − anterior) / días entre pesajes individuales (TPA-22). Lote con individuales: GDP = **promedio de las
  GDP individuales** y peso promedio de los individuales si son más recientes que el último pesaje promedio; lote anónimo:
  diferencia de promedios entre los dos últimos pesajes promedio (§6.3).
- Un pesaje promedio no pisa el peso individual (va a `lotes_estado`); exige `cantidad`.
- Alerta de pesaje atípico con `pesaje_atipico_pct`/`pesaje_atipico_dias` (parámetros por especie, editables por establecimiento):
  web → `422 requiere_confirmacion`; app → observación en el evento.
- Verificado: 3 tests (GDP individual 1,333 y de lote 0,967 = promedio, promedio de lote sin pisar individual + 422 sin cantidad, alerta dentro/fuera del plazo).

### TPA-23 · bugfix: serie de pesos por animal · ✅

**Endpoint nuevo:** `GET …/animales/{a}/pesajes?desde&hasta&incluir_lote` → `PuntoPeso[]` (ascendente, sin paginar).

**Comentario del dev:**
- `SeriePesos` lee el **payload** de los eventos vigentes del timeline del animal (no el `resumen`): pesajes individuales y promedio
  que lo incluyen, promedios del lote anónimo de origen previos a la individualización (`heredado_de_lote`), peso al nacer y al destete.
- Decisión: la GDP por punto replica la regla del proyector (pesajes individuales + peso al nacer, contra el anterior de fecha menor;
  el destete se muestra pero no cuenta), así el último punto coincide con `gdp_kg_dia` de la ficha. Los filtros se aplican después de calcular.
- Trampa: `hacienda.ver` lo tienen todos los roles; el único 403 posible es `membresia_revocada` (es lo que cubre el test).
- Verificado: 3 tests (serie completa 38→98→150→200→210,5 con GDP 1 / 0,85 = ficha, la madre no hereda el peso de la cría, filtro con GDP
  del anterior, herencia de lote y `incluir_lote=false`, 422 ×2, 404 inexistente/ajeno, lectura OK, 403 revocada) + suite verde.

## TPA-25 · Recategorización sugerida · ✅ (parte API)

**Endpoints:** `GET …/animales?sugerencia_categoria=true` (lista, con `categoria_sugerida`), `POST …/animales/recategorizar` (201 EventoResumen[]).
**Comando:** `hacienda:sugerir-categorias` (diario 04:00 ART).

**Comentario del dev:**
- `SugeridorCategoria` + `CondicionEvaluator` (DSL de §3.2: `todas`/`alguna`/`no`, hojas con `{param}` y hoja-parámetro) sobre
  `reglas_categoria` por prioridad (las del establecimiento reemplazan a las globales de igual prioridad). Las 9 reglas son las
  mismas para BOV/BUB; cambian los umbrales por parámetro: a los 17 meses el bubillito ya se sugiere bubillo (16 m) y el
  novillito todavía no (23 m). Se evalúa al proyectar cada evento y cada noche (la edad cambia sola).
- No se sugiere volver a una categoría anterior (orden del catálogo) — ver TPA-16.
- Confirmar en bloque: **un `hacienda.recategorizacion` por animal**, todo o nada; si coincide con la sugerida lleva
  `sugerida: true`, `regla_id` y el motivo de la regla; si el usuario elige otra, `motivo: manual`.
- Verificado: 5 tests (destete/castración/dentición/parto por especie, confirmación en bloque, atomicidad + 403, job por edad, evaluador del DSL).

### TPA-25 · bugfix: descartar sugerencia y sugerencia en altas por push · ✅

**Endpoint nuevo:** `POST …/animales/sugerencias/descartar` (`{items: [{animal_id, categoria}]}` → 200 AnimalResumen[]);
aditivo `Animal.categorias_descartadas`; parámetro nuevo `edad_destete_meses` (BOV 7 / BUB 7).

**Comentario del dev:**
- Causa real del hueco «push sin sugerencia»: el push sí proyecta y evalúa, pero una cría dada de alta grande (bucerro de 2 años) quedaba
  `destetado = false` sin evento de destete y ninguna regla cumplía. Ahora el sugeridor la considera destetada si su edad ≥ `edad_destete_meses`
  (dato por especie, mismo valor que la semilla de la app); vale igual para web, push y el job diario (que la sugiere al cumplir la edad).
- Descartar = tabla `sugerencias_descartadas` (animal, categoría): el sugeridor no propone esa categoría mientras la regla la siga dando;
  otra sugerencia distinta sí aparece. No es evento (no cambia el animal): auditoría `descartar_sugerencia` + cambio del animal para el pull.
  Todo o nada, idempotente; 422 si el animal es ajeno o la sugerencia cambió desde que el usuario la vio.
- Trampa: crías cargadas con fecha de nacimiento > 7 meses ahora salen sugeridas (se ajustó el test que usaba 8 meses). Base de dev: migrate + `CatalogosSeeder` + job (0 cambios en el demo).
- Verificado: 3 tests nuevos (alta por push con bucerro de 24 m → butorito y el de 3 m recién a los 7 m vía job; descartar con reproyección/job/parto
  posterior e idempotencia; 422 ×3 sin efectos, 403 lectura, 404 ajeno) + suite verde.

## TPA-48 · Ofertas de animales y lotes · ✅ (parte API)

**Endpoints:** `GET|POST …/ofertas`, `POST …/ofertas/desde-lote`, `GET|PATCH …/ofertas/{o}`, `GET …/ofertas/{o}/avisos`,
`GET …/ofertas/{o}/preview?locale`, `POST …/ofertas/{o}/publicar`, `POST …/ofertas/{o}/estado`, públicos
`GET /public/ofertas/{codigo}?locale` y `GET /public/ofertas/{codigo}/pdf?locale`.

**Comentario del dev:**
- Tablas `ofertas`, `oferta_animales`, `oferta_publicaciones` (append-only, sobre `tropilla.oferta_publicacion/1` hasheado y
  encadenado: lo que se publicó es lo que se ancla) y la base del perfil (`perfiles_publicos` con slug, `perfil_publicaciones`,
  `perfil_slugs_historicos`) que usa la ficha pública. Código público Crockford base32 de 10 caracteres.
- Precarga **derivada de la hacienda** (`ContenidoOferta`): categorías, razas, peso promedio con fecha/método y rango (individual
  o promedio del lote, lo más reciente), edad, dentición, **clase de faena** de `categorias_faena` (bubillos 4 dientes 468 kg →
  BBO pesado), sanidad de 12 meses sin datos individuales, retiro, origen y sistema. `mostrar` elige bloques; el precio solo sale
  con `mostrar_precio`. Avisos: retiro vigente, reactores (dato privado, no se publica), pesaje > `pesaje_antiguo_dias`, sin pesaje
  → `422 requiere_confirmacion` hasta `confirmar_avisos`. Publicar exige email verificado.
- **Compromisos (duda bloqueante nº 1 de Claude Design):** `libre · en_oferta · en_contrato · vendido` derivado (ofertas activas,
  contratos abiertos cuando exista TPA-56, estado del animal); publicar hacienda comprometida → **409 `comprometido`**; un lote no
  anónimo congela sus animales en la oferta; si se venden/dan de baja todos, la oferta pasa sola a `vendida`. Nuevo campo aditivo
  `compromiso` en `AnimalResumen` (documentado en el YAML).
- Ficha pública = clase separada (`OfertaPublicaResource`, whitelist), zona general (nunca ubicación exacta), SEO por idioma
  (canonical/alternates/JSON-LD; indexable solo si el perfil está publicado y en el directorio), leyenda fija es/en/pt.
  **Desvío:** el PDF (Gotenberg, con QR a `/v/{huella}`) se devuelve directo `200 application/pdf` en vez de `302` a una URL
  prefirmada (no hay todavía S3 de generados). Probado contra el Gotenberg real del stack.
- Demo: la oferta «30 bubillos Mediterráneos» queda publicada en el seeder.
- Verificado: 6 tests (desde lote → preview en → publicar hasheado → ficha pública sin precio/privados, avisos, compromisos 409 y
  venta → vendida, estados/republicar/retirada 404, PDF con QR, permisos asesor/encargado/404 y 422) + smoke del PDF real.

## TPA-49 · Consultas de compradores · ✅ (parte API)

**Endpoints:** `GET …/consultas?estado` (cursor; por defecto sin spam), `GET …/consultas/{c}` (marca vista), `POST …/consultas/{c}/estado`.

**Comentario del dev:**
- Tabla `consultas` (§9.6) con `vista_en` (ver no cambia el estado: sigue «nueva» hasta responder), estados
  nueva/respondida/cerrada/spam, `respondida_por`, `canal_respuesta`; IP solo como HMAC. Las crea el formulario público (TPA-55).
- Responder abre WhatsApp/email: la Resource arma `whatsapp_url` (`wa.me` con el teléfono normalizado) y `mailto_url` con un
  saludo **en el idioma del comprador** y el título de la oferta.
- Solo dueño/admin (`consultas.ver/gestionar`): el asesor y el encargado no ven datos personales del comprador (T12, duda 21).
  El aviso por email al recibir una consulta sale en TPA-55; el push (FCM) queda para alertas (TPA-53).
- Verificado: 2 tests (bandeja/filtros/links/vista/estados/422; 403 por rol, admin ve, 404 ajeno).

## TPA-55 · Contacto desde el exterior sin cuenta · ✅ (parte API)

**Endpoints:** `POST /public/consultas` (ConsultaPublicaInput → 201 `{recibida, mensaje}`).

**Comentario del dev:**
- Destino por `oferta_codigo` (oferta publicada/reservada) o `establecimiento_slug` (perfil **publicado**); `contrato_codigo`
  → 404 hasta TPA-56. Llega a la bandeja de TPA-49 con país, tipo de comprador, volumen e **idioma** (campo o Accept-Language);
  sesión opcional (si viene token se guarda `usuario_id`). Respuesta en el idioma del comprador.
- Anti-spam: honeypot `website` (responde 201 igual y guarda como `spam`, sin aviso), Cloudflare **Turnstile**
  (`TURNSTILE_SECRET`; sin secreto se acepta en local/testing y en producción el formulario responde 503) y **3/h por IP**.
  IP guardada solo como HMAC.
- Aviso por email a dueño/admin (`NuevaConsultaNotification`, en cola) con link a la bandeja; el detalle personal solo en el panel.
- Demo: 2 consultas sin responder sobre la oferta de bubillos (un exportador chino en inglés y un frigorífico local).
- Verificado: 5 tests (feliz con idioma y mail, honeypot, captcha inválido, rate limit 429, 422/404).

## TPA-50 · Perfil público del establecimiento · ✅ (parte API)

**Endpoints:** `GET|PATCH …/perfil`, `GET …/perfil/slug-disponible`, `GET …/perfil/preview?locale`, `POST …/perfil/preview/aceptar`,
`POST …/perfil/publicar`, `POST …/perfil/despublicar`, `POST …/perfil/secciones/{s}/congelar|descongelar`; públicos
`GET /public/establecimientos/{slug}?locale` (301 si el slug cambió), `…/{slug}/ofertas`, `…/{slug}/contratos` (`[]` hasta TPA-56).

**Comentario del dev:**
- Secciones activables (portada, actividad, rodeo exacto/rango, sanidad 12 meses, trazabilidad, documentos, oferta, contacto)
  calculadas **en vivo** desde la hacienda (se actualizan solas) salvo las **congeladas**, que muestran su snapshot.
  `PerfilPublicoBuilder` es whitelist: nunca CUIT, RENSPA, ubicación exacta (zona según `ubicacion_publica`, máx. localidad),
  precios, reactores, tratamientos individuales, nombres de empleados ni número de socio; contacto solo los canales que el
  productor marca visibles (el formulario siempre). Sanidad como hechos («Aftosa: N registros de vacunación»), nunca «al día» (duda 12).
- Vista previa obligatoria (`422 preview_requerida`) y email verificado; publicar = `perfil_publicaciones` hasheado y encadenado
  (`tropilla.perfil_publicacion/1`). Slug `^[a-z0-9-]{3,60}$` único; cambiarlo guarda el viejo en `perfil_slugs_historicos` → 301.
- Completitud 0–100 + `faltantes` (ranking del directorio); insignias solo por hechos medibles (`historial_12_meses`,
  `integridad_registrada`); SEO por idioma, indexable solo si está publicado **y** en el directorio.
- Demo: `/p/la-esperanza` publicado y en el directorio, textos es/en/pt, WhatsApp visible.
- Verificado: 4 tests (flujo completo + snapshot hasheado + ausencia de datos sensibles, exacto/congelar/descongelar, slug/301/409/despublicar, permisos).

## TPA-54 · Directorio público con buscador · ✅ (parte API)

**Endpoints:** `GET /public/directorio?q&provincia&departamento&especie&raza&categoria&con_oferta&con_contrato&locale&limit&cursor`,
`GET /public/directorio/facetas`, `GET /public/sitemap`.

**Comentario del dev:**
- Solo perfiles **publicados con el directorio activado** (opt-in; el demo lo activa). Consulta en vivo sobre perfiles,
  existencias, animales y ofertas (MVP; la proyección `directorio_entradas` + `tsvector` de §11 queda para cuando haya volumen).
  Búsqueda sin acentos (`unaccent`) por nombre, departamento y textos del perfil.
- Orden por **última actividad** (último evento registrado o edición del perfil) y completitud, nunca por pago; insignias solo
  por hechos medibles. Cursor opaco con huella de filtros (cambiar filtros → 400). `con_contrato` devuelve vacío hasta TPA-56.
- Facetas (provincias, especies, razas, categorías, con oferta) sobre el conjunto filtrado; sitemap solo con lo indexable
  (perfiles del directorio y sus ofertas activas) con idiomas.
- Verificado: 3 tests (filtros/búsqueda/oculto fuera, paginado + facetas, sitemap).

### TPA-54 · bugfix: fotos en la API + demo del directorio con fotos · ✅

**Rutas nuevas:** `GET /public/fotos/{sha256}` (sin cuenta, inmutable) y `GET /fotos/{sha256}?expires&signature` (privada, URL firmada).
**Seeder:** `DemoDirectorioSeeder` (después de `DemoLaEsperanzaSeeder`; `php artisan db:seed --class=DemoDirectorioSeeder`).

**Comentario del dev:**
- Schema `Foto {sha256, url, alt, ancho, alto}`: público en perfil (portada), oferta (congelada en la publicación), profesional y directorio; firmado en animal,
  usuario y vistas internas. La ruta pública sirve un hash solo si lo referencia un perfil publicado, la publicación vigente de una oferta
  publicada/reservada/vendida o un profesional público (retirada/borrador/no referenciado → 404); `immutable` + `ETag` = hash. La firmada es relativa
  (vale detrás del proxy de la web), estable dentro de la hora, y sin firma o vencida da 404. `archivos` suma `ancho`, `alto` (se miden al subir) y `alt` {es,en,pt}.
- Arreglos de paso: `PATCH …/perfil` validaba `fotos` pero no las guardaba; `PUT /me/profesional` acepta `foto_sha256`; toda foto debe ser un archivo completo (422 si no).
- Demo: 5 establecimientos (El Timbó, Los Caranday, Don Anselmo, La Paloma, Santa Rita; `<slug>@tropilla.test` / `tropilla-demo`) con hacienda por eventos, 7 ofertas + la de
  La Esperanza republicada con foto, 3 profesionales y fotos de animales por `hacienda.actualizacion_ficha` (sobreviven a la reconstrucción). Formosa, Chaco y Misiones
  usan códigos INDEC reales (34056, 22084, 54007) pero `geo_departamentos` solo tiene Corrientes: la zona pública muestra la provincia.
- Trampa: en el entorno compartido migré unos minutos después de montar el código; en esa ventana completar una subida dio 500 (columna `ancho`). Migrar antes de tocar el código.
- Verificado: 5 tests (público ok/304/no referenciado/borrador/retirada, firmada ok/sin firma/adulterada/vencida, 401 descarga sin token, 422/403/404 al guardar, demo
  completo + reconstrucción + idempotencia) + suite verde; seeder corrido sobre la base de dev (cadenas verificadas).

## TPA-41 · Página pública de verificación · ✅ (parte API)

**Endpoints:** `GET /public/verificaciones/{huella}?locale`, `GET /public/verificaciones/{huella}/prueba` (prueba.json),
`GET /public/verificaciones/archivo/{sha256}`, `POST /public/verificaciones/archivo` (multipart ≤ 25 MB, no se guarda).

**Comentario del dev:**
- Por huella: tipo, descripción (tipo de evento y fecha / título de la oferta), fecha de registro, **autor solo por rol**
  (nunca nombres de empleados), versiones y si es la vigente, `confianza`, e `IntegridadRegistro` (huella, eslabón anterior,
  eslabón, seq; anclaje y prueba de Merkle cuando exista TPA-40). **Verificar ≠ ver**: el sobre solo se publica para registros
  que ya son públicos (publicaciones de oferta y de perfil, T9); de un evento no sale ni el id del animal; el establecimiento
  aparece solo si su perfil está publicado (o si el registro es público).
- `prueba.json` trae fórmulas y datos para recalcular sin confiar en TROPILLA (§6.4). Leyenda obligatoria es/en/pt
  («prueba que no cambió desde la fecha de anclaje; no certifica que sea verdadero»).
- Archivo: el navegador calcula el SHA-256 (WebCrypto) o se sube y solo se hashea; coincide únicamente con versiones de documentos
  **publicados** (la carpeta documental es TPA-36: hasta entonces siempre «No coincide»). Un archivo privado responde igual que uno inexistente.
- Verificado: 4 tests (evento sin contenido + eslabón recalculable + prueba.json, versión no vigente y sobre público de oferta
  re-hasheable, 404, archivo por hash/subida sin guardar/422).

## TPA-27 · Servicio natural e inseminación · ✅ (parte API)

**Endpoints:** vía `POST …/eventos` (`reproduccion.servicio_natural`, `reproduccion.inseminacion`) y push; resultado en la ficha
(`estado_reproductivo`, `servicio_fecha`, `servicio_tipo` — **nuevo campo aditivo** en `Animal`, ya en el YAML —, `fecha_probable_parto`, `fpp_precision`).

**Comentario del dev:**
- La proyección ya venía de TPA-22 (servida + FPP con gestación por especie/raza); se agregó `App\Domain\Reproduccion\ReglasReproductivas`,
  que `RegistrarEvento` llama para todo `reproduccion.*` (bloqueantes en el paso 8, advertencias en el 10): una sola puerta, sin duplicar reglas.
- Servicio natural: `fin ≥ inicio` (422), al menos una hembra entre los sujetos, toros (`toro_ids`) del establecimiento, machos enteros y de la
  especie de las hembras; `lote_toros_id` existente y de la especie; toros externos como texto. IA/IATF: `semen.toro_id` macho de la especie,
  `semen.raza` del catálogo de la especie, inseminador/centro/partida como texto; IA en macho bloquea por `requiere_sexo` (dato).
- Trampa: un lote de servicio (M6) se expande con los toros adentro; el proyector solo marca «servida» a las hembras (antes el toro quedaba «servido»).
- Advertencias: `servicio_en_prenada` (la FPP nueva reemplaza a la del tacto) y `toro_no_activo`; en la web piden confirmación, por sync quedan como observación.
- Verificado: 5 tests (servicio natural con toro adentro y FPP Murrah desde el inicio, IA/IATF bovina 283 d y semen propio, 10 reglas bloqueantes/422,
  advertencia + confirmación, 403 lectura / 404 ajeno). Suite completa verde.

## TPA-28 · Tacto y fecha probable de parto · ✅ (parte API)

**Endpoints:** vía `POST …/eventos` / push (`reproduccion.tacto`); en la ficha `estado_reproductivo`, `fecha_probable_parto`, `fpp_precision`
y los **campos aditivos** `tacto_fecha` y `dias_gestacion` (hoy − concepción, calculado al leer) en `Animal` (ya en el YAML).

**Comentario del dev:**
- La FPP ya la calculaba el proyector de TPA-22 con `gestacion_media_dias` resuelto por especie/raza/establecimiento a la fecha del tacto
  (dato: Murrah 303, Mediterránea 313, Jafarabadi 330, búfalo sin raza 310, bovino 283); un override del establecimiento por
  `PUT …/parametros/gestacion_media_dias` cambia los tactos siguientes. Lo que faltaba eran las reglas (en `ReglasReproductivas`).
- Preñada exige `dias_gestacion` o `tamano` (422); días > `gestacion_max_dias` de la especie bloquea (bovino 295, búfalo 335);
  días > media advierte `fpp_pasada`. Vacía limpia FPP y días.
- Decisión: un tacto preñada **solo por tamaño** confirma la preñez pero no inventa días (no hay tabla tamaño→días validada): conserva la
  FPP del servicio/IA o del tacto anterior con días. Queda como dato a cargar si un vet la define.
- Arreglo pedido por la app: el resumen del tacto decía «Prenada»; ahora «Preñada (N días)» / «Preñada (chica)» / «Vacía» (aserción en el test).
- Modo manga = app (búsqueda por identificador + tactos en lote por `POST /sync/push`, misma `RegistrarEvento`); no requiere endpoint nuevo.
- Verificado: 5 tests (FPP de las 5 gestaciones + ficha con `dias_gestacion` + timeline, override del establecimiento, preñada sin días 422 /
  tamaño conserva la FPP de la IA, máximo por especie y advertencia, tacto en macho / 403 / 404). Suite completa verde.

## TPA-29 · Parto, nacimiento, aborto y destete · ✅ (parte API)

**Endpoints:** vía `POST …/eventos` / push (`reproduccion.parto`, `hacienda.nacimiento`, `reproduccion.aborto`, `reproduccion.destete`);
**campos aditivos** en `Animal`: `dias_posparto` y `destete {fecha, peso_kg}` (ya en el YAML); crías en `GET …/animales/{a}/genealogia`.

**Comentario del dev:**
- El parto ya creaba las crías vivas con `madre_id` = sujeto (TPA-22). Se agregaron reglas en `ReglasReproductivas`: hasta 3 crías (422),
  cría viva con categoría de cría (`categorias.es_cria`, dato), `madre_id` declarado = la hembra del parto; especie de la cría = madre (ya existía).
- Gestación mínima por especie/raza (dato `gestacion_min_dias`): el parto **advierte** `gestacion_corta` si desde la concepción (tacto con días o
  servicio/IA; inicio del servicio natural) pasaron menos de mínimo − 30 d (modelo-datos §3.3: búfala 265, bovino 240), y `parto_reciente` si
  el parto anterior está más cerca que eso. Web → `422 requiere_confirmacion`; sync → observación. En correcciones no se compara.
- Aborto → vacía y sin FPP (ya existía); advierte `aborto_sin_prenez` y bloquea días > máxima. Destete: `destetado` + fecha + peso; con
  `categoria_nueva` recategoriza en el acto (no puede ser categoría de cría); **sin ella queda la sugerencia** de TPA-25 (motivo destete).
  Decisión: no se recategoriza en la proyección sin evento (rompería la confirmación de TPA-25 y las 8 sugerencias del demo). Lote anónimo:
  el conteo pasa a la categoría nueva (un destete por sexo, si no 422). Advierte `ya_destetado`.
- Trampa (bug de TPA-22): un parto con cría muerta (sin `animal`) rompía la proyección (uuid `''` en el `NOT IN` de la madre): corregido.
- Pendiente fuera de alcance: GDP predestete (no se guarda el peso al nacer aparte del primer peso), padre de la cría desde el servicio.
- Verificado: 7 tests (mellizos + muerta con madre/peso/existencias/genealogía/días posparto, 5 rechazos del parto, gestación corta y parto
  reciente con confirmación + bovino sin aviso, aborto, destete individual con/sin categoría, destete de lote anónimo, 403/404). Suite verde.

## TPA-30 · Tablero de vientres y calendario de partos · ✅ (parte API)

**Endpoints:** `GET …/reproduccion/tablero?especie` (TableroVientres[]), `GET …/reproduccion/partos?desde&hasta&especie&vencidos` (PartoCalendario[]).

**Comentario del dev:**
- Lecturas sobre `animales_estado` en `App\Domain\Reproduccion\TableroReproductivo` (controller fino + `ReproduccionRequest`, que autoriza
  `reproduccion.ver` **antes** de validar: un ajeno recibe 404 aunque mande filtros inválidos). Vientre = hembra activa con categoría de
  vientre (`categorias.es_vientre`, dato) o con estado reproductivo; una por especie del establecimiento (o la filtrada).
- Tablero: vacías, servidas, preñadas, paridas y preñadas por mes de FPP. **Desvío aditivo** (en el YAML): `sin_estado` (vientres sin
  eventos reproductivos, para que los estados cierren con el total), `partos_proximos` y `partos_vencidos` (la alerta, lista para Inicio/TPA-53).
- Calendario: servidas y preñadas con FPP, por fecha, con `fpp_precision`, `dias_para_parto` (negativo si pasó) y `vencido` = FPP +
  `parto_vencido_tolerancia_dias` (15, parámetro por especie/raza/establecimiento) < hoy sin parto registrado; `vencidos=true|false` filtra.
  «Hoy» es el de la zona horaria del establecimiento. La agrupación por semana la hace el cliente. El push/email de la alerta queda para TPA-53.
- Demo: 2 vacas Braford individualizadas del rodeo (anónimo 619 → 617, total BOV sigue 1.240): 0904 con IATF (servida) y 0731 con el parto
  vencido hace 20 días (relativo a la carga). U-0142, las 4 búfalas ≤ 7 días y las 8 sugerencias no cambian; el test del demo ahora también
  verifica tablero y calendario (115 eventos).
- Verificado: 4 tests (tablero BUB/BOV con mes de parto y alertas, calendario orden/días/vencido/filtros y parto registrado sale, tolerancia
  por parámetro del establecimiento, 422 ×4 / lectura ve / revocada 403 / ajeno 404) + demo. Suite completa verde.

## TPA-31 · Catálogo de productos sanitarios · ✅ (parte API)

**Endpoints:** `GET|POST …/productos-sanitarios` (`?archivados&tipo&especie`), `PATCH …/productos-sanitarios/{p}`,
`POST …/productos-sanitarios/{p}/archivar`, `GET /catalogos/productos-sugeridos?especie`.

**Comentario del dev:**
- Tabla `productos_sanitarios` (maestro: id del cliente idempotente, `row_version`/409, archivado en vez de borrado, nombre único
  case-insensitive entre activos, CHECK de tipo y retiros ≥ 0). `GuardarProductoSanitario` con auditoría y `cambios`: viaja por
  pull (`producto_sanitario`) y el snapshot ya lo trae (la app lo necesita offline para copiar el retiro al aplicar, TPA-32).
- **Sin dosis prescriptas:** `dosis_declarada` es texto libre del usuario. La base sugerida (`ProductosSugeridos`, genéricos por
  principio activo) no trae dosis **ni retiro**: el retiro depende del marbete del producto comprado; se copia con `sugerido_codigo`.
- Desvío aditivo: los sugeridos salen con `id: null` + `codigo`; filtro `especie` en el listado (incluye los sin especie declarada).
- Trampa: la autorización va en el FormRequest (`authorize()`) para que un no-miembro reciba 404 aunque mande un cuerpo inválido.
- Verificado: 3 tests (ciclo alta/idempotencia/filtros/409/archivo/pull/snapshot, sugeridos sin dosis, 422 ×6 + nombre repetido, 403 lectura, 404 ajeno).

## TPA-32 · Registrar aplicación sanitaria · ✅ (parte API)

**Endpoints:** vía `POST …/eventos` / `POST /sync/push` (`sanidad.vacunacion`, `sanidad.desparasitacion`, `sanidad.tratamiento`);
retiro en la ficha (`retiro_carne_hasta`/`retiro_leche_hasta`) y en el timeline (pestaña Sanidad).

**Comentario del dev:**
- Una sola puerta: `ReglasSanitarias` (nuevo, como `ReglasReproductivas`) lo llama `RegistrarEvento` para todo `sanidad.*`: producto del
  establecimiento (`dependencia_faltante`, reintentable), enfermedad del catálogo, y advertencias `retiro_menor_al_producto`,
  `envase_vencido`, `producto_otra_especie` (web → `422 requiere_confirmacion`; app → observación).
- «A lote en un paso» (web): `CompletarSobreWeb` expande un lote no anónimo a sus animales activos con `lote_id` de contexto (lo mismo que
  hace la app, sync §3.1.1) y copia al payload el **snapshot del producto** (nombre, principio activo, vía, retiros) **antes de hashear**;
  editar el producto después no cambia lo aplicado. Solo para sanidad: en hacienda un evento a lote tiene semántica propia (pesaje promedio).
- Potrero: el servidor resuelve los individuales presentes (propio o vía su lote) y los fija en `evento_animales` → el retiro llega a cada
  ficha. Lote anónimo: queda a nivel lote (el retiro por lote se calcula en TPA-35). Tratamiento: el retiro corre desde el último día.
- Cerrar la tarea del plan (`tarea_id`) llega con TPA-33 (las tareas todavía no existían). Por push el sobre no se toca (viene firmado).
- Verificado: 4 tests (lote expandido + retiro 35 d en 3 fichas + snapshot inmutable, potrero con individual y lote trasladado + tratamiento,
  3 advertencias + confirmación, 422 ×3 / 403 lectura / 404 ajeno).

## TPA-33 · Plan sanitario desde plantillas · ✅ (parte API) + seeder DEMO

**Endpoints:** `GET …/plan-sanitario`, `POST …/plan-sanitario/plantillas`, `POST|PATCH …/plan-sanitario/items[/{i}]`,
`POST …/plan-sanitario/items/{i}/archivar`, `GET|POST …/tareas` (`?estado=pendiente|vencida|hecha|omitida|abiertas&desde&hasta`, cursor),
`PATCH …/tareas/{t}`, `POST …/tareas/{t}/omitir`. **Comando:** `sanidad:generar-tareas` (diario 04:30 ART).

**Comentario del dev:**
- Tablas `planes_sanitarios` (aviso aceptado: quién/cuándo, obligatorio para aplicar plantillas: `aviso_aceptado: true` o 422),
  `plan_items` (copia de la plantilla con código/versión; `editado` si difiere) y `tareas` (`UNIQUE (plan_item_id, clave_ocurrencia)`).
  Plantillas = **sugerencia**: nunca dosis; ventanas/fechas de aftosa y rabia vienen sin fechas (`requiere_configuracion`) y se editan.
- `GeneradorTareas` (horizonte 90 d) **sincroniza** lo deseado: crea, actualiza fechas si se editó el ítem (salvo tareas `reprogramada`
  por el usuario) y omite con motivo las pendientes futuras que ya no corresponden (ítem archivado/inactivo, condición de zona que dejó
  de cumplirse, animal destetado/salido). Disparadores: fecha fija, ventana (MM-DD o meses), relativo a nacimiento (una por animal),
  periódico, refuerzo tras primera dosis (rabia 20–60 d) y repetición de ventana (pediculosis +15 d). Condición por zona con el DSL.
- Alcance resuelto con datos de `categorias` (`es_vientre`, `es_reproductor`, cría…) → `cabezas_estimadas` (individuales + anónimos).
- Cierre: un evento `sanidad.*` con `payload.tarea_id` la marca hecha dentro de `RegistrarEvento`; **anular** ese evento la reabre.
  Nacimiento/parto/alta/vacunación disparan la regeneración (job `afterCommit`). Tareas por pull (`tarea`) y abiertas en el snapshot.
- Trampa: las fechas se calculan como días «naive» de la zona del establecimiento (comparar Carbon en zonas distintas corría un día).
- Demo: 3 productos, 7 plantillas con aviso aceptado por el dueño, ventanas de aftosa de la zona; queda pendiente el refuerzo de rabia de
  los bucerros y la 2ª campaña del año omitida («aplicada el 02/10, cargada antes del plan»).
- Verificado: 4 tests (aviso/condiciones/ventanas/relativa por evento/zona/idempotencia/cierre y reapertura/snapshot; refuerzo + periódico
  hasta destete; reprogramar/409/omitir/filtros/archivo; ítem manual, 422 ×7, 403 lectura, 404 ajeno) + demo. Suite verde.

## TPA-34 · Pruebas diagnósticas y laboratorio · ✅ (parte API)

**Endpoints:** vía `POST …/eventos` / push (`sanidad.prueba_diagnostica`, `sanidad.analisis_laboratorio` con `adjuntos`); umbrales
editables por establecimiento con `PUT …/parametros/tuberculina_<tipo>_{negativo_lt|positivo_gte}_mm`; reactor en la ficha.

**Comentario del dev:**
- `ResultadoPrueba` (método `diferencia_mm` de `tipos_prueba`): Δ = 72 h − 0 h; negativo si Δ < umbral, positivo si Δ ≥ umbral, si no
  sospechoso (cervical simple 3/3 → sin sospechoso). Umbrales = **parámetros por especie** (seed BOV/BUB iguales, `validado_por_vet=false`;
  duda 16) con respaldo en `tipos_prueba.umbrales`. Aritmética con `bcmath` (decimales string, sin floats).
- Web: con las dos lecturas y sin resultado, el servidor lo calcula antes de hashear. `calculado` incoherente → 422 en la web; por sync se
  acepta con revisión `resultado_prueba_discrepante` (umbrales cambiados offline; migración aditiva que amplía el CHECK de `revisiones`).
- Positivo → reactor (proyector de TPA-22) con confirmación previa en la web (`marca_reactor`, duda 16). Lecturas → un solo animal;
  prueba fuera de catálogo o de otra especie → 422. `ContextoEvento` ahora sabe el canal (`web`/`push`) para reglas así.
- Análisis (coprológico, IFD, MAT, serología…) con `resultado_texto` y archivo en `adjuntos` (SHA-256; queda `pendiente` hasta que la
  subida diferida lo complete, TPA-44). `documento_ids` se valida cuando exista la carpeta documental (TPA-36).
- Verificado: 4 tests (anocaudal negativo/sospechoso/positivo→reactor + override por especie del establecimiento, cervical simple,
  declarado≠calculado, calculado incoherente/sin lecturas/prueba inexistente/varios animales, análisis con adjunto, 403/404, push con revisión).

## TPA-35 · Retiro vigente y reactores · ✅ (parte API)

**Endpoints:** `GET …/sanidad/retiros?especie` (AnimalResumen[] + aditivo `lotes[]`), `GET …/sanidad/reactores?especie`;
aditivo `retiro_carne_hasta`/`retiro_leche_hasta` en `Lote`.

**Comentario del dev:**
- Retiro individual ya proyectado (TPA-22/32); lo nuevo es el **lote anónimo**: `Retiros::deLote` lo deriva al leer de sus aplicaciones
  vigentes (`sujeto_tipo = lote`, fecha local + duración del tratamiento + días del payload). Vence solo: «hoy» de la zona del establecimiento.
- Venta: `hacienda.egreso` advierte `reactor` (salvo destino faena, que es la salida sugerida) y `retiro_vigente` también para lotes
  anónimos; web → `422 requiere_confirmacion`, app → observación. Ofertas desde lote anónimo con retiro → aviso `retiro_vigente`.
  La operación con trato (TPA-46) reutiliza los mismos chequeos.
- Privacidad: listas solo para miembros (`sanidad.ver`); perfil y ofertas públicas siguen con su whitelist (reactor nunca sale; el retiro
  solo como hecho agregado si el productor muestra el bloque sanidad). Autoriza antes de validar (ajeno → 404 con filtro inválido).
- Pendiente (resuelto en el bugfix de abajo): animales individualizados desde un lote anónimo no heredaban el retiro del lote.
- Verificado: 3 tests (listas por especie + lote + vencimiento al pasar la fecha, advertencias de venta/faena/lote/oferta, 422/lectura/404).

### TPA-35 · bugfix: herencia del retiro al individualizar y potrero → lotes anónimos · ✅

**Comentario del dev:**
- Individualizar: `ProyectorAnimal` arranca al animal con el retiro del lote de origen (`Retiros::deLote` con tope = instante de la
  individualización, inclusive: ante la duda, conservador); las aplicaciones propias posteriores lo extienden con `max`. Reconstruir da lo mismo.
- Potrero: tabla nueva `evento_lotes` (gemela append-only de `evento_animales`, con trigger): al registrar una aplicación a un potrero,
  `ReglasSanitarias` fija también los lotes anónimos presentes con cabezas. `Retiros::deLote`, el timeline del lote y la historia heredada del
  animal la incluyen; el lote recibe su `cambio` para el pull. El sobre no se toca (se resuelve al registrar, igual que los animales).
- Decisión: la resolución es la foto del momento de registrar (como en TPA-32); un lote que llega al potrero después no hereda. Base de dev: solo `migrate`.
- Verificado: 2 tests nuevos (herencia 16/10 en ficha, lista de retiros y venta con advertencia; individualización previa sin herencia; reconstrucción;
  vencimiento; potrero → lote presente 31/10 y el de otro potrero intacto, timeline del lote, herencia por individualización, append-only, 403 lectura,
  422 payload, 404 ajeno) + suite verde.

## TPA-36 · Carpeta documental con versiones y vencimientos · ✅ (parte API)

**Endpoints:** `GET|POST …/documentos` (`?tipo&privacidad&vinculo_tipo&vinculo_id&vence_antes&visibilidad&estado&q`, cursor),
`GET …/documentos/vencimientos?dias`, `GET …/documentos/{d}`, `POST …/documentos/{d}/versiones`, `POST …/documentos/{d}/visibilidad`;
push `clase: documento_version`; `GET …/animales/{a}/documentos` ya real; sección `documentos` del perfil público.

**Comentario del dev:**
- Tablas `documentos` (cabecera = proyección de la vigente + visibilidad), `documento_versiones` y `documento_version_archivos` (**append-only**
  con `tropilla_inmutable`), `documento_vinculos`. CHECK fiscal ⇒ privado + trigger que impide publicar tipos no publicables. Archivos
  direccionados por SHA-256 (`archivos/sha256/aa/bb/<hash>`, nunca se sobrescriben); una referencia sin bytes queda `pendiente` (TPA-44).
- Una sola puerta `RegistrarDocumentoVersion` (web arma el sobre `tropilla.documento_version/1`; app lo manda firmado): huella, idempotencia
  por id, `hash_anterior` = huella de la versión anterior, eslabón en la cadena del establecimiento, vínculos existentes (`dependencia_faltante`),
  conflicto de versión → `en_revision` + revisión `documento_en_conflicto` (web: 409). Nueva versión / corrección / revocación nunca borran.
- Permisos: fiscal solo dueño/admin (crear, ver, versionar; un tipo fiscal es siempre fiscal); encargado/asesor versionan solo lo que cargaron.
  Publicar: `no_publicable` para fiscal/no publicable; la visibilidad no es evidencia (auditada, no va al sobre). Revocar despublica.
- Verificación: `/public/verificaciones/archivo/{sha}` ahora coincide con documentos **publicados**; `/public/verificaciones/{huella}` describe
  la versión sin revelar el título si es privada. Pull `documento` (comercial/fiscal según rol) y snapshot con versiones.
- Las alertas 30/7/0 días se emiten en TPA-53 sobre `…/documentos/vencimientos`.
- Verificado: 5 tests (alta con 2 archivos y vínculos, huella desde el sobre guardado, v2/corrección/revocación, append-only, cadena verificada;
  fiscal/publicación/verificación por archivo/encargado; vencimientos y 8 filtros; push idempotente + conflicto + 2 rechazos + pull/snapshot; 422/403/404).

## TPA-44 · Subida diferida de fotos y archivos · ✅ (parte API)

**Endpoints:** `POST /archivos/subidas`, `POST /archivos/subidas/{s}/completar`, `GET /archivos/{sha256}` (302 a URL firmada 5 min),
`GET /archivos/{sha256}/estado`; dev/tests: `PUT /archivos/subidas/{s}/partes/{n}` (ruta firmada). **Comando:** `archivos:expirar-subidas` (horario).

**Comentario del dev:**
- El teléfono sube **directo al almacén** con URLs prefirmadas (la API no pasa bytes): simple < 8 MiB, si no multipart de 5 MiB; la cola de
  archivos de la app es independiente de la de eventos (el sobre referencia el SHA-256 y el archivo queda `pendiente` hasta completar).
  Dedupe por hash: si ya está `completo` responde 200 y no se sube. Reintento de una parte = volver a hacer PUT; de la subida = iniciar otra.
- Completar **verifica SHA-256 y bytes** del objeto; si no coincide se borra (la clave es el hash, nunca puede quedar otro contenido) →
  422 `archivo_corrupto`. Vencidas a las 24 h: el job aborta el multipart.
- Interfaz `AlmacenObjetos`: `S3Almacen` (MinIO/S3/R2; presigna con un cliente apuntado a `AWS_PUBLIC_ENDPOINT` porque SigV4 firma el host
  — `minio:9000` no es alcanzable desde el teléfono) y `LocalAlmacen` (URLs firmadas de la API; tests y dev sin MinIO).
- Descarga solo si el usuario ve algo que referencia el archivo (versión de documento según fiscal/rol, adjunto de evento, planilla, o lo subió él);
  si no 404. Contrato de la app respetado (`subida_id`, `modo`, `url`, `parte_bytes`, `partes[{numero,url}]`, completar con `{partes:[{numero,etag}]}`).
- Verificado: 3 tests (simple + dedupe + descarga; multipart 4 partes con reintento de una parte, partes faltantes 422, corrupto descartado;
  firma obligatoria, vencimiento, 422/403/404) + **smoke real contra MinIO**: multipart 12 MB y simple con PUT prefirmado, verificación y descarga.

## TPA-38 · Compartir documento por enlace temporal · ✅ (parte API)

**Endpoints:** `GET|POST …/documentos/{d}/enlaces`, `POST …/documentos/{d}/enlaces/{e}/revocar` (204), público `GET /public/enlaces/{token}?locale`.

**Comentario del dev:**
- Tablas `enlaces_compartidos` (token de 256 bits solo en la URL `{web}/e/{token}`; se guarda su SHA-256; vencimiento obligatorio ≤ 90 días,
  revocable, opcionalmente fijo a una versión) y `enlace_accesos` (append-only, IP como HMAC). Compartir no publica: el documento sigue `privado`
  y la cabecera muestra `enlaces_activos`.
- Público: documento con **confianza** y `verificacion_url` (huella de la versión), archivos con URL firmada de 5 min, nombre del establecimiento
  y leyenda (declarado + integridad); `Cache-Control: private, no-store` y `noindex`. Vencido o revocado → 410 `enlace_vencido`; token desconocido → 404.
- Fiscal: se puede compartir (dueño/admin, `documentos.compartir`) con `aviso` en la respuesta; nunca publicar (TPA-36). Enlace a versión fija
  muestra `estado: reemplazado` si ya hay una vigente más nueva. Auditoría de crear/revocar.
- Verificado: 3 tests (crear → abrir sin sesión con confianza/verificación/archivo → acceso contado → vencimiento 410; versión fija, revocación,
  token inexistente, fiscal con aviso, 4 validaciones; encargado 403, admin puede, ajeno 404).

## TPA-40 · Anclaje diario de integridad · ✅

**Comandos:** `integridad:anclar` (diario 03:00 ART), `integridad:actualizar-anclajes` (cada 6 h). **Endpoints:** `IntegridadRegistro.anclaje`
y `.prueba` ya completos (`…/eventos/{e}/integridad`, `/public/verificaciones/{huella}[/prueba]`); aditivo `GET /public/anclajes/{a}/recibo` (.ots);
pull `entidad: anclaje` (estado + `evento_ids` del establecimiento).

**Comentario del dev:**
- Tablas `anclajes` y `anclaje_inclusiones` (append-only). El job toma los registros sin inclusión en un anclaje vivo (≤ 2^20), arma el Merkle
  RFC 6962 sobre los eslabones y guarda **una prueba por registro**, todo en una transacción; después sella. `Merkle::arbol` calcula raíz + todas
  las pruebas en O(n log n) (bottom-up, el impar sube sin hermano) y da idéntico a la recursión de referencia (cotejado n = 1..70).
- `AnchorDriver`: `StubDriver` (confirma al instante; **con stub en producción la app no arranca**) y `OpenTimestampsDriver` (digest a ≥ 2
  calendarios, `.ots` propio con una rama por calendario; `actualizar` pide la versión de cada compromiso pendiente y confirma con la altura
  del bloque de Bitcoin). Codec `.ots` propio (`Ots`): **el archivo generado lo lee la librería de referencia `opentimestamps`** (smoke real
  contra a/b.pool.opentimestamps.org y eternitywall: 3 atestaciones pendientes). Polygon: no implementado (fase posterior).
- Recibos como objetos nuevos (`anclajes/{id}.ots`, `.v2.ots`…; nada se pisa). 5 fallos de sello → `fallido` + log critical, y esos registros
  se re-anclan en el próximo lote. Al confirmar: «Integridad registrada» (derivado) y un cambio `anclaje` por establecimiento afectado.
- Verificación de archivo (§6.5): coincide también con documentos compartidos por **enlace vigente**, no solo publicados.
- Verificado: 4 tests (stub: 5 eventos → raíz = referencia, todas las pruebas verifican, confianza, prueba.json recalculable, recibo, pull,
  incremental, append-only; fallido ×5 y re-anclaje; OpenTimestamps con calendarios falsos verificando el commitment calculado y la
  confirmación en v2; stub + producción no arranca) + smoke real contra los calendarios públicos.

## TPA-45 · Simulador de liquidación en pie vs al gancho · ✅ (parte API)

**Endpoints:** `POST …/simulaciones/calcular` (sin guardar), `GET|POST …/simulaciones` (cursor; guardadas inmutables). Ability `comercial.precios`.

**Comentario del dev:**
- `SimuladorLiquidacion` con **fórmula explícita** que también viaja en la respuesta (`formula`). Decisión de la duda 4: desbaste **solo en pie**,
  rendimiento al gancho **sobre el bruto** (desvío respecto de modelo-datos §9.1, que lo aplicaba sobre el neto: ≈ $1,5 M de diferencia en 30
  bubillos), flete/arranque/seguro/comisión **del vendedor** en las dos, IVA 10,5/21 % informativo. Si mandan desbaste al gancho se ignora.
- Defaults por especie como datos: rendimiento `rendimiento_gancho_ref_pct` (BUB 53 = NEA, BOV 55) e IVA `iva_hacienda_pct`. Todo con `bcmath`
  (decimales string, sin floats), redondeo half-up: dinero a 2, kg a 1. `conviene` + `diferencia_neto` cuando hay ambas modalidades.
- `simulaciones` privadas e inmutables (trigger); calcular no guarda («usable sin registrar una venta»).
- Verificado: 2 tests (caso 30 bubillos 14.040 kg con todas las líneas a mano, una sola modalidad + IVA 21 + desbaste ignorado + default BOV,
  guardar/listar/inmutable, 422 ×7, 403 encargado/asesor/lectura, 404 ajeno).

## TPA-46 · Registrar venta con condiciones del trato · ✅ (parte API)

**Endpoints:** `GET|POST …/operaciones` (`?especie&categoria&tipo&desde&hasta`, cursor), `GET …/operaciones/{o}`,
`POST …/operaciones/{o}/correcciones`, `POST …/operaciones/{o}/anulaciones`. Ability `comercial.precios` (dueño/admin).

**Comentario del dev:**
- Tablas `operaciones` (cabecera = proyección para «Mis precios»), `operacion_versiones` (**append-only**, sobre `tropilla.operacion_version/1`
  hasheado y encadenado con `hash_anterior`), `operacion_animales` (aditivo) y `precios_referencia` (para TPA-47). Alta/corrección/anulación =
  versiones nuevas; nada se borra. `integridad:verificar-cadenas` re-hashea también estas versiones.
- Liquidación con la **misma fórmula del simulador** sobre los valores reales; si el flete es del comprador no se descuenta. Trato completo
  (comprador, destino del catálogo, lugar/hora de pesada, modalidad, desbaste, tolerancias, plazo, flete, comisión, intermediario) y
  trazabilidad declarada (DT-e, tropa, RENSPA origen/destino).
- **PDF del trato** con Gotenberg (plantilla `pdf/trato`) → archivo por SHA-256 → Documento `resumen_trato` (comercial, vinculado a la operación)
  por la puerta única de documentos; su hash va dentro del sobre de la versión. Corrección → nueva versión del documento; anulación → revocación.
  Si Gotenberg no responde, la operación se registra igual sin PDF (log). Smoke real contra el Gotenberg del stack.
- Compromiso: los animales del trato quedan **vendido** (aunque no hayan salido); en contrato a futuro o ya vendidos → 409 `comprometido`;
  la oferta de origen pasa a vendida; retiro vigente/reactor → `422 requiere_confirmacion` (TPA-35). Anular libera el compromiso.
- Precios siempre privados: no hay `cambios` de operaciones y el resumen PDF (comercial) no baja por sync a encargado/asesor.
- Verificado: 4 tests (advertencia + alta con todas las líneas + PDF/Documento/huella + vendido + 409 + cadena; corrección encadenada con flete
  del comprador + anulación que revoca y libera; sin Gotenberg + al gancho 53 %; 422 ×8, 403 encargado/asesor, sync sin operaciones, 404).

## TPA-47 · Historial de precios propio · ✅ (parte API)

**Endpoints:** `GET …/precios/historial?especie&categoria&desde&hasta&referencia`, `GET|POST …/precios-referencia` (cursor). Solo dueño/admin.

**Comentario del dev:**
- Puntos = ventas **registradas** (las anuladas no cuentan) agrupadas por especie y categoría, con precio, modalidad, referencia y diferencial %
  = (precio − referencia) / referencia × 100 (bcmath, 2 decimales) + promedio por grupo.
- Referencia por punto: la elegida en la operación; si no, la última **declarada** a esa fecha de la misma especie (misma categoría primero) y la
  **misma modalidad** (en pie y gancho no se comparan → `null`). `?referencia=<descripción>` compara contra una serie de otra especie: el búfalo
  contra el «novillo overo» (duda 10 de Claude Design: no hay referencia pública bubalina).
- `precios_referencia` inmutables (trigger; una nueva por fecha), fecha no futura. Nada de esto es público ni viaja por sync.
- Verificado: 2 tests (+5 / −2 / null al gancho / −11,54 overo, promedio 1,5, búfalo vs overo −5,77, referencia elegida +10, anulada fuera,
  listado de referencias; 422 ×5, inmutable, admin ve, encargado/asesor/lectura 403, 404 ajeno).

## TPA-52 · Inicio con existencias, pendientes y actividad · ✅ (parte API)

**Endpoints:** `GET /establecimientos/{e}/inicio?especie` (Inicio: `existencias[]`, `pendientes{…, items[]}`, `actividad[]`). Ability `establecimiento.ver`.

**Comentario del dev:**
- Existencias = proyección `existencias` por especie y categoría (individuales + anónimas). **Variación del mes** por flujos del mes: nacimientos,
  partos e ingresos suman; muertes, egresos, faenas y pérdidas restan (individuales con su categoría al darse de baja; lotes por conteo); los
  movimientos de categoría de lotes anónimos pasan de una a otra. Decisión: las altas de inventario/importación/alta rápida **no** son variación
  (es hacienda que ya estaba). Categorías que se vaciaron en el mes aparecen con 0 cabezas y su variación.
- Pendientes de la semana: tareas del plan (pendientes y vencidas, TPA-33), partos próximos/vencidos (`TableroReproductivo`, TPA-30),
  recategorizaciones sugeridas, documentos que vencen en 30 días (fiscales solo con `documentos.fiscal`), consultas nuevas (solo dueño/admin),
  anticipos por confirmar (cuando existan tomas, TPA-57) y revisiones abiertas; con `items[]` navegables (tipo, título, fecha, ruta, entidad).
- Actividad: últimos 10 eventos por fecha de **carga**, con autor y rol. El estado de sincronización lo arma la app con su outbox (el contrato
  que ya consume `inicio_datos.dart`: `existencias[].variacion_mes`, `pendientes.tareas_sanitarias`, `consultas_nuevas`).
- Verificado: 2 tests (variación +2/−7/+4/−1/0 con stock inicial excluido, 6 contadores + ítems, actividad con autor, filtro por especie;
  encargado sin consultas, 422, 404 ajeno) + chequeo sobre el demo de dev (1.240 / 380, 4 partos próximos, 8 sugerencias, 2 consultas).

## TPA-53 · Alertas y notificaciones · ✅ (parte API)

**Endpoints:** `GET /me/notificaciones?no_leidas` (cursor + `meta.no_leidas`), `POST /me/notificaciones/{n}/leer` (204),
`PUT /me/preferencias-notificacion` (204), aditivo `GET /me/preferencias-notificacion`. **Comando:** `alertas:generar` (diario 07:00 ART).

**Comentario del dev:**
- Tablas `notificaciones` (in-app siempre; `clave_dedupe` única por usuario: el mismo aviso no se repite) y `preferencias_notificacion`
  (por usuario, establecimiento opcional y tipo; defaults push sí / email no / resumen no).
- `GeneradorAlertas` por rol (functional/04 §11): tarea próxima ≤ 3 d / vencida y refuerzo (dueño, encargado, asesor), documento a 30/7/0 d
  o vencido (dueño, admin; clave por versión), parto próximo (`parto_proximo_aviso_dias`) / vencido (encargado, asesor), recategorizaciones
  (encargado), entrega de contrato a 15 d (cuando existan contratos). Consulta nueva: aviso inmediato desde `RecibirConsultaPublica` (sin datos
  del comprador). Resumen diario por email con lo de las últimas 24 h de los tipos que el usuario activó.
- Push detrás de `PushDriver`: `LogPushDriver` (local/testing) y `FcmPushDriver` (FCM HTTP v1, JWT RS256 de la cuenta de servicio → access token
  cacheado; `UNREGISTERED` borra el `fcm_token` del dispositivo). Sin SDK. Config `TROPILLA_PUSH_DRIVER`, `TROPILLA_FCM_CREDENCIALES`.
- Huecos: «sin sincronizar > 48 h» es local de la app (el servidor no ve la outbox); «apta para servicio» y «peso de 1er servicio» quedan para F2
  (falta el parámetro de edad objetivo).
- Verificado: 3 tests (alertas por rol + push solo al dispositivo del encargado + dedupe + vencida al pasar la fecha; preferencias push off +
  resumen por email + bandeja/leída/ajena/422/404; FCM con JWT firmado y token inválido).

## TPA-56 · Contrato a futuro con token (productor) · ✅ (parte API) + seeder DEMO

**Endpoints:** `POST …/contratos/calcular`, `GET|POST …/contratos`, `GET …/contratos/{c}`, `GET …/contratos/{c}/preview`, `POST …/contratos/{c}/publicar`,
`POST …/contratos/{c}/terminos[?simular=true]`, `POST …/contratos/{c}/cancelar|incumplido`, `POST …/contratos/{c}/tomas/{t}/confirmar-pago|devolucion-informada`,
`POST …/contratos/{c}/entrega[/confirmar]`, `GET …/apalancamiento`; públicos `GET /public/contratos/{codigo}` y `…/establecimientos/{slug}/contratos`
(ya reales), filtro `con_contrato` del directorio y consultas con `contrato_codigo`.

**Comentario del dev:**
- Tablas `contratos_futuro` (cabecera con CHECK `tokens_comprometidos ≤ tokens_totales` y motivo obligatorio en cancelado/incumplido),
  `contrato_versiones` y `toma_movimientos` (**append-only**, sobres `tropilla.contrato_version/1` y `tropilla.toma_movimiento/1` hasheados y
  encadenados), `contrato_animales` (compromiso «en contrato»), `tomas` y `entregas` (sobre `tropilla.entrega/1` al liquidar).
- Cálculo (`Terminos`) exacto del modelo: kg al gancho = floor(cab × peso × rend), tokens de 100 kg hacia abajo (el resto queda del productor),
  precio especial y anticipo por token redondeados a 2; defaults como parámetros. Ejemplo del modelo verificado: 40 × 480 × 53 % = 10.176 kg → 101
  tokens, $4.784, $143.520/token. Borrador editable; publicar = versión 1 con huella + PDF (Gotenberg) a Documentos.
- **Compromiso**: publicar exige la hacienda libre (individuales sin oferta/contrato/venta + cabezas anónimas no comprometidas) → si no 409
  `comprometido`; cancelar/incumplido liberan. Todo bajo `SELECT … FOR UPDATE` del contrato.
- **Mortalidad/faltante**: nueva versión con motivo → primero se liberan reservas no confirmadas (de la más nueva), después recorte prorrata de las
  confirmadas con reparto del resto por mayor fracción y anticipo a devolver; `?simular=true` muestra el plan. Trampa: el recorte va **antes** de
  bajar los totales (si no, viola el CHECK). Con tomas, la economía del trato queda fija (422).
- **Entrega**: factor = kg reales / contratados; dentro de ±5 % liquida por toma (kg efectivos, importe, anticipo neto, saldo); fuera → `a_resolver`.
  Confirman productor y compradores → `liquidado`, sobre de entrega, operación comercial (venta al gancho, PDF del trato). Apalancamiento: cobrados,
  pendientes, kg comprometidos y % tomado. Asesor ve contratos sin compradores ni montos. Precios públicos solo para compradores con cuenta (TPA-57).
- Demo: contrato publicado sobre las 60 bubillas (143 tokens).
- Verificado: 4 tests (cálculo del modelo, borrador → publicar con huella/PDF, precios ocultos sin cuenta, cadena; tomas ≤ libres, propio productor no,
  «recibí», apalancamiento, economía fija 422, recorte 60/30 → 33/17 con $3.875.040 y $1.865.760 a devolver, devolución informada; entrega a resolver y
  dentro de tolerancia → liquidación exacta → operación; compromiso 409, cancelar devuelve y libera, 422, asesor sin montos, encargado 403, 404) + demo.

## TPA-57 · Cuenta de comprador y toma de tokens · ✅ (parte API) + seeder DEMO

**Endpoints:** `GET|PUT /me/comprador`, `POST /contratos/{codigo}/tomas` (Idempotency-Key), `GET /comprador/tomas?estado` (cursor; `vigentes` aditivo),
`POST /comprador/tomas/{t}/pago-informado|devolucion-confirmada`, `POST /comprador/tomas/{t}/entrega/confirmar`, `GET|POST /seguimientos`,
`DELETE /seguimientos/{slug}`. **Comando:** `contratos:liberar-tomas` (cada 15 min).

**Comentario del dev:**
- `compradores` (cuenta liviana 1:1: empresa, tipo, país, idiomas, volumen) y `seguimientos` (dejar de seguir = `dejado_en`, api-v1 A8). Con perfil de
  comprador y sesión, `/public/contratos/{codigo}` y la lista del perfil muestran precio especial y anticipo (excepción explícita); sin cuenta, null.
- Tomar: perfil + email verificado, ≤ libres bajo lock del contrato (409 `tokens_insuficientes`), nunca del propio establecimiento; `Idempotency-Key`
  por comprador. Flujo «pagué» (comprador) → «recibí» (productor, TPA-56); cada paso es un movimiento hasheado y encadenado y avisa a la otra parte.
- Vencimiento: reservas sin «recibí» pasadas `toma_vencimiento_horas` (48) → `liberada` por el sistema y los tokens vuelven a estar libres (aviso al
  comprador). Duda 7 (2 días hábiles) queda como parámetro del contrato; no se cuentan hábiles.
- «Mis contratos» = tomas con contrato (código, establecimiento, fecha de entrega, estado) y su propia línea de la liquidación; devolución de anticipo
  confirmada → `devuelta_parcial` (sigue confirmada por los tokens que le quedan: entra en la liquidación). El comprador confirma la entrega → liquidado.
- Seguir productores: aviso de **oferta** (primera publicación) y **contrato** nuevos a los seguidores (solo con perfil publicado).
- Demo: `comprador@tropilla.ar` / `tropilla-demo` (Frigorífico del Litoral) con 40 tokens confirmados del contrato de las bubillas y siguiendo a La Esperanza.
- Infra: la suite paralela agotaba `max_locks_per_transaction` (64) de Postgres; `compose.yaml` lo sube a 256 (aplica al recrear el contenedor) y,
  mientras tanto, se corre con `--processes=4`.
- Verificado: 3 tests (perfil → precio visible → toma 40 idempotente → 409 → pago → pendiente en Inicio → recibí → Mis contratos; liberación a las 48 h y
  tokens libres, recorte + devolución informada/confirmada, entrega confirmada por el comprador con liquidación exacta, ajeno 404; seguir/aviso de contrato
  nuevo/dejar, 404 sin perfil, 422 ×5) + demo.

## TPA-58 · «Busco» y perfil de profesional · ✅ (parte API)

**Endpoints:** `GET|POST /comprador/buscos`, `PATCH /comprador/buscos/{b}`, `POST /comprador/buscos/{b}/cerrar`, `GET /comprador/buscos/{b}/respuestas`,
`GET …/demandas` (cursor), `POST …/demandas/{b}/respuestas`, `GET /public/buscos?especie&provincia`, `GET|PUT /me/profesional`,
`GET /public/profesionales/{slug}`, `POST …/profesionales` (204). **Comando:** `busco:actualizar` (diario 06:00 ART).

**Comentario del dev:**
- Tablas `buscos` (categorías/razas `text[]` con GIN, zona jsonb, plazo, estado abierta/cerrada/vencida), `busco_respuestas`, `busco_coincidencias`
  (proyección) y `profesionales` + `profesional_establecimientos`.
- Coincidencias contra establecimientos con **perfil publicado**: especie y categoría con cabezas (existencias) obligatorias, zona por provincia/departamento
  (o cualquiera); puntaje 0–100. Se recalculan al publicar/editar/cerrar y cada día (existencias cambian; vencidos por plazo). Aviso `busco_coincide` una sola
  vez por establecimiento a dueño/admin.
- Duda 22 («Busco» anónimo necesita mensajería): el productor **responde dentro de TROPILLA** (mensaje + oferta propia opcional) y el comprador recibe el aviso
  sin exponer su contacto; el nombre/empresa solo se ven si eligió `mostrar_nombre`. Publicar exige perfil de comprador y email verificado.
- Profesional: matrícula **declarada** (la leyenda pública lo dice: no verificada), especialidades, zonas, idiomas, bio; público solo con email verificado.
  «Trabaja con X» solo si dueño/admin del establecimiento lo autoriza y el perfil del establecimiento está publicado.
- Trampa: el operador jsonb `?` choca con los placeholders de PDO → `jsonb_exists()`.
- Verificado: 3 tests (publicar → calza 100 / no calza por zona → aviso único → respuesta → lectura del comprador → público anónimo y con nombre → recálculo
  a 80 → cerrar → sin demandas; profesional público/privado + autorización + 403/422; 403 sin perfil comprador, 422 ×4, 403 encargado, 404 ajeno).

## TPA-51 · Organizaciones y adhesión con consentimiento · ✅ (parte API)

**Endpoints:** `GET /organizaciones?q&especie` (cursor), `GET /organizaciones/{o}`, `GET|POST /productores/{p}/adhesiones`,
`POST /productores/{p}/adhesiones/{a}/consentimiento`, `POST /productores/{p}/adhesiones/{a}/revocar`, `GET /organizaciones/{o}/adheridos` (padrón);
aditivos `POST /organizaciones` (solo staff) y `POST /organizaciones/{o}/miembros`.

**Comentario del dev:**
- Tablas `organizaciones` (alta controlada por el equipo en el MVP: `es_staff`), `organizacion_miembros` (admin/operador), `adhesiones` (proyección; única por
  productor + organización) y `consentimientos` (**append-only**). Cada otorgamiento, cambio o revocación es una versión con sobre `tropilla.consentimiento/1`
  (alcances, versión del texto legal, quién otorgó, `hash_anterior`) hasheada y encadenada en la **cadena del productor** (se crea la primera vez).
- Granular: existencias agregadas, cobertura sanitaria, lotes postulados, precios agregados — todo `false` por defecto. El padrón (solo admin/operador de la
  organización) devuelve únicamente lo consentido; revocar = deja de compartir datos nuevos y lo anterior queda con fecha (duda 26). Re-adherirse sigue la
  numeración de versiones. Lotes postulados y precios agregados quedan como alcance registrado sin datos (fase 2: sello/protocolos).
- Permisos a nivel productor: dueño/admin de **algún** establecimiento del productor (`organizaciones.adherir`, `consentimientos.gestionar`); sin membresía → 404.
- Trampa: jsonb reordena claves → los alcances se devuelven en el orden del contrato.
- Verificado: 2 tests (búsqueda, adhesión con huella recalculable desde el sobre y cadena del productor, 409, padrón con solo existencias, cambio → solo sanidad,
  revocación → fuera del padrón, re-adhesión v4, append-only, cadenas verificadas; encargado 403, ajeno 404, padrón ajeno 404, alta solo staff, 422, org inexistente 404).

## TPA-61 + TPA-62 · Borrador de documento desde foto offline + extracción de acta de vacunación · ✅ (parte API)

**Endpoints (aditivos):** `GET|POST /establecimientos/{e}/borradores`, `GET …/borradores/{b}`, `PATCH …/borradores/{b}/campos`,
`POST …/borradores/{b}/confirmar|reextraer|descartar`.

**Comentario del dev:**
- La foto se captura offline con el módulo de documentos existente (sobre `tropilla.documento_version/1` en la outbox + páginas en la cola de archivos por
  SHA-256, verificado en el servidor al completar la subida). El borrador referencia ese documento: la app lo encola con su UUIDv7 y lo manda después del push
  (idempotente por id; 409 `dependencia_faltante` reintentable si el documento todavía no llegó). Si falta alguna página queda `esperando_archivos` y el hook
  de `GestionarSubida::completar` lo pasa a `en_cola` solo.
- Tablas `borradores_documento` (cabecera + proyección de campos), `borrador_extracciones` y `borrador_correcciones` (**append-only**, trigger
  `tropilla_inmutable`): cada extracción guarda proveedor, modelo, esquema versionado, páginas, salida cruda, campos y advertencias; cada decisión humana
  guarda ruta, estado, valor anterior y nuevo, usuario y fecha.
- Proveedor detrás de `Extractor`: `ClaudeExtractor` (SDK oficial `anthropic-ai/sdk`, visión + `output_config.format` json_schema, modelo
  `claude-haiku-4-5-20251001` por defecto vía `TROPILLA_EXTRACCION_MODELO`, key `ANTHROPIC_API_KEY`), `FakeExtractor` (tests; prohibido en producción) y
  `ExtractorDeshabilitado` (sin key → `fallido` con mensaje, nunca datos inventados). Errores transitorios (429/5xx/red): 3 intentos con espera creciente.
- Familia `acta_vacunacion` (TPA-62) con dos esquemas: `acta_vacunacion.711_2025/1` y `acta_vacunacion.879_2002/1` (sin tipo de acto ni alcance). Extrae
  RENSPA, fecha, campaña, tipo/alcance, vacunas (enfermedad, marca, serie, vencimiento), categorías con existentes/vacunados aftosa/brucelosis, totales,
  ente/vacunador/matrícula, responsable/DNI y firmas detectables. La categoría del catálogo es **sugerencia** (`sugerido: true`), no lectura.
- Advertencias sin autocorregir: vacunados > existentes, totales que no cierran, existencias distintas a TROPILLA, RENSPA distinto/mal formado, fecha
  faltante/futura, firma no detectada, problemas de imagen, formulario no reconocido; `categoria_otra_especie` bloquea.
- Confirmar exige todo revisado (`revision_incompleta`) y `confirmar_advertencias`; crea un `sanidad.vacunacion` por vacuna por `ArmarSobreWeb` +
  `RegistrarEvento` (sujetos: animales activos de las categorías vacunadas en tandas de 1000 + lotes anónimos con esas categorías; adjuntos = páginas) y
  versiona el documento (`correccion_metadatos`) con vínculos a los eventos y fecha/emisor si faltaban. Alcance `cero` no crea eventos. Todo o nada, idempotente.
- Pendiente fuera de alcance: Anexo IV de la 711/2025 no se verificó casillero por casillero (investigación §4); el payload de `sanidad.vacunacion` no
  guarda cantidades por categoría (siguen en el borrador/documento).
- Verificado: 6 tests (flujo completo con subida diferida, 879 con advertencias, fallas/reintento/reextraer/filas nuevas/sin proveedor/descartar,
  permisos 403/404/409/422, normalización, request real del SDK con middleware).

## TPA-64 · Captura estructurada de DT-e y guías provinciales · ✅ (parte API)

**Real vs mock:** real. Reutiliza el pipeline de TPA-61/62 (borrador → extracción Gemini/Claude → revisión campo a campo → confirmación explícita);
los tests usan `FakeExtractor`. No hay integración con SIGSA: TROPILLA no emite ni valida DT-e/guías (no hay API pública de SENASA para eso).

**Comentario del dev:**
- Refactor sin cambio de contrato para el acta: `FamiliaBase` concentra el campo revisable, la interpretación sin autocorregir, la validación, el catálogo
  de categorías y la salida estructurada genérica (variante + problemas + escalares + filas). La familia decide qué hace la confirmación
  (`confirmacion()`: eventos con sujetos resueltos y/o eventos existentes a vincular) y qué advertencias bloquean (`bloqueantes()`).
- Familias nuevas `dte` (`dte.356_2008/1` DT-e de SIGSA, `dte.dta_historico/1` DTA en papel) y `guia_provincial` (`guia_provincial.dut_chaco/1`,
  `guia_provincial.generica/1` para otras provincias con `variante_no_verificada`). Extraen número, CUVE, fechas, motivo, origen/destino (RENSPA,
  titular, CUIT, establecimiento, localidad), transporte, precintos, requisitos sanitarios, FIDHA, intervenciones/talones del DUT y el detalle por
  especie/categoría/cantidad/marca. Tipo de documento nuevo `guia_provincial` (privado, comercial).
- Grupo «Movimiento en TROPILLA» (campos propios, se revisan como cualquier otro): sentido sugerido por RENSPA de origen/destino; `vincular` sugerido si
  un ingreso/egreso vigente tiene el mismo N.º de DT-e (comparado sin formato), si no `registrar`. Vincular no crea eventos: el documento se versiona
  con el vínculo y queda en `eventos_vinculados`. Registrar crea `hacienda.egreso` (conteo del lote anónimo o animales por caravana) o
  `hacienda.ingreso` (conteo a lote anónimo) con `dte_numero`, contraparte declarada y `documento_ids`.
- Conciliación sin autocorregir: suma del detalle vs total, cabezas por categoría vs el movimiento vinculado, N.º de DT-e distinto, sentido opuesto,
  RENSPA ajeno, vigencia inválida, DT-e anulado, conteo del lote insuficiente. Bloquean: lote/caravanas/movimiento sin elegir o ajenos, lote de otra
  especie o no anónimo para conteo, caravanas desconocidas, categoría de otra especie. Las advertencias se recalculan al confirmar.
- Migración aditiva `2026_10_21_100000_add_dte_guia_familias_to_borradores` (columna `eventos_vinculados`, CHECK de familias, tipo `guia_provincial`).
- Verificado: 4 tests nuevos (DT-e → egreso por conteo y documento versionado; DUT → vincula al egreso con el mismo DT-e sin crear eventos y variante
  genérica; egreso por caravanas e ingreso por conteo con bloqueos; validación, movimiento ajeno y 403) + los 13 de borradores; suite 311 verde, Pint.
- Pendiente: catálogo completo de campos de la pantalla SIGSA y ejemplares vigentes de guías de Corrientes, Formosa y Misiones (investigación,
  incertidumbres 2 y 3); validar contra fotos reales de DT-e/DUT.

## TPA-66 · Pruebas, certificados y estatus brucelosis/TBC · ✅ (parte API)

**Endpoints (aditivos):** `GET …/sanidad/oficial` (`?horizonte_dias`), `GET …/sanidad/oficial/resumen-veterinario` (`?formato=pdf|csv&meses`),
`GET|POST …/sanidad/protocolos`, `GET …/sanidad/protocolos/{p}`, `POST …/sanidad/protocolos/{p}/anular`, `POST …/sanidad/estatus`,
`POST …/sanidad/estatus/{e}/anular`, `POST …/sanidad/saneamiento`, `PATCH …/sanidad/saneamiento/{p}`, `GET …/animales/{a}/pruebas`;
familia de borrador `protocolo_sanitario`.

**Comentario del dev:**
- Tablas `protocolos_sanitarios` (laboratorio, protocolo, prueba, motivo DOES/recertificación/saneamiento/movimiento/rutina, modalidad total/muestreo,
  stock base, fechas, veterinario, vencimiento, documento original), `protocolo_resultados` (**append-only**: identificación tal como figura + animal
  asociado si coincide, muestra, lecturas en mm, resultado), `estatus_sanitarios` (historial por enfermedad: libre/en saneamiento/en certificación/sin
  estatus/suspendido, certificado, autoridad, vigencia; uno vigente, el nuevo reemplaza) y `planes_saneamiento` (acciones con responsable y fecha,
  próximo muestreo; uno activo, edición con `row_version`). `tipos_prueba.enfermedad` + pruebas SAT, 2-ME, ELISA y FC.
- **Transcripción vs condición oficial:** todo lleva `origen` = `transcripcion_revisada` (exige el original no fiscal; CHECK en la base) o `declarado`, con
  su leyenda y el aviso «no es la condición sanitaria oficial; la certifica SENASA (SIGSA)». Nada se presenta como certificado por TROPILLA.
- Registrar un protocolo crea `sanidad.prueba_diagnostica` por los resultados asociados (agrupados por resultado en tandas de 1000; con lecturas, uno por
  animal) → positivo = reactor por el proyector de siempre. Advertencias con confirmación: sin asociar, reactor, fecha futura, muestreo incompleto, vencido.
  Un protocolo no se edita: se anula (anula sus eventos; 409 si respalda el estatus vigente).
- Vencimientos como datos: `brucelosis_seronegatividad_validez_dias` (60, resultado para movimiento) y `sanidad_oficial_aviso_dias` (30), editables
  por establecimiento. `…/sanidad/oficial` junta estatus, plan, positivos de la enfermedad y vencimientos (estatus, movimiento, muestreo, acciones).
- Resumen para el veterinario: PDF (Gotenberg, plantilla `pdf/resumen-veterinario`; 503 si no responde) y CSV `;` con BOM; se genera a pedido, no se guarda.
- Foto → borrador (TPA-61/62 reutilizado): familia `protocolo_sanitario/1` (protocolo de laboratorio, planilla de sangrado o de tuberculinización).
  Sobre el refactor de TPA-64 (`Familia::confirmacion()` propone eventos sueltos), un protocolo es una entidad propia: `ConfirmarBorrador` delega
  en `FamiliaConRegistroPropio::registrar()` (misma transacción, mismo versionado del documento). La migración (`2026_10_21_120000`, después de la
  de TPA-64) rehace el CHECK de familias **a partir del vigente**, sin pisar las de otros tickets. Tipo de campo nuevo `decimal` (lecturas en mm).
- Smoke real con Gemini (`gemini-3.8-flash`) sobre un protocolo sintético de La Esperanza: leyó enfermedad, técnica, motivo, protocolo, fechas, RENSPA,
  vet/matrícula, firma, totales y las 4 filas; advirtió la caravana inexistente y el positivo; la confirmación dejó 3 asociados y 1 reactor. PDF real revisado.
- Verificado: 5 tests (protocolo con asociación/confirmación/reactor/vencimiento/idempotencia/anulación/append-only; tuberculina con lecturas y original;
  estatus/reemplazo/anulación, plan con 409 y cierre, vencimientos; CSV/PDF/503 y 403/404; foto → revisión → confirmación) + suite completa.

---

## TPA-67 · Resultado de faena y conciliación de venta · ✅ (parte API)

**Endpoints (aditivos):** `GET|POST …/faena/resultados` (`?operacion_id&borrador_id&estado`), `POST …/faena/planilla` (CSV/XLSX → reses para revisar),
`GET|PATCH …/faena/resultados/{r}`, `POST …/faena/resultados/{r}/anular`, `GET …/faena/resultados/{r}/exportar` (`?formato=pdf|csv`);
familia de borrador `resultado_faena`; tipos de documento `resultado_faena` y `liquidacion_hacienda`. Ability `comercial.precios` (dueño/admin).

**Comentario del dev:**
- Tablas `resultados_faena` (frigorífico, CUIT, faenador, fecha, tropa, romaneo, DT-e, cabezas, tipificador, `origen`, romaneo original, liquidación
  JSONB como la emitió la contraparte, documentos de liquidación y factura, venta **o** egreso vinculado, `row_version`, anulación con motivo) y
  `faena_reses` (**append-only** por trigger: garrón, RFID/caravana, animal asociado, medias A/B, peso, categoría, tipificación, decomiso).
- Tres entradas: carga a mano (`declarado`), planilla digital del frigorífico (`importado`, encabezados tolerantes y coma decimal; la lectura no guarda)
  y PDF/foto por lectura asistida (`transcripcion_revisada`, familia `resultado_faena.romaneo/1` vía `FamiliaConRegistroPropio`). Los dos últimos exigen
  el original (CHECK en la base). Asociación RFID–garrón por la misma búsqueda de identificaciones que sanidad (incluye vendidos/bajas).
- **Oficial vs cálculo:** el Resource separa `oficial` (dato del frigorífico con leyenda) de `conciliacion` (cálculo de TROPILLA, se recalcula al leer):
  enviados vs reses (conciliados, faltantes, no estaban en la venta, sin asociar), rinde por animal (res / último peso vivo) y de tropa (kg reses /
  kg vivos de la venta), importe esperado al gancho vs bruto liquidado, bruto − deducciones vs neto. No crea eventos ni toca pesos vivos.
- Exporta CSV (`;`, BOM; columnas `tropilla_*` = cálculo propio) y PDF (Gotenberg, `pdf/conciliacion-faena`, 503 si no responde); a pedido, no se guarda.
- Pendiente: smoke de lectura asistida con Gemini sobre un romaneo real (el test usa el extractor fake). OpenAPI: se quitó un `presentacion` duplicado
  en `BorradorDocumento` (TPA-66) que impedía parsear el YAML.
- Verificado: 4 tests (alta con advertencias, asociación, conciliación, idempotencia, liquidación con 409, factura, CSV/PDF, anulación, append-only;
  validaciones/advertencias/403/404; planilla CSV; foto → revisión → confirmación → vincular venta) + suite completa 342 verdes.

---

## TPA-77 · Bot de Telegram del productor · ✅ (parte API)

Endpoints: `GET|PATCH|DELETE /me/telegram`, `POST /me/telegram/codigo`, `POST /telegram/webhook` (sin token de usuario).
Dominio `App\Domain\Telegram` + cliente genérico `App\Domain\Ia\GeminiCliente`. Migración `2026_10_21_090000_create_telegram_tables`.

**Comentario del dev:**
- **Vinculación**: código de 10 caracteres (sin 0/O/1/I/L), 10 min, un solo uso; solo se guarda su SHA-256. `/start <código>`
  o deep link `t.me/tropilla_bot?start=<código>`. Un vínculo activo por usuario y por chat (índices únicos parciales); vincular
  de nuevo cierra el anterior (`nuevo_vinculo`). 5 intentos fallidos por chat cada 10 min. Teléfono por contacto de Telegram
  solo si es el del propio remitente (referencia). `/campo` con botones; si tiene un solo establecimiento queda elegido.
- **Webhook**: `X-Telegram-Bot-Api-Secret-Token` con `hash_equals`; sin token/secreto el endpoint da 404. Idempotente por
  `update_id` (`telegram_updates`) y todo se procesa en cola (`ProcesarUpdateJob`, un intento). Solo chats privados; los botones
  se aceptan solo del usuario de Telegram vinculado.
- **Permisos**: el bot actúa como el usuario vinculado (`Gate::forUser` en cada herramienta/acción; `Contexto::como()` fija y
  restaura el usuario autenticado para las Actions que lo leen). Las herramientas que el rol no tiene ni se le declaran al modelo.
- **Documentos**: foto/PDF → `GuardarArchivo` → `RegistrarDocumentoVersion` → `CrearBorrador` (mismo borrador TPA-61/62;
  álbum = documento multipágina). `SeguirBorradorJob` mira el estado sin tocar el flujo de extracción. Revisión por chat: resumen +
  solo los dudosos (confianza < 0,85, alternativas, sugerencias de TROPILLA, críticos vacíos, con advertencia) con
  Correcto/Corregir/No se lee → `RevisarCampos` (queda en `borrador_correcciones`). Recién al confirmar se marcan los no
  preguntados (el usuario los vio en el resumen) y se llama `ConfirmarBorrador` con origen `telegram`; advertencias → «Registrar igual».
- **Asistente**: Gemini REST `generateContent` (`x-goog-api-key`, modelo `TROPILLA_ASISTENTE_MODELO`, default
  `gemini-flash-latest`), function calling con herramientas de solo lectura sobre consultas existentes (existencias,
  animal por identificación, `TableroReproductivo`, tareas/documentos/partos, clima, ofertas sin precios) y `proponer_carga`.
  Sin memoria entre mensajes. Audio: transcripción con el mismo modelo (inline `audio/ogg`) y se muestra «Entendí: …».
- **Cargas rápidas** (nacimiento/parto, pesaje, muerte, tratamiento): el servidor resuelve caravanas y arma el input; propuesta con
  botones; al confirmar `ArmarSobreWeb::alta(…, 'telegram')` + `RegistrarEvento` (canal web: advertencias se vuelven a preguntar).
  Idempotente por id de evento + lock por propuesta. `eventos.origen` admite `telegram` (CHECK + validación del sobre).
- **Avisos**: canal nuevo en `Notificador::aUsuario` → `AvisoTelegramJob` (afterCommit) si `preferencias_notificacion.telegram`
  (default true) y el chat tiene avisos activos (`/avisos` o `PATCH /me/telegram`). 403 de Telegram → se desvincula.
- **Auditoría**: `telegram_mensajes` append-only (trigger `tropilla_inmutable`) con tipo, comando, acción, entidad y metadatos
  (largo, herramientas), **sin** texto libre ni respuestas; altas/bajas/cambios del vínculo en `auditoria`.
- **Secretos**: token en la URL de la Bot API → `TelegramApi` reemplaza el token por `***` en todo error; la key de Gemini va por
  header. `php artisan telegram:webhook --url=… | --info | --borrar` registra el webhook sin mostrar nada. `telegram:limpiar` cada hora.
- Verificado: Pest `Telegram/BotTelegramTest` 15 tests con `Http::fake` (Telegram y Gemini) + suite completa verde.

## TPA-61 · bugfix: la cola reentregaba la extracción antes de que terminara · ✅

**Comentario del dev:**
- `retry_after` de redis/database/beanstalkd pasa de 90 s a **360 s** (override por env `*_QUEUE_RETRY_AFTER`): con 90 s,
  una lectura de Gemini/Claude de hasta 300 s se daba por perdida y otro worker la volvía a tomar (y con `tries=1` terminaba en `MaxAttemptsExceeded`).
- `ExtraerBorradorJob`: `timeout` 300 s (< retry_after con margen), `failOnTimeout` y `failed()` que pasa el borrador de `extrayendo` a `fallido`
  con un mensaje claro, para que un corte del worker no lo deje colgado; el productor lo relee a mano.
- El worker de compose no cambia: el `$timeout` del job pisa el `--timeout` por defecto (60 s) y los demás jobs siguen cortando a los 60 s.
- Verificado: test Pest nuevo (retry_after > timeout + 30 en las tres conexiones y timeout → fallido); suite 308 verde, Pint.

## TPA-65 · Libro de tratamientos y extracción FIDHA · ✅ (parte API)

**Endpoints (aditivos):** `GET /establecimientos/{e}/sanidad/libro-tratamientos` (q, desde, hasta, tipo, lote_id, cursor). Familias `libro_tratamientos`
y `fidha` en los borradores existentes. **Real vs mock:** real (pipeline Gemini/Claude de TPA-61/62; tests con `FakeExtractor`).

**Comentario del dev:**
- `libro_tratamientos.666_2011/1`: encabezado (establecimiento, RENSPA, titular, folio, rúbrica, veterinario/matrícula) y una fila por renglón con el
  contenido mínimo del anexo (fecha, animales, caravanas, diagnóstico, producto, principio activo, dosis, vía, duración, responsable, retiros). El lote
  se sugiere si el renglón lo nombra; las caravanas se resuelven contra identificaciones vigentes. Cada fila confirmada es un `sanidad.tratamiento`
  (retiros incluidos, así alimenta «retiro vigente»); la fecha «no aplica» omite la fila.
- `fidha.655_2026/1` (vigente) y `fidha.anterior/1` (`formulario_anterior`): inspección, tropa y tratamiento precaucional. Confirmar registra la
  inspección como `sanidad.observacion_clinica` con el signo «inspección de garrapata: <resultado>» y, si hubo, un `sanidad.desparasitacion` externo;
  si un movimiento cargado tiene el mismo N.º de DT-e, el documento también se vincula a él. Decisión: no se creó un tipo de evento nuevo para no tocar
  el contrato de sync/hash de los tres repos; el signo hace la inspección buscable.
- Baja confianza: un campo **dudoso** (confianza < 0,6 con algo leído) no se acepta en un pedido de varios campos («Todo bien»): queda como sugerencia
  pendiente hasta confirmarlo de a uno, corregirlo o marcarlo. Aplica a todas las familias.
- Separado del formulario oficial: las observaciones de cada evento y el `aviso` del libro aclaran que no reemplazan al libro rubricado ni al FIDHA; la
  foto queda íntegra como documento fuente vinculado. Advertencias: retiro faltante, contenido mínimo faltante, inspección positiva/rechazo,
  tratamiento sin producto, fecha de tratamiento posterior, firma no detectada, RENSPA distinto; bloquean fila sin animales y caravanas desconocidas.
- Migración aditiva `2026_10_21_110000_add_libro_fidha_familias_to_borradores` (CHECK de familias, tipos `libro_tratamientos` y `fidha`). Al integrar con
  TPA-66 se hicieron dinámicos el CHECK de esta migración y el de `…100000_add_dte_guia_familias…` (rehacen la lista desde el vigente, como TPA-66), para
  que el orden en que corran en cada ambiente no borre familias ajenas; y se quitó del OpenAPI un `presentacion` duplicado en `BorradorDocumento`.
- Verificado: 4 tests nuevos (libro con dudoso bloqueado en bloque, sujetos lote/caravana y búsqueda; filas sin animales/omitidas; FIDHA con
  inspección + tratamiento + vínculo al movimiento y variante anterior; permisos/validación del libro); suite 342 verde, Pint (sin cambios en los archivos del ticket).
- Pendiente: casilleros literales del FIDHA 2026 y del anexo de contenidos mínimos contra ejemplares reales (investigación, incertidumbre 2).

## TPA-63 · Importar identificaciones electrónicas y planilla de caravanas · ✅ (parte API)

Endpoints: `GET …/identificaciones/importaciones`, `GET|PUT …/identificaciones/importaciones/{id}` (PUT idempotente por UUIDv7
del teléfono: 201/200). Migración `identificacion_importaciones` + `identificacion_importacion_filas`. Payload de
`hacienda.identificacion` con campos opcionales nuevos `motivo`, `origen`, `dispositivo {tipo, lector}`, `vinculada_a` e
`importacion_id`. Parámetros `identificacion_electronica_hitos` y `rfid_prefijo_pais` por especie; tipo de documento `planilla_identificacion`.

**Comentario del dev:**
- La importación la arma el teléfono sin señal (bastón, archivo CSV/TXT del lector, código de barras o planilla en papel con la foto
  como respaldo); las colocaciones viajan como eventos `hacienda.identificacion` por el push y la importación entera se sube después
  con **todas** sus filas. El servidor las reverifica (`ConciliadorIdentificaciones`, misma regla que el teléfono) y guarda
  `estado` (teléfono) y `estado_servidor`: `lista`, `reposicion`, `sin_cambios`, `registrada`, `duplicada`, `invalida`, `en_uso`,
  `sin_conciliar`, `otra_especie`, `omitida`. Nada se descarta: un PUT sin alguna fila ya guardada → 422; confirmada, la cabecera queda fija.
- Identificaciones anteriores: la reposición deja la RFID previa no vigente en el historial (proyector existente); el evento lleva
  fecha (`colocada_en`), tipo, motivo, origen y dispositivo/lector, y `vinculada_a` con la caravana con que se concilió.
- Especie y fecha: hitos de la Res. SENASA 530/2025 (destete/primer movimiento 1/3/2025, stock sin oficial 31/12/2025, todas las
  categorías 1/7/2026) como **datos por especie** (hoy iguales para BOV y BUB); la respuesta trae `reglas.vigentes/proximos` a la
  fecha. RFID sin prefijo 032 solo advierte. Otra especie que la de la importación queda `otra_especie`.
- Verificado: 4 tests Pest nuevos (10 estados con ceros a la izquierda, prefijo, ambigüedad; evento con los campos nuevos;
  idempotencia y filas que no se borran; reglas por fecha/especie y catálogo; 403/404/422); suite 331 verde, Pint.
- Desvío: el brain no tiene estos endpoints ni los campos nuevos del payload (anotado abajo y en `openapi-v1.yaml`).

## Desvíos del contrato

Todos documentados en `docs/openapi-v1.yaml` (aditivos salvo que se diga):

- TPA-14: `poligono` (GeoJSON) en `Potrero`/`PotreroInput`.
- TPA-21: `POST …/importaciones` acepta multipart `archivo` además de `archivo_sha256`; (bugfix) `ImportacionFila.original` con los valores crudos de la planilla.
- TPA-23 (bugfix): `GET …/animales/{a}/pesajes` + schema `PuntoPeso`.
- TPA-25 (bugfix): `POST …/animales/sugerencias/descartar`; `Animal.categorias_descartadas`; parámetro `edad_destete_meses`.
- TPA-27/28/29: campos `servicio_tipo`, `tacto_fecha`, `dias_gestacion`, `dias_posparto`, `destete` en `Animal`.
- TPA-30: `sin_estado`, `partos_proximos`, `partos_vencidos` en `TableroVientres`.
- TPA-48: `compromiso` en `AnimalResumen`; PDF público de oferta como `200 application/pdf` en vez de `302` a URL prefirmada.
- TPA-43 (bugfix): catálogos con `especie_codigo` (+ alias `especie`); `/sync/catalogos` `private, no-cache`; identificador tolerante a ceros.
- TPA-63: `GET|PUT …/identificaciones/importaciones[/{id}]` + schemas `ImportacionIdentificaciones*`; `hacienda.identificacion` con `motivo`, `origen`, `dispositivo`, `vinculada_a`, `importacion_id` (opcionales); parámetros `identificacion_electronica_hitos`/`rfid_prefijo_pais`; tipo de documento `planilla_identificacion`.
- TPA-31: `ProductoSanitario.id` nullable + `codigo` en la base sugerida; `sugerido_codigo` en el input; filtro `especie`.
- TPA-32: la web expande sanidad a lote no anónimo y completa el snapshot del producto en el payload; sujeto potrero resuelto a animales presentes.
- TPA-33: `PlanItem` + `nota`, `aplica`, `requiere_configuracion`, `archivado_en`; `Tarea.reprogramada`; filtro `estado=abiertas`; formato de `disparador` documentado.
- TPA-34: parámetros `tuberculina_*_mm` por especie; resultado calculado por el servidor en la web; revisión `resultado_prueba_discrepante`.
- TPA-35: `lotes[]` aditivo en `…/sanidad/retiros`; `retiro_*_hasta` en `Lote`; advertencia `reactor` en egreso. (bugfix) aplicación a potrero alcanza lotes anónimos presentes (`evento_lotes`); herencia del retiro al individualizar.
- TPA-36: `DocumentoInput.privacidad` opcional (default del tipo); `emisor_nombre` en `Documento`; `versiones` solo en ficha/alta/sync.
- TPA-38: `Enlace.aviso` para fiscales; URL pública `{web}/e/{token}`; `estado: reemplazado` en enlaces a versión fija.
- TPA-40: `GET /public/anclajes/{a}/recibo` (.ots); pull `anclaje` con `evento_ids`; verificación de archivo también por enlace vigente.
- TPA-45: rendimiento al gancho sobre el **bruto** y desbaste solo en pie (modelo-datos §9.1 decía sobre el neto); `formula` aditiva en `SimulacionResultado`.
- TPA-46: `Operacion`/`OperacionInput` + `animal_ids`, `oferta_id` (+ `lote_id`, `contrato_id`, `confirmar_advertencias`, `id`); liquidación calculada por el servidor.
- TPA-47: query `referencia` (serie por descripción, admite otra especie) en `…/precios/historial`.
- TPA-53: `GET /me/preferencias-notificacion` aditivo; `meta.no_leidas` en la bandeja.
- TPA-56: `Contrato.documento_id`; `huella` null en borrador; respuesta de `?simular=true` = {terminos, recorte}; vínculo de documentos `contrato` → tabla `contratos_futuro`.
- TPA-57: `Toma.contrato` y `Toma.entrega` (solo la línea del comprador) aditivos; `estado=vigentes` en `/comprador/tomas`; códigos 403 `perfil_comprador_requerido` / `email_no_verificado`.
- TPA-54 (bugfix): schema `Foto`; `fotos` de `Perfil`/`Oferta` pasan de `Archivo[]` a `Foto[]` y las públicas de `string[]` a `Foto[]`; `foto` aditivo junto a cada `foto_url`; rutas `/public/fotos/{sha}` y `/fotos/{sha}`; `Profesional.foto_sha256`.
- TPA-58: `Busco.zona.provincias_nombres` aditivo; reglas de visibilidad del comprador y puntaje documentados.
- TPA-64: familias `dte` y `guia_provincial`; `BorradorDocumento.eventos_vinculados` y `presentacion`; `BorradorCampo.tipo` suma `lote` y `evento`; `agregar_fila` acepta los grupos de la familia; `aviso` ya no nombra solo a SENASA.
- TPA-65: `GET …/sanidad/libro-tratamientos` + schema `LibroTratamientoFila`; familias `libro_tratamientos` y `fidha`; regla de campos dudosos en `PATCH …/campos`.
- TPA-51: `POST /organizaciones` (staff) y `POST /organizaciones/{o}/miembros` aditivos; `Adherido.datos` con solo lo consentido; 409 `ya_adherido`.
- TPA-77: tag `Telegram` (`/me/telegram*`, `/telegram/webhook`); `Evento.origen` suma `telegram`; `preferencias-notificacion` suma `telegram` (opcional en el PUT).
- TPA-66: endpoints de `sanidad/oficial`, `sanidad/protocolos`, `sanidad/estatus`, `sanidad/saneamiento` y `animales/{a}/pruebas`; familia `protocolo_sanitario` (grupo agregable `animales`, tipo de campo `decimal`); `TipoPrueba.enfermedad` en catálogos.
- TPA-67: endpoints `faena/*` (tag Comercial) y schemas `ResultadoFaena*`, `ResFaenaInput`, `LiquidacionFaena`; familia `resultado_faena` en borradores.

### TPA-76 · Ficha completa del establecimiento · ✅

**Comentario del dev:** `ficha_ampliada` y `visibilidad_ficha` conservan los bloques versionados, auditados y sincronizables. El lindero se almacena como `MultiPolygon(4326)` con índice GiST, y la API convierte GeoJSON declarado, verifica tipo/validez y filtra cada bloque público mediante allowlist explícita; el identificador RENSPA y observaciones libres permanecen privados, mientras vencimientos/plan sanitario requieren opt-in. El seeder regional agrega datos solo a los demos identificados de Corrientes, Chaco, Formosa y Misiones: fichas completas, bovinos y búfalos, pedigrí externo de cinco generaciones con ancestro compartido, IATF con lote de pajuelas, organizaciones, documentos, sanidad, ofertas y compradores; ninguna ruta del seeder selecciona `prueba@tropilla.ar`. La repetición se valida en Pest y conserva versiones ya presentes y las decisiones de visibilidad.
Se agregaron `ficha_ampliada` y `visibilidad_ficha` como JSONB en establecimientos. El PATCH usa la fila versionada existente, auditoría y cursor de sync; lectura por roles sin `establecimiento.ver_privados` filtra cada bloque y solo entrega los opt-in. El panel permite editar los bloques como JSON y el mapa de visibilidad; los formularios visuales por dominio, lectura offline Flutter, geometría de lindero PostGIS, seeder regional completo y producción quedan pendientes. Decisión: no inventar polígonos a partir del punto GPS; exigir geometría declarada por el productor con fuente/fecha cuando se implemente. Validado Pest de datos de establecimiento (7/44), Pint 421 archivos; Next typegen, tsc y lint.

### TPA-70 · Alertas climáticas y riesgo por establecimiento (en curso)

Dominio `App\Domain\Clima`: `UbicacionClima` (ubicación del establecimiento redondeada a 2 decimales → centroide del departamento → capital provincial; la coordenada nunca sale en la respuesta), `FuentesClima` (Open-Meteo forecast 16 días + 7 pasados, Open-Meteo archive ERA5 para la anomalía de lluvia de 60 días contra los 10 años previos, NOAA CPC ONI; sin key, timeouts de 10 s, cache 3/24/24 h por punto), `EvaluadorAlertas` (reglas puras: lluvia extrema, sequía por déficit, helada, THI ≥ 79 por 3 días, índice simple de incendio, fase ENSO informativa) y la Action `ActualizarClima`. Tablas `clima_reglas` (umbrales como datos, override por establecimiento), `clima_alertas` (no se borran: `descartada` si el pronóstico ya no las sostiene; misma regla con fechas contiguas = misma alerta, sin re-aviso) y `clima_estado`. Comando `clima:actualizar` cada 6 h; `GET …/clima` recalcula si el cálculo tiene más de 6 h. Aviso `clima_alerta` por `Notificador` (in-app siempre, push si hay FCM) a dueño/admin/encargado/asesor, salvo ENSO (informativo). Si una fuente falla, sus reglas no se evalúan y se conservan las alertas previas. Licencia: la API gratuita de Open-Meteo es para uso no comercial; producción comercial requiere su plan pago o fuente alternativa (pendiente de decisión). Pest Clima 6/104; suite completa 292 verdes.

### TPA-71 · Brief preventivo de forraje y carga animal · ✅

Dominio `App\Domain\Forraje\BriefForraje`. Endpoints: `GET …/forraje/insumos`, `POST …/forraje/briefs/calcular`, `GET|POST …/forraje/briefs`, `GET …/forraje/briefs/{brief}`, `POST …/forraje/briefs/{brief}/revisiones`. Abilities nuevas: `forraje.ver` (todos), `forraje.crear` (dueño/admin/encargado/asesor), `forraje.revisar` (dueño/admin/asesor). Migración `2026_10_21_090000_create_forraje_briefs_tables` (`forraje_briefs` y `forraje_brief_revisiones`, ambas inmutables por trigger). Parámetros nuevos en `EspeciesSeeder` (`forraje_ev_categoria` por especie, `forraje_consumo_ms_kg_ev_dia`, `forraje_aprovechamiento_reservas_pct`, `forraje_incertidumbre_pct`, `forraje_reduccion_oferta_pct`, `forraje_rollo_kg`, `forraje_ms_pct`), sobrescribibles por establecimiento con `PUT …/parametros/{clave}`.

**Comentario del dev:** El brief usa el inventario declarado (`existencias`) con equivalencias EV como datos por especie, la superficie del establecimiento (o la suma de potreros) y la receptividad de la ficha de TPA-76; las reservas (rollos, silo, suplemento) se cargan en cada brief. Calcula tres escenarios de oferta de pasto (base, −25 %, −50 %, editables) con EV de referencia, faltante de MS y días de cobertura como rango (±20 % sobre la demanda, explicado como margen de prudencia y no como intervalo estadístico). Lee el contexto ya calculado de TPA-70 (alertas, anomalía de lluvia, ENSO) sin consultar fuentes y marca qué escenario se parece más a esas señales, aclarando que no es pronóstico de pasto. No receta manejo ni calcula carga óptima: devuelve preguntas para el asesor. El piloto queda documentado: cada brief guardado es inmutable y recibe revisiones append-only con persona, rol y nota. Los coeficientes son orientativos y quedan marcados como no validados (en búfalos, sin validar). Pest de forraje 6/148.

### TPA-72 · Chequeo de contraparte por CUIT · ✅

Dominio `App\Domain\Contrapartes`: `Cuit` (dígito verificador, tipo de persona), interfaz `Fuentes\FuenteContraparte` con dos adaptadores — `BcraCentralDeudores` (API pública del BCRA sin credenciales: situación actual, 24 meses y cheques rechazados; reintentos, timeout, cache 12 h y error no cacheado) y `ArcaConstanciaInscripcion` (`no_configurada`: el WS de constancia exige certificado + WSAA; no se hace scraping ni se evade el CAPTCHA) — y `ChequeoContraparte` (lectura neutral, avisos, checklist). Endpoints: `GET …/contrapartes/fuentes`, `GET|POST …/contrapartes/consultas`, `GET …/contrapartes/consultas/{c}`, `PUT …/contrapartes/consultas/{c}/checklist/{item}`. Ability `contrapartes.consultar` (dueño/admin). Migración `2026_10_21_120000_create_contraparte_consultas_tables`. Comando `contrapartes:depurar` (diario 05:00). Variables: `TROPILLA_CONTRAPARTES_BCRA` (default true), `TROPILLA_CONTRAPARTES_BCRA_URL`, `TROPILLA_CONTRAPARTES_ARCA_DRIVER` (deshabilitado), `TROPILLA_CONTRAPARTES_TIMEOUT`, `TROPILLA_CONTRAPARTES_MAX_DIA` (20), `TROPILLA_CONTRAPARTES_RETENCION_DIAS` (180), `TROPILLA_CONTRAPARTES_REVISION_LEGAL` (pendiente).

**Comentario del dev:** La consulta es asistida y puntual: exige finalidad, consentimiento versionado y, si el CUIT es de una persona humana, que el titular la autorizó; queda registrado quién consultó, cuándo y para qué, con tope diario por establecimiento. Cada fuente informa su estado (`ok`, `sin_datos`, `no_configurada`, `error`) y la hora de consulta, de modo que "no respondió" o "sin registros" nunca se presentan como "sin deudas". La lectura son frases neutrales con período y fuente, sin score, sin bloqueo y sin la señal de antigüedad del CUIT. El checklist de diligencia lo marca el productor. Vencida la retención se borra el informe y queda el registro. Real: BCRA verificado contra la API pública; ARCA queda con adaptador listo y sin credenciales. La revisión legal sigue pendiente: no se automatizan re-consultas ni lotes. Pest de contrapartes 7/93.
