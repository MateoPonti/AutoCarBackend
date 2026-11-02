-- AutoCar · renombra las tablas a singular (users → usuario, vehiculos → vehiculo, vehiculo_fotos → vehiculo_foto).
-- Se ejecuta UNA vez en el SQL Editor de Neon, después de migracion-e4.sql. No borra datos y es idempotente.
-- Una base nueva no la necesita: schema.sql ya usa los nombres nuevos.

ALTER TABLE IF EXISTS users          RENAME TO usuario;
ALTER TABLE IF EXISTS vehiculos      RENAME TO vehiculo;
ALTER TABLE IF EXISTS vehiculo_fotos RENAME TO vehiculo_foto;

ALTER INDEX IF EXISTS idx_vehiculos_marca         RENAME TO idx_vehiculo_marca;
ALTER INDEX IF EXISTS idx_vehiculos_estado_precio RENAME TO idx_vehiculo_estado_precio;
ALTER INDEX IF EXISTS idx_vehiculos_usuario       RENAME TO idx_vehiculo_usuario;

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'vehiculos_estado_check') THEN
    ALTER TABLE vehiculo RENAME CONSTRAINT vehiculos_estado_check TO vehiculo_estado_check;
  END IF;
END $$;
