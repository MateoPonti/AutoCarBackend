-- 033: empresas (concesionarias y agencias), visitas a las publicaciones y el precio de cada una contra el mercado.
--   - Rol "empresa": se suma con "Sumá tu concesionaria", ingresa recién cuando un admin la aprueba (como los talleres),
--     publica sin límite y paga un abono mensual (parámetro abono_empresa_mensual, el mismo para todas; se cobra en la
--     grilla de Afiliaciones). Sus autos llevan la insignia con su nombre y no salen en el metabuscador.
--   - Un particular tiene hasta limite_autos_particular autos activos (publicados o en revisión).
--   - vehiculo_visita: una visita por visitante, auto y día (también sin sesión), para el panel de la empresa.
--   - vehiculo_mercado: el precio de cada publicación contra autos parecidos de otros sitios, guardado para que Explorar
--     no lea Firestore: se calcula al aprobar la publicación y una vez por día en el scraper (que deja un documento que el
--     back copia acá), o a mano desde el admin.
-- Correrla ANTES de desplegar el back que la usa. Se puede correr más de una vez.

INSERT INTO rol (nombre, descripcion) VALUES
  ('empresa', 'Concesionaria o agencia: publica sin límite, con su insignia, su vitrina y su panel')
ON CONFLICT (nombre) DO NOTHING;

CREATE TABLE IF NOT EXISTS empresa (
  usuario_id     INTEGER      PRIMARY KEY REFERENCES usuario(id) ON DELETE CASCADE,
  razon_social   VARCHAR(100) NOT NULL,                 -- el nombre que se ve en la insignia y la vitrina
  cuit           VARCHAR(13)  NOT NULL,                 -- 30-12345678-9
  localidad      VARCHAR(100) NOT NULL,
  telefono       VARCHAR(30)  NOT NULL,
  sitio_web      VARCHAR(200),
  estado         VARCHAR(10)  NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente', 'aprobado', 'rechazado')),
  motivo_rechazo VARCHAR(300),
  resuelto_por   INTEGER      REFERENCES usuario(id) ON DELETE SET NULL,
  resuelto_at    TIMESTAMPTZ,
  created_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
  updated_at     TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_empresa_estado ON empresa (estado, created_at);

CREATE TABLE IF NOT EXISTS vehiculo_visita (
  vehiculo_id INTEGER     NOT NULL REFERENCES vehiculo(id) ON DELETE CASCADE,
  visitante   VARCHAR(64) NOT NULL,                     -- u:<id> con sesión; si no, una marca anónima del día
  dia         DATE        NOT NULL,                     -- en hora de Argentina
  PRIMARY KEY (vehiculo_id, visitante, dia)
);
CREATE INDEX IF NOT EXISTS idx_vehiculo_visita_dia ON vehiculo_visita (dia, vehiculo_id);

CREATE TABLE IF NOT EXISTS vehiculo_mercado (
  vehiculo_id    INTEGER       PRIMARY KEY REFERENCES vehiculo(id) ON DELETE CASCADE,
  precio         NUMERIC(14,2) NOT NULL,                -- con qué datos del auto se calculó (si cambian, se recalcula)
  kilometraje    INTEGER       NOT NULL,
  anio           SMALLINT      NOT NULL,
  suficientes    BOOLEAN       NOT NULL,                -- hay al menos 5 autos parecidos
  diferencia_pct NUMERIC(7,1),                          -- % contra el precio típico de los parecidos
  mediana_precio NUMERIC(14,2),
  mediana_km     INTEGER,
  comparables    INTEGER       NOT NULL,
  calculado_at   TIMESTAMPTZ   NOT NULL DEFAULT now()
);

ALTER TABLE parametro DROP CONSTRAINT IF EXISTS parametro_unidad_check;
ALTER TABLE parametro ADD CONSTRAINT parametro_unidad_check
  CHECK (unidad IN ('porcentaje', 'pesos', 'dias', 'veces', 'horas', 'puntos', 'autos'));

INSERT INTO parametro (clave, valor, minimo, maximo, unidad, descripcion, orden) VALUES
  ('limite_autos_particular', 3,     1, 50,       'autos', 'Autos activos (publicados o en revisión) que puede tener un particular; las empresas no tienen límite', 37),
  ('abono_empresa_mensual',   50000, 0, 10000000, 'pesos', 'Abono mensual de cada empresa (incluye mantenimiento y aparición); el mismo para todas', 38)
ON CONFLICT (clave) DO NOTHING;
