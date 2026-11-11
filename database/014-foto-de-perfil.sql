-- AutoCar · foto de perfil opcional (POST/DELETE /api/me/foto, GET /api/usuarios/{id}/foto). No se pide al
-- registrarse; se sube desde "Mi cuenta". Se guarda en la base (BYTEA), igual que las fotos de las publicaciones,
-- y se ve en el detalle de las publicaciones del usuario y en el chat.
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main) ANTES de desplegar el back que la usa. Es idempotente.
-- Una base nueva no la necesita: schema.sql ya trae todo.

CREATE TABLE IF NOT EXISTS usuario_foto (
  usuario_id INTEGER     PRIMARY KEY REFERENCES usuario(id) ON DELETE CASCADE,
  mime       VARCHAR(20) NOT NULL CHECK (mime IN ('image/jpeg', 'image/png', 'image/webp')),
  datos      BYTEA       NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
