-- AutoCar · cobro de la inspección con Mercado Pago (Checkout Pro, API de Orders, ver Pagos\Cobros).
-- Una fila por inspección cobrada: la order de Mercado Pago, su link de pago y cómo va el cobro.
--   pendiente: falta que el comprador pague (el mecánico no la puede aceptar) · pagado · cancelado: se cayó sin pagar
--   devuelto: se le devolvió por Mercado Pago (rechazada, cancelada, no realizada, caída o reclamo que procedió)
-- Solo se usa con MP_ACTIVO=true en el back. Con MP_ACTIVO=false (o sin correr esta migración) el pago sigue simulado.
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main). Es idempotente.
-- Una base nueva no la necesita: schema.sql ya trae todo.

CREATE TABLE IF NOT EXISTS cobro (
  inspeccion_id INTEGER       PRIMARY KEY REFERENCES inspeccion(id) ON DELETE CASCADE,
  monto         NUMERIC(12,2) NOT NULL CHECK (monto > 0),       -- lo que paga el comprador: precio − crédito usado
  orden_id      VARCHAR(64),                                     -- id de la order en Mercado Pago (ORD...)
  checkout_url  TEXT,                                            -- adónde se manda al comprador a pagar
  intento       INTEGER       NOT NULL DEFAULT 1,                -- sube si hay que armar otra order (la anterior venció)
  estado        VARCHAR(10)   NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente', 'pagado', 'cancelado', 'devuelto')),
  estado_mp     VARCHAR(30),                                     -- el status de la order en Mercado Pago, tal cual
  pagado_at     TIMESTAMPTZ,
  devuelto_at   TIMESTAMPTZ,
  created_at    TIMESTAMPTZ   NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ   NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_cobro_orden ON cobro (orden_id);
