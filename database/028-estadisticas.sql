-- AutoCar · estadísticas del admin: de dónde viene cada inspección y qué recomendaciones vio cada comprador,
-- y la fórmula de "para vos" como parámetros editables.
-- Con esto el panel puede contestar "de los que vieron una recomendación, ¿cuántos pagaron la inspección?".
-- "Compró" en las estadísticas = pagó la inspección (no sabemos si después compró el auto en otro sitio).
-- origen: desde dónde tocó "Mandale un mecánico":
--   para_vos     → un auto marcado "para vos" según su perfil (metabuscador)
--   destacado    → uno de los destacados del metabuscador (más barato, mejor relación, menos usado)
--   metabuscador → otro auto de la lista del metabuscador
--   publicacion  → la ficha de una publicación de AutoCar
-- Las inspecciones anteriores quedan con origen NULL ("sin dato").
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main), ANTES de desplegar el back que la usa. Es idempotente.
-- Una base nueva no la necesita: schema.sql ya trae todo.

ALTER TABLE inspeccion ADD COLUMN IF NOT EXISTS origen VARCHAR(15)
  CHECK (origen IN ('para_vos', 'destacado', 'metabuscador', 'publicacion'));

-- mostrado = el comprador tuvo la recomendación en pantalla; tocado = tocó "Mandale un mecánico" ahí.
-- El front manda cada par (tipo, origen, ref) una sola vez por sesión del navegador.
CREATE TABLE IF NOT EXISTS evento (
  id          BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  usuario_id  INTEGER      NOT NULL REFERENCES usuario(id) ON DELETE CASCADE,
  tipo        VARCHAR(8)   NOT NULL CHECK (tipo IN ('mostrado', 'tocado')),
  origen      VARCHAR(15)  NOT NULL CHECK (origen IN ('para_vos', 'destacado', 'metabuscador', 'publicacion')),
  ref         VARCHAR(500),
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_evento_origen ON evento (origen, tipo, created_at);

-- La fórmula de "para vos" (MetaBusqueda\ParaVos): cuánto pesa cada cosa en cada perfil, de 0 (no cuenta) a 5.
-- Antes estaba fija en el código; ahora el admin la cambia en Parámetros (por ejemplo, si el embudo muestra que
-- "para vos" convierte poco). Unidad nueva: 'puntos'.
ALTER TABLE parametro DROP CONSTRAINT IF EXISTS parametro_unidad_check;
ALTER TABLE parametro ADD CONSTRAINT parametro_unidad_check CHECK (unidad IN ('porcentaje', 'pesos', 'dias', 'veces', 'horas', 'puntos'));
INSERT INTO parametro (clave, valor, minimo, maximo, unidad, descripcion, orden) VALUES
  ('peso_personal_precio', 3, 0, 5, 'puntos', 'Para vos (uso personal): cuánto pesa el precio', 18),
  ('peso_personal_km', 2, 0, 5, 'puntos', 'Para vos (uso personal): cuánto pesan los kilómetros', 19),
  ('peso_personal_dias', 2, 0, 5, 'puntos', 'Para vos (uso personal): cuánto pesan los días publicado', 20),
  ('peso_personal_anio', 0, 0, 5, 'puntos', 'Para vos (uso personal): cuánto pesa el año', 21),
  ('peso_familia_precio', 2, 0, 5, 'puntos', 'Para vos (familia): cuánto pesa el precio', 22),
  ('peso_familia_km', 3, 0, 5, 'puntos', 'Para vos (familia): cuánto pesan los kilómetros', 23),
  ('peso_familia_dias', 1, 0, 5, 'puntos', 'Para vos (familia): cuánto pesan los días publicado', 24),
  ('peso_familia_anio', 1, 0, 5, 'puntos', 'Para vos (familia): cuánto pesa el año', 25),
  ('peso_trabajo_precio', 3, 0, 5, 'puntos', 'Para vos (trabajo): cuánto pesa el precio', 26),
  ('peso_trabajo_km', 1, 0, 5, 'puntos', 'Para vos (trabajo): cuánto pesan los kilómetros', 27),
  ('peso_trabajo_dias', 1, 0, 5, 'puntos', 'Para vos (trabajo): cuánto pesan los días publicado', 28),
  ('peso_trabajo_anio', 0, 0, 5, 'puntos', 'Para vos (trabajo): cuánto pesa el año', 29),
  ('peso_aplicacion_precio', 2, 0, 5, 'puntos', 'Para vos (aplicación (Uber, Cabify)): cuánto pesa el precio', 30),
  ('peso_aplicacion_km', 2, 0, 5, 'puntos', 'Para vos (aplicación (Uber, Cabify)): cuánto pesan los kilómetros', 31),
  ('peso_aplicacion_dias', 1, 0, 5, 'puntos', 'Para vos (aplicación (Uber, Cabify)): cuánto pesan los días publicado', 32),
  ('peso_aplicacion_anio', 3, 0, 5, 'puntos', 'Para vos (aplicación (Uber, Cabify)): cuánto pesa el año', 33)
ON CONFLICT (clave) DO NOTHING;
