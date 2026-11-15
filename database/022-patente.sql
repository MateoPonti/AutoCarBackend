-- AutoCar · patente del auto inspeccionado (decidido el 14/11, ideas-14-11-2026/decisiones-14-11-2026.md del front).
-- La carga el mecánico al ver el auto, con una foto de la chapa como control, y es obligatoria para firmar: el informe
-- "queda en la patente" y con ella se arma el historial del auto aunque el aviso cambie o se borre.
-- Se guarda solo la patente y la foto de la chapa: nada del dueño.
--
-- Las inspecciones que ya estaban firmadas no tenían patente. En lugar de borrar la base, se les inventa una
-- (formato Mercosur, terminada en "DM" de demo), la misma para todas las del mismo aviso: así el historial de un
-- auto con más de una inspección se puede mostrar. Esas no tienen foto de la chapa.
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main), ANTES de desplegar el back que la usa. Es idempotente.
-- Una base nueva no la necesita: schema.sql ya trae todo.

ALTER TABLE inspeccion ADD COLUMN IF NOT EXISTS patente VARCHAR(7);

-- Patentes inventadas para las firmadas que no tienen: una por aviso (url_clave), en orden de la primera firma
WITH avisos AS (
  SELECT url_clave, ROW_NUMBER() OVER (ORDER BY MIN(firmada_at), url_clave) AS n
    FROM inspeccion
   WHERE estado = 'firmada' AND patente IS NULL
   GROUP BY url_clave
)
UPDATE inspeccion i
   SET patente = 'AD' || lpad(((a.n - 1) % 1000)::text, 3, '0') || 'DM'
  FROM avisos a
 WHERE i.estado = 'firmada' AND i.patente IS NULL AND i.url_clave IS NOT DISTINCT FROM a.url_clave;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'inspeccion_patente_formato') THEN
    ALTER TABLE inspeccion ADD CONSTRAINT inspeccion_patente_formato
      CHECK (patente IS NULL OR patente ~ '^([A-Z]{3}[0-9]{3}|[A-Z]{2}[0-9]{3}[A-Z]{2})$');
  END IF;
  -- Sin patente no se firma (la foto de la chapa la exige el back: las inventadas de arriba no tienen)
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'inspeccion_firmada_con_patente') THEN
    ALTER TABLE inspeccion ADD CONSTRAINT inspeccion_firmada_con_patente
      CHECK (estado <> 'firmada' OR patente IS NOT NULL);
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_inspeccion_patente ON inspeccion (patente) WHERE patente IS NOT NULL;

-- Foto de la chapa (en la base, como las demás fotos): una por inspección
CREATE TABLE IF NOT EXISTS inspeccion_patente_foto (
  inspeccion_id INTEGER     PRIMARY KEY REFERENCES inspeccion(id) ON DELETE CASCADE,
  mime          VARCHAR(20) NOT NULL,
  datos         BYTEA       NOT NULL,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
