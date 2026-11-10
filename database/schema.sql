-- AutoCar · esquema de la base (PostgreSQL / Neon). Se ejecuta una vez en el SQL Editor de Neon
-- (o con psql). Es idempotente: se puede volver a correr sin romper datos.

-- Un usuario tiene UN rol; un rol tiene VARIOS permisos. Los endpoints piden permisos, no roles:
-- para crear un rol nuevo alcanza con darle permisos acá, sin tocar el código.
CREATE TABLE IF NOT EXISTS rol (
  id          INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nombre      VARCHAR(20)  NOT NULL UNIQUE,
  descripcion VARCHAR(150) NOT NULL
);

CREATE TABLE IF NOT EXISTS permiso (
  id          INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nombre      VARCHAR(50)  NOT NULL UNIQUE,   -- "recurso.accion", el que exige cada ruta en routes.php
  descripcion VARCHAR(150) NOT NULL
);

CREATE TABLE IF NOT EXISTS rol_permiso (
  rol_id     INTEGER NOT NULL REFERENCES rol(id) ON DELETE CASCADE,
  permiso_id INTEGER NOT NULL REFERENCES permiso(id) ON DELETE CASCADE,
  PRIMARY KEY (rol_id, permiso_id)
);

INSERT INTO rol (nombre, descripcion) VALUES
  ('usuario',   'Usuario registrado: publica vehículos y chatea con otros usuarios'),
  ('moderador', 'Revisa las publicaciones: las aprueba o las rechaza'),
  ('admin',     'Puede todo: usuarios, moderación, bajas y métricas'),
  ('mecanico',  'Mecánico de un taller adherido: verifica vehículos')
ON CONFLICT (nombre) DO NOTHING;

INSERT INTO permiso (nombre, descripcion) VALUES
  ('vehiculos.moderar',       'Ver la cola de publicaciones y aprobarlas o rechazarlas'),
  ('vehiculos.gestionar',     'Dar de baja vehículos del catálogo'),
  ('usuarios.ver',            'Ver la lista y los datos de los usuarios'),
  ('usuarios.editar',         'Editar usuarios: nombre, rol y baja lógica'),
  ('verificaciones.ver',      'Ver las verificaciones de vehículos asignadas al taller'),
  ('verificaciones.realizar', 'Cargar el resultado de una verificación'),
  ('metricas.ver',            'Ver las métricas de la aplicación'),
  ('reclamos.resolver',       'Ver los reclamos de inspecciones y decidir si proceden')
ON CONFLICT (nombre) DO NOTHING;

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM (VALUES
  ('moderador', 'vehiculos.moderar'),
  ('moderador', 'reclamos.resolver'),
  ('mecanico',  'verificaciones.ver'),
  ('mecanico',  'verificaciones.realizar')
) AS rp(rol, permiso)
JOIN rol r ON r.nombre = rp.rol
JOIN permiso p ON p.nombre = rp.permiso
ON CONFLICT DO NOTHING;

-- El admin tiene TODOS los permisos (también los que se agreguen después, al volver a correr esto)
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM rol r CROSS JOIN permiso p WHERE r.nombre = 'admin'
ON CONFLICT DO NOTHING;

