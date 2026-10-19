-- AutoCar · esquema de la base (PostgreSQL / Neon). Se ejecuta una vez en el SQL Editor de Neon
-- (o con psql). Es idempotente: se puede volver a correr sin romper datos.

CREATE TABLE IF NOT EXISTS users (
  id            INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nombre        VARCHAR(80)  NOT NULL,
  email         VARCHAR(254) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  rol           VARCHAR(10)  NOT NULL DEFAULT 'usuario' CHECK (rol IN ('usuario', 'admin')),
  created_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS vehiculos (
  id          INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  marca       VARCHAR(50)   NOT NULL,
  modelo      VARCHAR(60)   NOT NULL,
  anio        SMALLINT      NOT NULL CHECK (anio BETWEEN 1950 AND 2100),
  precio      NUMERIC(11,2) NOT NULL CHECK (precio > 0),
  kilometraje INTEGER       NOT NULL DEFAULT 0 CHECK (kilometraje >= 0),
  combustible VARCHAR(10)   NOT NULL CHECK (combustible IN ('nafta', 'diesel', 'gnc', 'hibrido', 'electrico')),
  transmision VARCHAR(10)   NOT NULL CHECK (transmision IN ('manual', 'automatica')),
  estado      VARCHAR(12)   NOT NULL DEFAULT 'disponible' CHECK (estado IN ('disponible', 'vendido')),
  descripcion TEXT,
  imagen_url  VARCHAR(255),
  created_at  TIMESTAMPTZ   NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ   NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_vehiculos_marca ON vehiculos (marca);
CREATE INDEX IF NOT EXISTS idx_vehiculos_estado_precio ON vehiculos (estado, precio);
