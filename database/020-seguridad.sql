-- AutoCar · dos arreglos de seguridad de la Entrega 4:
--  1. Un comprador no puede tener dos pedidos en curso del mismo aviso. El back ya lo chequeaba, pero si llegaban
--     seis pedidos a la vez pasaban los seis. Ahora lo frena la base: cada inspección guarda la clave del aviso
--     (url_clave, la arma Inspecciones\Fuente::clave) y un índice único no deja dos 'pedida'/'aceptada' iguales.
--  2. Límite de intentos de ingreso: login_intento anota cada intento por email (ver Auth\IntentosLogin).
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main), ANTES de desplegar el back que la usa. Es idempotente.
-- Si falla con "could not create unique index", hay un comprador con dos pedidos en curso del mismo aviso: verlos con
--   SELECT comprador_id, url_clave, array_agg(id) FROM inspeccion
--    WHERE estado IN ('pedida', 'aceptada') GROUP BY 1, 2 HAVING COUNT(*) > 1;
-- cancelar los que sobran y volver a correrla. Una base nueva no la necesita: schema.sql ya trae todo.

ALTER TABLE inspeccion ADD COLUMN IF NOT EXISTS url_clave VARCHAR(500);

-- Las que ya existen: la misma regla que Fuente::clave (si cambia una, cambiar la otra)
UPDATE inspeccion SET url_clave = CASE
    WHEN fuente = 'autocar' AND vehiculo_id IS NOT NULL THEN 'autocar:' || vehiculo_id
    WHEN fuente = 'mercadolibre' AND url ~* 'MLA-?[0-9]+' THEN 'mercadolibre:MLA' || substring(url FROM '[Mm][Ll][Aa]-?([0-9]+)')
    ELSE rtrim(lower(regexp_replace(regexp_replace(btrim(url), '[?#].*$', ''), '^https?://(www\.)?', '', 'i')), '/')
  END
WHERE url_clave IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS inspeccion_una_en_curso
  ON inspeccion (comprador_id, url_clave) WHERE estado IN ('pedida', 'aceptada');

CREATE TABLE IF NOT EXISTS login_intento (
  id         INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  email      VARCHAR(254) NOT NULL,
  created_at TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_login_intento_email ON login_intento (email, created_at);