-- activo = false es la baja lógica (lo hace el admin): no puede ingresar y su sesión deja de valer.
CREATE TABLE IF NOT EXISTS usuario (
  id            INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nombre        VARCHAR(80)  NOT NULL,
  email         VARCHAR(254) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  rol_id        INTEGER      NOT NULL REFERENCES rol(id),
  activo        BOOLEAN      NOT NULL DEFAULT true,
  created_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_usuario_rol ON usuario (rol_id);

-- usuario_id: el vendedor, dueño de la publicación (obligatorio: 011-vehiculo-con-vendedor.sql). Toda publicación
-- nace 'pendiente' y el catálogo público sólo muestra las 'disponible'.
-- Se publica por partes (010-publicar-por-partes.sql): con marca, modelo y año ya se crea, y el resto se completa
-- después. Por eso precio, kilometraje, combustible y transmisión pueden estar vacíos, pero no en una visible.
CREATE TABLE IF NOT EXISTS vehiculo (
  id           INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  usuario_id   INTEGER       NOT NULL REFERENCES usuario(id),
  marca        VARCHAR(50)   NOT NULL,
  modelo       VARCHAR(60)   NOT NULL,
  version      VARCHAR(60),
  anio         SMALLINT      NOT NULL CHECK (anio BETWEEN 1950 AND 2100),
  precio       NUMERIC(11,2) CHECK (precio > 0),
  kilometraje  INTEGER       CHECK (kilometraje >= 0),
  combustible  VARCHAR(10)   CHECK (combustible IN ('nafta', 'diesel', 'gnc', 'hibrido', 'electrico')),
  transmision  VARCHAR(10)   CHECK (transmision IN ('manual', 'automatica')),
  condicion    VARCHAR(10)   CHECK (condicion IN ('excelente', 'muy_bueno', 'bueno', 'regular')),
  estado       VARCHAR(12)   NOT NULL DEFAULT 'disponible'
               CONSTRAINT vehiculo_estado_check
               CHECK (estado IN ('pendiente', 'disponible', 'rechazado', 'vendido', 'eliminado')),
  descripcion  TEXT,
  imagen_url   VARCHAR(255),
  acepta_canje BOOLEAN       NOT NULL DEFAULT false,
  busca_canje  VARCHAR(120),
  localidad    VARCHAR(100),
  motivo_rechazo VARCHAR(300), -- lo escribe el admin al rechazar; el dueño lo ve en sus publicaciones
  created_at   TIMESTAMPTZ   NOT NULL DEFAULT now(),
  updated_at   TIMESTAMPTZ   NOT NULL DEFAULT now(),
  CONSTRAINT vehiculo_completo_si_visible CHECK (
    estado NOT IN ('disponible', 'vendido')
    OR (precio IS NOT NULL AND kilometraje IS NOT NULL AND combustible IS NOT NULL AND transmision IS NOT NULL)
  )
);

CREATE INDEX IF NOT EXISTS idx_vehiculo_marca ON vehiculo (marca);
CREATE INDEX IF NOT EXISTS idx_vehiculo_estado_precio ON vehiculo (estado, precio);
CREATE INDEX IF NOT EXISTS idx_vehiculo_usuario ON vehiculo (usuario_id);

-- Fotos de las publicaciones, guardadas en la base (el disco de Render se borra en cada deploy).
-- Se sirven con GET /api/fotos/{id}. Al borrar el vehículo se borran sus fotos.
CREATE TABLE IF NOT EXISTS vehiculo_foto (
  id          INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  vehiculo_id INTEGER     NOT NULL REFERENCES vehiculo(id) ON DELETE CASCADE,
  orden       SMALLINT    NOT NULL DEFAULT 0,
  mime        VARCHAR(20) NOT NULL CHECK (mime IN ('image/jpeg', 'image/png', 'image/webp')),
  datos       BYTEA       NOT NULL,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_fotos_vehiculo ON vehiculo_foto (vehiculo_id, orden);

-- Chat entre un interesado y el vendedor, siempre sobre un vehículo. Una sola conversación por
-- (vehículo, interesado). updated_at = último mensaje, para ordenar la bandeja.
CREATE TABLE IF NOT EXISTS conversacion (
  id            INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  vehiculo_id   INTEGER     NOT NULL REFERENCES vehiculo(id),
  interesado_id INTEGER     NOT NULL REFERENCES usuario(id),
  vendedor_id   INTEGER     NOT NULL REFERENCES usuario(id),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (vehiculo_id, interesado_id),
  CHECK (interesado_id <> vendedor_id)
);

CREATE INDEX IF NOT EXISTS idx_conversacion_interesado ON conversacion (interesado_id);
CREATE INDEX IF NOT EXISTS idx_conversacion_vendedor ON conversacion (vendedor_id);

-- El id es creciente: el front pide sólo los nuevos con ?desde=<último id que tiene> (polling).
CREATE TABLE IF NOT EXISTS mensaje (
  id              INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  conversacion_id INTEGER       NOT NULL REFERENCES conversacion(id) ON DELETE CASCADE,
  usuario_id      INTEGER       NOT NULL REFERENCES usuario(id),
  texto           VARCHAR(1000) NOT NULL CHECK (length(texto) > 0),
  leido           BOOLEAN       NOT NULL DEFAULT false,
  created_at      TIMESTAMPTZ   NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_mensaje_conversacion ON mensaje (conversacion_id, id);

-- Sesiones de PHP ($_SESSION), guardadas en la base porque el disco de Render se borra en cada deploy (ver SesionDb).
-- id = el valor de la cookie autocar_sesion; datos = $_SESSION serializado por PHP.
CREATE TABLE IF NOT EXISTS sesion (
  id    VARCHAR(256) PRIMARY KEY,
  datos TEXT         NOT NULL,
  vence TIMESTAMPTZ  NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sesion_vence ON sesion (vence);

-- Inspecciones independientes (ver 006-inspecciones.sql): el comprador elige al mecánico y el mecánico firma.
-- Un mecánico (usuario con rol mecanico) tiene UN taller. Sin el perfil completo no aparece en el ranking.
-- demo = true en los talleres de ejemplo (database/007-demo-inspecciones-solo-dev.sql, solo en dev).
-- estado: un usuario pide sumar su taller ("Adherí tu taller", 009-solicitud-taller.sql) y queda 'pendiente'
-- hasta que un admin lo aprueba (pasa a rol mecanico) o lo rechaza con un motivo. Solo los aprobados están en la red.
CREATE TABLE IF NOT EXISTS taller (
  usuario_id        INTEGER       PRIMARY KEY REFERENCES usuario(id),
  nombre            VARCHAR(80)   NOT NULL,
  direccion         VARCHAR(120)  NOT NULL,
  localidad         VARCHAR(100)  NOT NULL,
  telefono          VARCHAR(30)   NOT NULL,
  precio_inspeccion NUMERIC(11,2) NOT NULL CHECK (precio_inspeccion > 0),
  activo            BOOLEAN       NOT NULL DEFAULT true,
  demo              BOOLEAN       NOT NULL DEFAULT false,
  cuit              VARCHAR(13),
  horarios          VARCHAR(80),
  estado            VARCHAR(10)   NOT NULL DEFAULT 'aprobado',
  motivo_rechazo    VARCHAR(300),
  resuelto_por      INTEGER       REFERENCES usuario(id),
  resuelto_at       TIMESTAMPTZ,
  created_at        TIMESTAMPTZ   NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ   NOT NULL DEFAULT now(),
  CONSTRAINT taller_estado_check CHECK (estado IN ('pendiente', 'aprobado', 'rechazado'))
);

CREATE INDEX IF NOT EXISTS idx_taller_localidad ON taller (localidad);
CREATE INDEX IF NOT EXISTS idx_taller_estado ON taller (estado, created_at);

-- El auto puede ser de cualquier sitio: url + fuente siempre; vehiculo_id solo si es de AutoCar.
-- Los montos se guardan al pedirla (el taller puede cambiar su precio después). El pago es simulado.
-- Al firmar se copian el nombre del mecánico y del taller, y huella = SHA-256 del informe (ver Inspecciones\Huella).
CREATE TABLE IF NOT EXISTS inspeccion (
  id               INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  comprador_id     INTEGER       NOT NULL REFERENCES usuario(id),
  mecanico_id      INTEGER       NOT NULL REFERENCES usuario(id),
  vehiculo_id      INTEGER       REFERENCES vehiculo(id),
  url              VARCHAR(500)  NOT NULL,
  fuente           VARCHAR(12)   NOT NULL CHECK (fuente IN ('mercadolibre', 'kavak', 'autocosmos', 'autocar', 'otro')),
  titulo           VARCHAR(150),
  anio             SMALLINT      CHECK (anio BETWEEN 1950 AND 2100),
  kilometraje      INTEGER       CHECK (kilometraje >= 0),
  precio_publicado NUMERIC(13,2) CHECK (precio_publicado > 0),
  localidad        VARCHAR(100),
  estado           VARCHAR(10)   NOT NULL DEFAULT 'pedida'
                   CHECK (estado IN ('pedida', 'aceptada', 'rechazada', 'cancelada', 'firmada')),
  fecha_turno      TIMESTAMPTZ,
  motivo_rechazo   VARCHAR(300),
  precio           NUMERIC(11,2) NOT NULL CHECK (precio > 0),
  monto_mecanico   NUMERIC(11,2) NOT NULL,
  monto_autocar    NUMERIC(11,2) NOT NULL,
  monto_fondo      NUMERIC(11,2) NOT NULL,   -- parte de monto_autocar que va al fondo de garantía
  pago             VARCHAR(11)   NOT NULL DEFAULT 'retenido' CHECK (pago IN ('retenido', 'liberado', 'reembolsado')),
  conclusion       VARCHAR(16)   CHECK (conclusion IN ('recomendable', 'con_reparos', 'no_recomendable')),
  resumen          VARCHAR(1000),
  firmado_por      VARCHAR(80),
  taller_firma     VARCHAR(80),
  firmada_at       TIMESTAMPTZ,
  huella           CHAR(64),
  created_at       TIMESTAMPTZ   NOT NULL DEFAULT now(),
  updated_at       TIMESTAMPTZ   NOT NULL DEFAULT now(),
  CHECK (comprador_id <> mecanico_id),
  CHECK (monto_mecanico + monto_autocar = precio),
  CHECK (estado <> 'firmada' OR (conclusion IS NOT NULL AND firmado_por IS NOT NULL AND firmada_at IS NOT NULL AND huella IS NOT NULL))
);

CREATE INDEX IF NOT EXISTS idx_inspeccion_comprador ON inspeccion (comprador_id, created_at);
CREATE INDEX IF NOT EXISTS idx_inspeccion_mecanico ON inspeccion (mecanico_id, estado);

-- Un renglón por ítem del checklist (los códigos están en Inspecciones\Checklist).
CREATE TABLE IF NOT EXISTS inspeccion_item (
  inspeccion_id INTEGER     NOT NULL REFERENCES inspeccion(id) ON DELETE CASCADE,
  codigo        VARCHAR(20) NOT NULL,
  resultado     VARCHAR(9)  NOT NULL CHECK (resultado IN ('ok', 'observado', 'falla')),
  nota          VARCHAR(300),
  PRIMARY KEY (inspeccion_id, codigo)
);

-- Un solo reclamo por inspección. Si procede, el pago se reembolsa y el mecánico baja en el ranking.
CREATE TABLE IF NOT EXISTS reclamo (
  id            INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  inspeccion_id INTEGER       NOT NULL UNIQUE REFERENCES inspeccion(id),
  item_codigo   VARCHAR(20),
  descripcion   VARCHAR(1000) NOT NULL CHECK (length(descripcion) > 0),
  estado        VARCHAR(10)   NOT NULL DEFAULT 'abierto' CHECK (estado IN ('abierto', 'procede', 'no_procede')),
  compensacion  NUMERIC(11,2) CHECK (compensacion >= 0),
  respuesta     VARCHAR(1000),
  resuelto_por  INTEGER       REFERENCES usuario(id),
  created_at    TIMESTAMPTZ   NOT NULL DEFAULT now(),
  resuelto_at   TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_reclamo_estado ON reclamo (estado, created_at);

-- Los autos de otras plataformas (MercadoLibre, Kavak, Autocosmos), su historial, las búsquedas guardadas y sus avisos
-- NO están en esta base: van en Firestore (NoSQL). Ver docs/arquitectura-scraper.md y src/MetaBusqueda/Documentos.php.
