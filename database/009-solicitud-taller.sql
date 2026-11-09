-- AutoCar · "Adherí tu taller": un usuario pide sumarse a la red de mecánicos y un admin aprueba o rechaza.
-- Mientras la solicitud está pendiente, el taller NO aparece en el ranking y el usuario sigue con rol usuario.
-- Al aprobarla, el usuario pasa a rol mecanico y su taller entra al ranking.
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main), DESPUÉS de 006-inspecciones.sql y ANTES de
-- desplegar el back que la usa. Es idempotente. Una base nueva no la necesita: schema.sql ya trae todo.
--
-- Los talleres que ya existían (los de demo y los que cargó un mecánico desde su perfil) quedan aprobados.

ALTER TABLE taller ADD COLUMN IF NOT EXISTS cuit           VARCHAR(13);
ALTER TABLE taller ADD COLUMN IF NOT EXISTS horarios       VARCHAR(80);
ALTER TABLE taller ADD COLUMN IF NOT EXISTS estado         VARCHAR(10) NOT NULL DEFAULT 'aprobado';
ALTER TABLE taller ADD COLUMN IF NOT EXISTS motivo_rechazo VARCHAR(300);
ALTER TABLE taller ADD COLUMN IF NOT EXISTS resuelto_por   INTEGER REFERENCES usuario(id);
ALTER TABLE taller ADD COLUMN IF NOT EXISTS resuelto_at    TIMESTAMPTZ;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'taller_estado_check') THEN
    ALTER TABLE taller ADD CONSTRAINT taller_estado_check CHECK (estado IN ('pendiente', 'aprobado', 'rechazado'));
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_taller_estado ON taller (estado, created_at);
