-- AutoCar · inspección "no_realizada": el mecánico aceptó, fue y no la pudo hacer (por ejemplo, el vendedor no dejó
-- revisar el auto). Se le devuelve todo al comprador y el motivo va en motivo_rechazo.
-- El estado pasa de VARCHAR(10) a VARCHAR(12) porque "no_realizada" tiene 12 letras.
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main). Es idempotente. Correrla **antes** de desplegar el back
-- que la usa. Una base nueva no la necesita: schema.sql ya la trae.

ALTER TABLE inspeccion ALTER COLUMN estado TYPE VARCHAR(12);

ALTER TABLE inspeccion DROP CONSTRAINT IF EXISTS inspeccion_estado_check;
ALTER TABLE inspeccion ADD CONSTRAINT inspeccion_estado_check
  CHECK (estado IN ('pedida', 'aceptada', 'rechazada', 'cancelada', 'firmada', 'no_realizada'));
