-- AutoCar · toda publicación tiene un vendedor. Antes el admin podía cargar vehículos sin dueño (y el seed también
-- lo hacía), y en esos autos el chat respondía "no tiene un vendedor". Ahora nadie del panel publica ni edita:
-- toda publicación entra por POST /api/mis-vehiculos con el usuario logueado como vendedor, y la base lo exige.
-- Los vehículos que hoy no tienen dueño (los del seed y los cargados por el admin) pasan a la cuenta demo
-- vendedor-demo@autocar.test (contraseña VendedorDemo2026), que puede ingresar y contestar los chats.
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main) ANTES de desplegar el back que la usa. Es idempotente.
-- Una base nueva no la necesita: schema.sql y seed.sql ya traen todo.

-- 1) La cuenta demo, solo si hay vehículos sin dueño
INSERT INTO usuario (nombre, email, password_hash, rol_id)
SELECT 'Vendedor demo', 'vendedor-demo@autocar.test',
       '$2y$12$GN2ml5KX.hieSOh03pG3duNDCz0eUyLRZjZEVC19t31vWceomEfu2',
       (SELECT id FROM rol WHERE nombre = 'usuario')
WHERE EXISTS (SELECT 1 FROM vehiculo WHERE usuario_id IS NULL)
ON CONFLICT (email) DO NOTHING;

-- 2) Los vehículos sin dueño pasan a la cuenta demo
UPDATE vehiculo
SET usuario_id = (SELECT id FROM usuario WHERE email = 'vendedor-demo@autocar.test')
WHERE usuario_id IS NULL;

-- 3) Desde ahora la base no acepta un vehículo sin vendedor
ALTER TABLE vehiculo ALTER COLUMN usuario_id SET NOT NULL;

-- 4) vehiculos.gestionar ya no es alta y edición: el admin solo da de baja
UPDATE permiso SET descripcion = 'Dar de baja vehículos del catálogo' WHERE nombre = 'vehiculos.gestionar';
UPDATE rol SET descripcion = 'Puede todo: usuarios, moderación, bajas y métricas' WHERE nombre = 'admin';
