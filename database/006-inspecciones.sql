-- AutoCar · inspecciones independientes: el comprador elige un mecánico de la red, el mecánico revisa el auto
-- con un checklist y firma el informe con su nombre. Si se le pasó algo que se veía, el comprador reclama.
-- Se ejecuta UNA vez en el SQL Editor de Neon, DESPUÉS de 005-roles.sql. Es idempotente.
-- Una base nueva no la necesita: schema.sql ya trae todo.

INSERT INTO permiso (nombre, descripcion) VALUES
  ('reclamos.resolver', 'Ver los reclamos de inspecciones y decidir si proceden')
ON CONFLICT (nombre) DO NOTHING;

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM (VALUES
  ('moderador', 'reclamos.resolver')
) AS rp(rol, permiso)
JOIN rol r ON r.nombre = rp.rol
JOIN permiso p ON p.nombre = rp.permiso
ON CONFLICT DO NOTHING;

-- El admin tiene TODOS los permisos, también el nuevo
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM rol r CROSS JOIN permiso p WHERE r.nombre = 'admin'
ON CONFLICT DO NOTHING;

-- Un mecánico (usuario con rol mecanico) tiene UN taller. Sin el perfil completo no aparece en el ranking.
-- demo = true en los talleres de ejemplo (database/007-demo-inspecciones-solo-dev.sql, solo en dev).
CREATE TABLE IF NOT EXISTS taller (
  usuario_id        INTEGER       PRIMARY KEY REFERENCES usuario(id),
  nombre            VARCHAR(80)   NOT NULL,
  direccion         VARCHAR(120)  NOT NULL,
  localidad         VARCHAR(100)  NOT NULL,
  telefono          VARCHAR(30)   NOT NULL,
  precio_inspeccion NUMERIC(11,2) NOT NULL CHECK (precio_inspeccion > 0),
  activo            BOOLEAN       NOT NULL DEFAULT true,
  demo              BOOLEAN       NOT NULL DEFAULT false,
  created_at        TIMESTAMPTZ   NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ   NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_taller_localidad ON taller (localidad);

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
