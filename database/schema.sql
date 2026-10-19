-- AutoCar · esquema de la base (MySQL 8 / Aiven). Se importa una sola vez:
--   mysql -h HOST -P PUERTO -u USUARIO -p --ssl-ca=database/ca.pem NOMBRE_BASE < database/schema.sql
-- (en local, sin --ssl-ca). Es idempotente: se puede volver a correr sin romper datos.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre        VARCHAR(80)  NOT NULL,
  email         VARCHAR(254) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  rol           ENUM('usuario','admin') NOT NULL DEFAULT 'usuario',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehiculos (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  marca       VARCHAR(50)  NOT NULL,
  modelo      VARCHAR(60)  NOT NULL,
  anio        SMALLINT UNSIGNED NOT NULL,
  precio      DECIMAL(11,2) NOT NULL,
  kilometraje INT UNSIGNED NOT NULL DEFAULT 0,
  combustible ENUM('nafta','diesel','gnc','hibrido','electrico') NOT NULL,
  transmision ENUM('manual','automatica') NOT NULL,
  estado      ENUM('disponible','vendido') NOT NULL DEFAULT 'disponible',
  descripcion TEXT NULL,
  imagen      VARCHAR(100) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_vehiculos_marca (marca),
  KEY idx_vehiculos_estado_precio (estado, precio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
