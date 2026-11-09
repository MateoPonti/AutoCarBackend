-- AutoCar · publicar por partes: el vendedor crea la publicación con marca, modelo y año, y completa el resto
-- (precio, kilometraje, combustible, transmisión, fotos...) cuando quiera. Mientras tanto queda 'pendiente'
-- e incompleta, y el moderador no la puede aprobar hasta que esté completa (lo controla el back).
-- Por las dudas, la base también lo controla: una publicación visible (disponible o vendida) tiene que tener
-- precio, kilometraje, combustible y transmisión.
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main) ANTES de desplegar el back que la usa. Es idempotente.
-- Una base nueva no la necesita: schema.sql ya trae todo.

ALTER TABLE vehiculo ALTER COLUMN precio      DROP NOT NULL;
ALTER TABLE vehiculo ALTER COLUMN kilometraje DROP NOT NULL;
ALTER TABLE vehiculo ALTER COLUMN kilometraje DROP DEFAULT;
ALTER TABLE vehiculo ALTER COLUMN combustible DROP NOT NULL;
ALTER TABLE vehiculo ALTER COLUMN transmision DROP NOT NULL;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'vehiculo_completo_si_visible') THEN
    ALTER TABLE vehiculo ADD CONSTRAINT vehiculo_completo_si_visible CHECK (
      estado NOT IN ('disponible', 'vendido')
      OR (precio IS NOT NULL AND kilometraje IS NOT NULL AND combustible IS NOT NULL AND transmision IS NOT NULL)
    );
  END IF;
END $$;
