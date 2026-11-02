-- AutoCar · sesiones de PHP guardadas en la base (reemplaza al JWT). Se ejecuta UNA vez en el SQL Editor de Neon,
-- ANTES de desplegar el back que usa $_SESSION (si no, el login da error 500). Es idempotente.
-- Una base nueva no la necesita: schema.sql ya la trae.

-- Las sesiones van en la base y no en archivos porque el disco de Render se borra en cada deploy.
-- id = el valor de la cookie autocar_sesion; datos = $_SESSION serializado por PHP.
CREATE TABLE IF NOT EXISTS sesion (
  id    VARCHAR(256) PRIMARY KEY,
  datos TEXT         NOT NULL,
  vence TIMESTAMPTZ  NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sesion_vence ON sesion (vence);
