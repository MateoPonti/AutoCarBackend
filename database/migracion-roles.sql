-- AutoCar · roles y permisos en tablas (reemplaza la columna usuario.rol de texto). Se ejecuta UNA vez en el
-- SQL Editor de Neon, DESPUÉS de las otras migraciones y ANTES de desplegar el back que usa permisos.
-- Es idempotente: se puede volver a correr. Una base nueva no la necesita: schema.sql ya trae todo.

-- Un usuario tiene UN rol; un rol tiene VARIOS permisos. Los endpoints piden permisos, no roles:
-- para crear un rol nuevo alcanza con darle permisos acá, sin tocar el código.
CREATE TABLE IF NOT EXISTS rol (
  id          INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nombre      VARCHAR(20)  NOT NULL UNIQUE,
  descripcion VARCHAR(150) NOT NULL
);

CREATE TABLE IF NOT EXISTS permiso (
  id          INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nombre      VARCHAR(50)  NOT NULL UNIQUE,   -- "recurso.accion", el que exige cada ruta en routes.php
  descripcion VARCHAR(150) NOT NULL
);

CREATE TABLE IF NOT EXISTS rol_permiso (
  rol_id     INTEGER NOT NULL REFERENCES rol(id) ON DELETE CASCADE,
  permiso_id INTEGER NOT NULL REFERENCES permiso(id) ON DELETE CASCADE,
  PRIMARY KEY (rol_id, permiso_id)
);

INSERT INTO rol (nombre, descripcion) VALUES
  ('usuario',   'Usuario registrado: publica vehículos y chatea con otros usuarios'),
  ('moderador', 'Revisa las publicaciones: las aprueba o las rechaza'),
  ('admin',     'Puede todo: usuarios, catálogo, moderación y métricas'),
  ('mecanico',  'Mecánico de un taller adherido: verifica vehículos')
ON CONFLICT (nombre) DO NOTHING;

INSERT INTO permiso (nombre, descripcion) VALUES
  ('vehiculos.moderar',       'Ver la cola de publicaciones y aprobarlas o rechazarlas'),
  ('vehiculos.gestionar',     'Alta, edición y baja de vehículos del catálogo'),
  ('usuarios.ver',            'Ver la lista y los datos de los usuarios'),
  ('usuarios.editar',         'Editar usuarios: nombre, rol y baja lógica'),
  ('verificaciones.ver',      'Ver las verificaciones de vehículos asignadas al taller'),
  ('verificaciones.realizar', 'Cargar el resultado de una verificación'),
  ('metricas.ver',            'Ver las métricas de la aplicación')
ON CONFLICT (nombre) DO NOTHING;

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM (VALUES
  ('moderador', 'vehiculos.moderar'),
  ('mecanico',  'verificaciones.ver'),
  ('mecanico',  'verificaciones.realizar')
) AS rp(rol, permiso)
JOIN rol r ON r.nombre = rp.rol
JOIN permiso p ON p.nombre = rp.permiso
ON CONFLICT DO NOTHING;

UPDATE rol SET descripcion = 'Puede todo: usuarios, catálogo, moderación y métricas' WHERE nombre = 'admin';

-- El admin tiene TODOS los permisos (también los que se agreguen después, al volver a correr esto)
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM rol r CROSS JOIN permiso p WHERE r.nombre = 'admin'
ON CONFLICT DO NOTHING;

-- usuario.rol (texto) pasa a usuario.rol_id (FK). activo = false es la baja lógica.
ALTER TABLE usuario ADD COLUMN IF NOT EXISTS rol_id INTEGER REFERENCES rol(id);
ALTER TABLE usuario ADD COLUMN IF NOT EXISTS activo BOOLEAN NOT NULL DEFAULT true;

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'usuario' AND column_name = 'rol') THEN
    UPDATE usuario u SET rol_id = r.id FROM rol r WHERE r.nombre = u.rol AND u.rol_id IS NULL;
    ALTER TABLE usuario DROP COLUMN rol;
  END IF;
END $$;

UPDATE usuario SET rol_id = (SELECT id FROM rol WHERE nombre = 'usuario') WHERE rol_id IS NULL;
ALTER TABLE usuario ALTER COLUMN rol_id SET NOT NULL;
CREATE INDEX IF NOT EXISTS idx_usuario_rol ON usuario (rol_id);
