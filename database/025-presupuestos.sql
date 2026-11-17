-- AutoCar · idea A, costo total del auto (pedido de la cátedra en el mapa de capas del 10/11).
-- Cada arreglo que marca el informe firmado (un ítem observado o con falla) se le pide a varios talleres de la red
-- (parámetro presupuestos_por_arreglo, 3). El taller que inspeccionó NUNCA recibe el pedido: separar al que revisa del
-- que arregla. Cada taller responde con un monto y un detalle, vigente unos días (parámetro dias_presupuesto). El costo
-- total suma el precio del aviso y el presupuesto vigente más barato de cada ítem.
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main), ANTES de desplegar el back que la usa. Es idempotente.
-- Una base nueva no la necesita: schema.sql ya trae todo.

CREATE TABLE IF NOT EXISTS presupuesto (
  id            INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  inspeccion_id INTEGER       NOT NULL REFERENCES inspeccion(id) ON DELETE CASCADE,
  item_codigo   VARCHAR(20)   NOT NULL,
  mecanico_id   INTEGER       NOT NULL REFERENCES usuario(id),   -- el taller al que se le pidió
  estado        VARCHAR(10)   NOT NULL DEFAULT 'pedido' CHECK (estado IN ('pedido', 'respondido', 'rechazado')),
  monto         NUMERIC(11,2) CHECK (monto > 0),
  detalle       VARCHAR(500),
  vigente_hasta TIMESTAMPTZ,
  created_at    TIMESTAMPTZ   NOT NULL DEFAULT now(),
  respondido_at TIMESTAMPTZ,
  UNIQUE (inspeccion_id, item_codigo, mecanico_id),
  CHECK (estado <> 'respondido' OR (monto IS NOT NULL AND vigente_hasta IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS idx_presupuesto_mecanico ON presupuesto (mecanico_id, estado, created_at);

INSERT INTO parametro (clave, valor, minimo, maximo, unidad, descripcion, orden) VALUES
  ('presupuestos_por_arreglo', 3,  1, 10,  'veces', 'A cuántos talleres de la red se les pide presupuesto por cada arreglo del informe', 12),
  ('dias_presupuesto',         15, 1, 90,  'dias',  'Días que vale el presupuesto de un taller', 13),
  ('comision_arreglos',        6,  0, 50,  'porcentaje', 'Comisión que paga el taller por un arreglo que le llega por AutoCar (todavía no se cobra)', 14)
ON CONFLICT (clave) DO NOTHING;
