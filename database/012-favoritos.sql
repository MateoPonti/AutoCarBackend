-- AutoCar · favoritos: el usuario guarda vehículos para verlos después (GET/PUT/DELETE /api/favoritos).
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main) ANTES de desplegar el back que la usa. Es idempotente.
-- Una base nueva no la necesita: schema.sql ya trae todo.

CREATE TABLE IF NOT EXISTS favorito (
  usuario_id  INTEGER     NOT NULL REFERENCES usuario(id) ON DELETE CASCADE,
  vehiculo_id INTEGER     NOT NULL REFERENCES vehiculo(id) ON DELETE CASCADE,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (usuario_id, vehiculo_id)
);
