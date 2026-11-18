-- AutoCar · estadísticas del admin: de dónde viene cada inspección y qué recomendaciones vio cada comprador.
-- Con esto el panel puede contestar "de los que vieron una recomendación, ¿cuántos pagaron la inspección?".
-- "Compró" en las estadísticas = pagó la inspección (no sabemos si después compró el auto en otro sitio).
-- origen: desde dónde tocó "Mandale un mecánico":
--   para_vos     → un auto marcado "para vos" según su perfil (metabuscador)
--   destacado    → uno de los destacados del metabuscador (más barato, mejor relación, menos usado)
--   metabuscador → otro auto de la lista del metabuscador
--   publicacion  → la ficha de una publicación de AutoCar
--   ranking      → el ranking de mecánicos
-- Las inspecciones anteriores quedan con origen NULL ("sin dato").
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main), ANTES de desplegar el back que la usa. Es idempotente.
-- Una base nueva no la necesita: schema.sql ya trae todo.

ALTER TABLE inspeccion ADD COLUMN IF NOT EXISTS origen VARCHAR(15)
  CHECK (origen IN ('para_vos', 'destacado', 'metabuscador', 'publicacion', 'ranking'));

-- mostrado = el comprador tuvo la recomendación en pantalla; tocado = tocó "Mandale un mecánico" ahí.
-- El front manda cada par (tipo, origen, ref) una sola vez por sesión del navegador.
CREATE TABLE IF NOT EXISTS evento (
  id          BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  usuario_id  INTEGER      NOT NULL REFERENCES usuario(id) ON DELETE CASCADE,
  tipo        VARCHAR(8)   NOT NULL CHECK (tipo IN ('mostrado', 'tocado')),
  origen      VARCHAR(15)  NOT NULL CHECK (origen IN ('para_vos', 'destacado', 'metabuscador', 'publicacion', 'ranking')),
  ref         VARCHAR(500),
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_evento_origen ON evento (origen, tipo, created_at);
