-- AutoCar · migración de la entrega 4 (publicar vehículos). Se ejecuta UNA vez en el SQL Editor de Neon
-- sobre una base creada con el schema.sql de la entrega 3. Es idempotente: se puede volver a correr.
-- Una base nueva no la necesita: schema.sql ya trae todo.

ALTER TABLE vehiculos ADD COLUMN IF NOT EXISTS usuario_id   INTEGER REFERENCES users(id);
ALTER TABLE vehiculos ADD COLUMN IF NOT EXISTS version      VARCHAR(60);
ALTER TABLE vehiculos ADD COLUMN IF NOT EXISTS condicion    VARCHAR(10)
  CHECK (condicion IN ('excelente', 'muy_bueno', 'bueno', 'regular'));
ALTER TABLE vehiculos ADD COLUMN IF NOT EXISTS acepta_canje BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE vehiculos ADD COLUMN IF NOT EXISTS busca_canje  VARCHAR(120);
ALTER TABLE vehiculos ADD COLUMN IF NOT EXISTS localidad    VARCHAR(100);

-- Estados nuevos: 'pendiente' (espera aprobación del admin) y 'rechazado'
ALTER TABLE vehiculos DROP CONSTRAINT IF EXISTS vehiculos_estado_check;
ALTER TABLE vehiculos ADD CONSTRAINT vehiculos_estado_check
  CHECK (estado IN ('pendiente', 'disponible', 'rechazado', 'vendido'));

CREATE INDEX IF NOT EXISTS idx_vehiculos_usuario ON vehiculos (usuario_id);

CREATE TABLE IF NOT EXISTS vehiculo_fotos (
  id          INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  vehiculo_id INTEGER     NOT NULL REFERENCES vehiculos(id) ON DELETE CASCADE,
  orden       SMALLINT    NOT NULL DEFAULT 0,
  mime        VARCHAR(20) NOT NULL CHECK (mime IN ('image/jpeg', 'image/png', 'image/webp')),
  datos       BYTEA       NOT NULL,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_fotos_vehiculo ON vehiculo_fotos (vehiculo_id, orden);
