-- AutoCar · mensajes al equipo: contacto (público, con email) y reportes de publicaciones o usuarios (con sesión).
-- POST /api/contacto y POST /api/reportes; el admin y el moderador los ven en el panel.
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main) ANTES de desplegar el back que la usa. Es idempotente.
-- Una base nueva no la necesita: schema.sql ya trae todo.

CREATE TABLE IF NOT EXISTS contacto (
  id         INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  email      VARCHAR(254)  NOT NULL,
  mensaje    VARCHAR(1000) NOT NULL,
  leido      BOOLEAN       NOT NULL DEFAULT false,
  created_at TIMESTAMPTZ   NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_contacto_email ON contacto (email, created_at);

CREATE TABLE IF NOT EXISTS reporte (
  id          INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  autor_id    INTEGER      NOT NULL REFERENCES usuario(id) ON DELETE CASCADE,
  vehiculo_id INTEGER      REFERENCES vehiculo(id) ON DELETE CASCADE,
  usuario_id  INTEGER      REFERENCES usuario(id) ON DELETE CASCADE,
  motivo      VARCHAR(10)  NOT NULL CHECK (motivo IN ('fraude', 'datos', 'vendido', 'trato', 'spam', 'otro')),
  detalle     VARCHAR(500),
  estado      VARCHAR(10)  NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente', 'resuelto')),
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
  -- Se reporta una publicación o un usuario, nunca los dos ni ninguno
  CHECK ((vehiculo_id IS NULL) <> (usuario_id IS NULL))
);
