-- AutoCar · dos cosas que pidió el profe el 13/11 ("variables y controles" y clasificar al usuario):
--  1. Parámetros: los números del negocio (comisión, fondo, tope, días de reclamo, precio de la inspección) salen
--     del código y quedan en la tabla parametro, que el admin cambia desde el panel. Cada cambio queda anotado en
--     parametro_cambio (quién, cuándo, valor anterior y nuevo). Cada inspección guarda el tope y los días de reclamo
--     con los que se pidió, así un cambio no toca las que ya existen.
--  2. Preferencias del comprador: "¿Para qué buscás el auto?" (obligatoria la primera vez que entra) y, si quiere,
--     tipo de auto, presupuesto y localidad. Con eso se lo clasifica.
--  3. Política de privacidad: cuándo la aceptó cada cuenta personal (obligatoria al registrarse; las que ya existen
--     la aceptan junto con la pregunta de arriba). Se guardan datos personales, así que hace falta el consentimiento.
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main), ANTES de desplegar el back que la usa. Es idempotente.
-- Una base nueva no la necesita: schema.sql ya trae todo.

-- 1. Parámetros
CREATE TABLE IF NOT EXISTS parametro (
  clave        VARCHAR(40)   PRIMARY KEY,
  valor        NUMERIC(14,4) NOT NULL,
  minimo       NUMERIC(14,4) NOT NULL,   -- lo que se puede poner, para que nadie escriba 770 en vez de 77
  maximo       NUMERIC(14,4) NOT NULL,
  unidad       VARCHAR(10)   NOT NULL CHECK (unidad IN ('porcentaje', 'pesos', 'dias', 'veces')),
  descripcion  VARCHAR(200)  NOT NULL,
  orden        SMALLINT      NOT NULL,   -- en qué orden se muestran en el panel
  updated_at   TIMESTAMPTZ   NOT NULL DEFAULT now(),
  updated_by   INTEGER       REFERENCES usuario(id) ON DELETE SET NULL,
  CHECK (minimo <= maximo AND valor BETWEEN minimo AND maximo)
);

CREATE TABLE IF NOT EXISTS parametro_cambio (
  id             INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  clave          VARCHAR(40)   NOT NULL REFERENCES parametro(clave) ON DELETE CASCADE,
  valor_anterior NUMERIC(14,4) NOT NULL,
  valor_nuevo    NUMERIC(14,4) NOT NULL,
  usuario_id     INTEGER       REFERENCES usuario(id) ON DELETE SET NULL,
  created_at     TIMESTAMPTZ   NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_parametro_cambio_fecha ON parametro_cambio (created_at DESC);

-- Valores decididos el 14/11 (ideas-14-11-2026/decisiones-14-11-2026.md del front)
INSERT INTO parametro (clave, valor, minimo, maximo, unidad, descripcion, orden) VALUES
  ('comision_autocar', 23,      0,    50,       'porcentaje', 'Parte del precio de la inspección que se queda AutoCar (el resto es del mecánico)', 1),
  ('parte_fondo',      33.3333, 0,    100,      'porcentaje', 'Parte de lo de AutoCar que va al fondo de garantía', 2),
  ('tope_veces',       3,       0,    10,       'veces',      'Tope de la compensación de un reclamo, en veces el precio de la inspección', 3),
  ('dias_reclamo',     15,      1,    60,       'dias',       'Días que tiene el comprador para reclamar después de la firma', 4),
  ('precio_minimo',    70000,   1000, 10000000, 'pesos',      'Precio mínimo que puede cobrar un taller por la inspección', 5),
  ('precio_sugerido',  100000,  1000, 10000000, 'pesos',      'Precio que se le sugiere al taller', 6),
  ('precio_maximo',    150000,  1000, 10000000, 'pesos',      'Precio máximo que puede cobrar un taller por la inspección', 7)
ON CONFLICT (clave) DO NOTHING;

INSERT INTO permiso (nombre, descripcion) VALUES
  ('parametros.editar', 'Cambiar los parámetros del negocio (comisión, fondo, tope, días de reclamo, precios)')
ON CONFLICT (nombre) DO NOTHING;
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM rol r, permiso p WHERE r.nombre = 'admin' AND p.nombre = 'parametros.editar'
ON CONFLICT DO NOTHING;

-- Las inspecciones que ya existen se pidieron con 3 veces y 15 días
ALTER TABLE inspeccion ADD COLUMN IF NOT EXISTS tope_veces   NUMERIC(6,2) NOT NULL DEFAULT 3;
ALTER TABLE inspeccion ADD COLUMN IF NOT EXISTS dias_reclamo SMALLINT     NOT NULL DEFAULT 15;

-- 2. Preferencias del comprador (una fila por usuario)
CREATE TABLE IF NOT EXISTS usuario_preferencia (
  usuario_id  INTEGER      PRIMARY KEY REFERENCES usuario(id) ON DELETE CASCADE,
  uso         VARCHAR(10)  NOT NULL CHECK (uso IN ('personal', 'familia', 'trabajo', 'aplicacion')),
  tipo        VARCHAR(10)  CHECK (tipo IN ('chico', 'sedan', 'suv', 'pickup')),   -- NULL = me da igual
  presupuesto INTEGER      CHECK (presupuesto > 0),                               -- NULL = no lo dijo
  localidad   VARCHAR(100),
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- 3. Política de privacidad (NULL = todavía no la aceptó)
ALTER TABLE usuario ADD COLUMN IF NOT EXISTS privacidad_aceptada_at TIMESTAMPTZ;
