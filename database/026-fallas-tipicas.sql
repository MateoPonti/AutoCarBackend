-- AutoCar · idea B, fallas típicas por modelo (pedido de la cátedra en el mapa de capas del 10/11).
--  1. Cada inspección guarda la marca y el modelo del auto, con los nombres del catálogo (la misma clave que usa el
--     comparador, Comparador\Clave). Si el auto es de AutoCar salen de la publicación; si no, los elige el comprador
--     o los completa el mecánico (obligatorios para firmar). A las que ya existen se les completan con la publicación
--     o con el título del aviso, si empieza con una marca y un modelo del catálogo.
--  2. falla_tipica: cada falla con su origen: 'internet' (la carga el admin con la fuente) o 'mecanicos' (la propone un
--     mecánico). El tercer origen, 'medido', no se guarda: sale de las inspecciones firmadas (qué % de los autos de ese
--     modelo tuvo ese ítem observado o con falla) y no se muestra con menos de fallas_minimo_inspecciones (20).
--  3. falla_voto: el voto de cada mecánico (la vio / no es típica). Con fallas_votos_confirmar votos de un lado la
--     falla pasa a confirmada o descartada.
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main), ANTES de desplegar el back que la usa. Es idempotente.
-- Una base nueva no la necesita: schema.sql ya trae todo.

-- 1. Marca y modelo de cada inspección
ALTER TABLE inspeccion ADD COLUMN IF NOT EXISTS marca  VARCHAR(60);
ALTER TABLE inspeccion ADD COLUMN IF NOT EXISTS modelo VARCHAR(60);
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'inspeccion_modelo_fk') THEN
    ALTER TABLE inspeccion ADD CONSTRAINT inspeccion_modelo_fk
      FOREIGN KEY (marca, modelo) REFERENCES modelo (marca, nombre) ON UPDATE CASCADE;
  END IF;
END $$;
CREATE INDEX IF NOT EXISTS idx_inspeccion_modelo ON inspeccion (marca, modelo) WHERE estado = 'firmada';

-- Las que ya existen: de la publicación de AutoCar...
UPDATE inspeccion i SET marca = v.marca, modelo = v.modelo
  FROM vehiculo v WHERE v.id = i.vehiculo_id AND i.marca IS NULL;
-- ...o del título del aviso ("Toyota Etios XLS 1.5" → Toyota, Etios), con el modelo más largo que coincida
UPDATE inspeccion i SET marca = x.marca, modelo = x.nombre
  FROM (SELECT DISTINCT ON (i2.id) i2.id, mo.marca, mo.nombre
          FROM inspeccion i2
          JOIN modelo mo ON lower(i2.titulo) = lower(mo.marca || ' ' || mo.nombre)
                         OR lower(i2.titulo) LIKE lower(mo.marca || ' ' || mo.nombre) || ' %'
         WHERE i2.marca IS NULL AND i2.titulo IS NOT NULL
         ORDER BY i2.id, length(mo.nombre) DESC) x
 WHERE x.id = i.id;

-- 2. Fallas típicas
CREATE TABLE IF NOT EXISTS falla_tipica (
  id          INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  marca       VARCHAR(60)  NOT NULL,
  modelo      VARCHAR(60)  NOT NULL,
  item_codigo VARCHAR(20)  NOT NULL,              -- el ítem del checklist donde se ve (Inspecciones\Checklist)
  titulo      VARCHAR(120) NOT NULL,
  descripcion VARCHAR(500),
  origen      VARCHAR(10)  NOT NULL CHECK (origen IN ('internet', 'mecanicos')),
  fuente_url  VARCHAR(500),                       -- de dónde salió (obligatoria si es de internet)
  creada_por  INTEGER      REFERENCES usuario(id) ON DELETE SET NULL,
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
  FOREIGN KEY (marca, modelo) REFERENCES modelo (marca, nombre) ON UPDATE CASCADE,
  CHECK (origen <> 'internet' OR fuente_url IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS idx_falla_tipica_modelo ON falla_tipica (marca, modelo);

-- 3. Votos de los mecánicos (uno por mecánico y falla; lo puede cambiar)
CREATE TABLE IF NOT EXISTS falla_voto (
  falla_id    INTEGER     NOT NULL REFERENCES falla_tipica(id) ON DELETE CASCADE,
  mecanico_id INTEGER     NOT NULL REFERENCES usuario(id) ON DELETE CASCADE,
  confirma    BOOLEAN     NOT NULL,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (falla_id, mecanico_id)
);

INSERT INTO parametro (clave, valor, minimo, maximo, unidad, descripcion, orden) VALUES
  ('fallas_minimo_inspecciones', 20, 1, 1000, 'veces',      'Inspecciones firmadas de un modelo que hacen falta para mostrar cuánto se midió cada falla', 15),
  ('fallas_votos_confirmar',     3,  1, 50,   'veces',      'Votos de mecánicos que hacen falta para confirmar o descartar una falla típica', 16),
  ('fallas_porcentaje_medido',   20, 1, 100,  'porcentaje', 'Desde qué % de inspecciones con el ítem observado o con falla se lo muestra como falla medida', 17)
ON CONFLICT (clave) DO NOTHING;
