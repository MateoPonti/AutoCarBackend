-- AutoCar · Meta-búsqueda: autos de otras plataformas, su historial y las búsquedas de los usuarios.
-- Se ejecuta UNA vez en el SQL Editor de Neon, ANTES de desplegar el back que la usa. Es idempotente.
-- Solo agrega tablas: no modifica las existentes. Una base nueva no la necesita: schema.sql ya la trae.

-- Plataformas de donde vienen los autos (la crea el importador la primera vez que la ve).
CREATE TABLE IF NOT EXISTS fuente (
  id     INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nombre VARCHAR(30) NOT NULL UNIQUE
);

-- Una corrida de un recolector. Solo las completas sirven para comparar y detectar autos retirados.
CREATE TABLE IF NOT EXISTS recoleccion (
  id        INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  fuente_id INTEGER      NOT NULL REFERENCES fuente(id),
  fecha     TIMESTAMPTZ  NOT NULL,
  alcance   VARCHAR(100) NOT NULL DEFAULT 'todo',
  completo  BOOLEAN      NOT NULL,
  autos     INTEGER      NOT NULL CHECK (autos >= 0),
  UNIQUE (fuente_id, fecha)
);

-- Un auto de otra plataforma. Lo que cambia semana a semana (precio, km) va en auto_foto_semanal.
-- No se borra cuando deja de publicarse: pasa a estado 'retirado' y conserva su historial.
CREATE TABLE IF NOT EXISTS auto_externo (
  id          INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  fuente_id   INTEGER      NOT NULL REFERENCES fuente(id),
  id_externo  VARCHAR(60)  NOT NULL,
  marca       VARCHAR(50)  NOT NULL,
  modelo      VARCHAR(60)  NOT NULL,
  version     VARCHAR(120),
  anio        SMALLINT     NOT NULL CHECK (anio BETWEEN 1950 AND 2100),
  transmision VARCHAR(10)  CHECK (transmision IN ('manual', 'automatica')),
  ubicacion   VARCHAR(100),
  url         VARCHAR(500),
  primera_vez TIMESTAMPTZ  NOT NULL,
  ultima_vez  TIMESTAMPTZ  NOT NULL,
  estado      VARCHAR(10)  NOT NULL CHECK (estado IN ('activo', 'reservado', 'retirado')),
  UNIQUE (fuente_id, id_externo)
);

CREATE INDEX IF NOT EXISTS idx_auto_externo_modelo ON auto_externo (lower(marca), lower(modelo));

-- precio: el publicado de forma destacada en la plataforma. precio_contado: solo si la plataforma lo informa.
-- Sin precio (por ejemplo, reservado) queda NULL y no cuenta en las estadísticas.
CREATE TABLE IF NOT EXISTS auto_foto_semanal (
  recoleccion_id INTEGER       NOT NULL REFERENCES recoleccion(id) ON DELETE CASCADE,
  auto_id        INTEGER       NOT NULL REFERENCES auto_externo(id) ON DELETE CASCADE,
  precio         NUMERIC(14,2) CHECK (precio > 0),
  precio_contado NUMERIC(14,2) CHECK (precio_contado > 0),
  moneda         VARCHAR(3)    NOT NULL CHECK (moneda IN ('ARS', 'USD')),
  km             INTEGER       NOT NULL CHECK (km >= 0),
  reservado      BOOLEAN       NOT NULL DEFAULT false,
  PRIMARY KEY (recoleccion_id, auto_id)
);

CREATE INDEX IF NOT EXISTS idx_auto_foto_auto ON auto_foto_semanal (auto_id);

-- Lo que un usuario quiere seguir. Marca y modelo son obligatorios; el resto, opcional.
CREATE TABLE IF NOT EXISTS busqueda_guardada (
  id          INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  usuario_id  INTEGER       NOT NULL REFERENCES usuario(id),
  marca       VARCHAR(50)   NOT NULL,
  modelo      VARCHAR(60)   NOT NULL,
  anio_desde  SMALLINT      CHECK (anio_desde BETWEEN 1950 AND 2100),
  anio_hasta  SMALLINT      CHECK (anio_hasta BETWEEN 1950 AND 2100),
  precio_max  NUMERIC(14,2) CHECK (precio_max > 0),
  km_max      INTEGER       CHECK (km_max >= 0),
  transmision VARCHAR(10)   CHECK (transmision IN ('manual', 'automatica')),
  created_at  TIMESTAMPTZ   NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ   NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_busqueda_usuario ON busqueda_guardada (usuario_id);

-- Avisos: lo que cambió para una búsqueda en una recolección completa.
CREATE TABLE IF NOT EXISTS coincidencia (
  id              INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  busqueda_id     INTEGER       NOT NULL REFERENCES busqueda_guardada(id) ON DELETE CASCADE,
  auto_id         INTEGER       NOT NULL REFERENCES auto_externo(id) ON DELETE CASCADE,
  recoleccion_id  INTEGER       NOT NULL REFERENCES recoleccion(id) ON DELETE CASCADE,
  tipo            VARCHAR(10)   NOT NULL CHECK (tipo IN ('nuevo', 'bajo', 'reservado', 'retirado')),
  precio_anterior NUMERIC(14,2),
  precio_actual   NUMERIC(14,2),
  fecha           TIMESTAMPTZ   NOT NULL,
  visto           BOOLEAN       NOT NULL DEFAULT false,
  UNIQUE (busqueda_id, auto_id, recoleccion_id, tipo)
);

CREATE INDEX IF NOT EXISTS idx_coincidencia_busqueda ON coincidencia (busqueda_id, visto);
