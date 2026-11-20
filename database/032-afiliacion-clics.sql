-- 032: afiliación con las plataformas del metabuscador (convenio simulado, como el pago de las inspecciones).
-- Cada clic en "Ver en Kavak / MercadoLibre / Autocosmos" pasa por GET /api/salida, que lo anota y redirige.
-- A fin de mes se le cobra a cada plataforma: clics válidos × su tarifa por clic. El admin ve el mes en curso y la
-- evolución mes a mes, y marca cobrado cada mes cerrado (se guarda el monto de ese momento).
-- Correrla ANTES de desplegar el back que la usa. Se puede correr más de una vez.

CREATE TABLE IF NOT EXISTS convenio_plataforma (
  fuente      VARCHAR(30)   PRIMARY KEY,               -- la misma clave que usa el metabuscador (mercadolibre, kavak…)
  nombre      VARCHAR(60)   NOT NULL,
  tarifa_clic NUMERIC(12,2) NOT NULL CHECK (tarifa_clic >= 0),
  activo      BOOLEAN       NOT NULL DEFAULT true,     -- sin convenio activo los clics se cuentan, pero no se cobran
  updated_at  TIMESTAMPTZ   NOT NULL DEFAULT now()
);

INSERT INTO convenio_plataforma (fuente, nombre, tarifa_clic) VALUES
  ('mercadolibre', 'MercadoLibre', 150),
  ('kavak',        'Kavak',        200),
  ('autocosmos',   'Autocosmos',   150)
ON CONFLICT (fuente) DO NOTHING;

-- Un clic hacia el aviso de otra plataforma. "valido" = el que se cobra: de una cuenta personal y no repetido
-- (el mismo usuario y el mismo auto cuentan una vez cada 24 horas). Los demás quedan para ver el tráfico total.
CREATE TABLE IF NOT EXISTS clic_salida (
  id         BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  usuario_id INTEGER      REFERENCES usuario(id) ON DELETE SET NULL,
  fuente     VARCHAR(30)  NOT NULL,
  auto_id    VARCHAR(200) NOT NULL,
  origen     VARCHAR(20),                                -- para_vos, destacado o metabuscador (desde dónde se tocó)
  valido     BOOLEAN      NOT NULL,
  created_at TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_clic_salida_fecha ON clic_salida (created_at, fuente);
CREATE INDEX IF NOT EXISTS idx_clic_salida_repetido ON clic_salida (usuario_id, auto_id, created_at);

-- Un mes cerrado que el admin marcó cobrado, con lo que se cobró en ese momento (si después cambia la tarifa, no se
-- mueve). tipo empresa queda para el abono de las concesionarias.
CREATE TABLE IF NOT EXISTS cobro_afiliacion (
  tipo        VARCHAR(12)   NOT NULL CHECK (tipo IN ('plataforma', 'empresa')),
  socio       VARCHAR(60)   NOT NULL,                  -- la fuente de la plataforma (o el id de la empresa)
  mes         DATE          NOT NULL CHECK (mes = date_trunc('month', mes)::date),
  cantidad    INTEGER       NOT NULL,                  -- clics válidos del mes
  monto       NUMERIC(14,2) NOT NULL,
  cobrado_at  TIMESTAMPTZ   NOT NULL DEFAULT now(),
  cobrado_por INTEGER       REFERENCES usuario(id) ON DELETE SET NULL,
  PRIMARY KEY (tipo, socio, mes)
);
