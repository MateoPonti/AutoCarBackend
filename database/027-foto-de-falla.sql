-- AutoCar · foto de cada falla del checklist (PENDIENTES.md del front, "Foto obligatoria al marcar una falla").
-- Si el mecánico marca un ítem con "falla", tiene que subir una foto de lo que vio: sin ella no firma.
-- En "observado" la foto es opcional. Es una foto por ítem (si sube otra, reemplaza la anterior) y se guarda en la
-- base, como la foto de la chapa. La ven los mismos que ven el informe.
-- Las inspecciones que ya estaban firmadas no tienen fotos: la regla vale para las que se firmen desde ahora.
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main), ANTES de desplegar el back que la usa. Es idempotente.
-- Una base nueva no la necesita: schema.sql ya trae todo.

CREATE TABLE IF NOT EXISTS inspeccion_item_foto (
  inspeccion_id INTEGER     NOT NULL REFERENCES inspeccion(id) ON DELETE CASCADE,
  codigo        VARCHAR(20) NOT NULL,
  mime          VARCHAR(20) NOT NULL,
  datos         BYTEA       NOT NULL,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (inspeccion_id, codigo)
);
