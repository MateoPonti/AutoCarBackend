-- AutoCar · auto vendido y fondo de garantía (lo que pidió el profe el 13/11, decidido el 14/11 y el 17/11).
--  1. Turno que confirma el vendedor: si el auto es de AutoCar, el vendedor confirma el turno que dio el mecánico.
--  2. El turno se cae solo: si la publicación pasa a vendida, eliminada o rechazada antes del turno, la inspección
--     queda 'caida' y se le devuelve todo al comprador. Si el turno ya pasó (el mecánico fue), se firma igual.
--  3. Crédito para el comprador original (tabla credito): si se firma con el auto ya vendido, o si el turno estaba
--     confirmado y el auto se vende dentro de las horas del cupón después de la firma. Se usa sola en su próxima
--     inspección (credito_usado) y vuelve si esa inspección se cae, se cancela o se rechaza.
--  4. Fondo de garantía: si un reclamo procede, el fondo paga la devolución y la compensación, y el mecánico paga
--     una franquicia (porcentaje de lo que cobró) que vuelve al fondo. Se guardan en el reclamo.
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main), ANTES de desplegar el back que la usa. Es idempotente.
-- Una base nueva no la necesita: schema.sql ya trae todo.

-- 1 y 2. Turno confirmado y estado 'caida'
ALTER TABLE inspeccion ADD COLUMN IF NOT EXISTS turno_confirmado_at TIMESTAMPTZ;
ALTER TABLE inspeccion DROP CONSTRAINT IF EXISTS inspeccion_estado_check;
ALTER TABLE inspeccion ADD CONSTRAINT inspeccion_estado_check
  CHECK (estado IN ('pedida', 'aceptada', 'rechazada', 'cancelada', 'firmada', 'no_realizada', 'caida'));

-- 3. Créditos del comprador
ALTER TABLE inspeccion ADD COLUMN IF NOT EXISTS credito_usado NUMERIC(11,2) NOT NULL DEFAULT 0;
ALTER TABLE inspeccion DROP CONSTRAINT IF EXISTS inspeccion_credito_usado_check;
ALTER TABLE inspeccion ADD CONSTRAINT inspeccion_credito_usado_check CHECK (credito_usado >= 0 AND credito_usado <= precio);

CREATE TABLE IF NOT EXISTS credito (
  id            INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  usuario_id    INTEGER       NOT NULL REFERENCES usuario(id) ON DELETE CASCADE,
  inspeccion_id INTEGER       NOT NULL REFERENCES inspeccion(id) ON DELETE CASCADE,  -- la que lo generó
  tipo          VARCHAR(12)   NOT NULL CHECK (tipo IN ('auto_vendido', 'cupon')),
  monto         NUMERIC(11,2) NOT NULL CHECK (monto > 0),
  usado_en      INTEGER       REFERENCES inspeccion(id) ON DELETE SET NULL,          -- NULL = disponible
  created_at    TIMESTAMPTZ   NOT NULL DEFAULT now(),
  UNIQUE (inspeccion_id)   -- una inspección da un solo crédito
);
CREATE INDEX IF NOT EXISTS idx_credito_usuario ON credito (usuario_id, created_at) WHERE usado_en IS NULL;

-- 4. Lo que pagó el fondo y lo que puso el mecánico en cada reclamo que procedió
ALTER TABLE reclamo ADD COLUMN IF NOT EXISTS devolucion NUMERIC(11,2) CHECK (devolucion >= 0);
ALTER TABLE reclamo ADD COLUMN IF NOT EXISTS franquicia NUMERIC(11,2) CHECK (franquicia >= 0);
-- Los que ya procedieron: se devolvió el precio y el mecánico no pagó franquicia (todavía no existía)
UPDATE reclamo r SET devolucion = i.precio, franquicia = 0
  FROM inspeccion i WHERE i.id = r.inspeccion_id AND r.estado = 'procede' AND r.devolucion IS NULL;

-- Parámetros nuevos (la unidad 'horas' es para la ventana del cupón)
ALTER TABLE parametro DROP CONSTRAINT IF EXISTS parametro_unidad_check;
ALTER TABLE parametro ADD CONSTRAINT parametro_unidad_check CHECK (unidad IN ('porcentaje', 'pesos', 'dias', 'veces', 'horas'));
INSERT INTO parametro (clave, valor, minimo, maximo, unidad, descripcion, orden) VALUES
  ('franquicia',      40, 0, 100, 'porcentaje', 'Parte de lo que cobró el mecánico que paga al fondo si un reclamo procede', 9),
  ('credito_vendido', 30, 0, 100, 'porcentaje', 'Crédito para el comprador, en % del precio, si el auto se vendió (firmada con el auto vendido o cupón)', 10),
  ('horas_cupon',     48, 1, 720, 'horas',      'Horas después de la firma en las que, si el auto se vende con el turno confirmado, el comprador recibe el cupón', 11)
ON CONFLICT (clave) DO NOTHING;
