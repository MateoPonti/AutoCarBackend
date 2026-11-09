-- AutoCar · datos de DEMO de la red de mecánicos. SOLO para la base de dev (Neon dev o la local), nunca en main.
-- Se ejecuta después de schema.sql (o de 006-inspecciones.sql). Es idempotente.
-- 5 mecánicos de Mercedes y Luján con su taller, marcados con demo = true para poder borrarlos:
--   DELETE FROM taller WHERE demo; DELETE FROM usuario WHERE email LIKE '%@demo.autocar.com.ar';
-- Contraseña de todos: mecanico-demo
-- Las inspecciones firmadas de ejemplo (para que el ranking tenga números) se cargan después con
-- `php bin/demo-historial.php`, que firma con el mismo código que la API: así la huella de cada informe es real.

INSERT INTO usuario (nombre, email, password_hash, rol_id)
SELECT u.nombre, u.email, '$2y$12$nhrbZHzYp9khIexSilRgoelAYw4KDpepfYVCYHsrkU2nJK2gu2Nmi', r.id
FROM (VALUES
  ('Raúl Ferreyra (demo)',  'mecanico1@demo.autocar.com.ar'),
  ('Silvina Gómez (demo)',  'mecanico2@demo.autocar.com.ar'),
  ('Martín Acosta (demo)',  'mecanico3@demo.autocar.com.ar'),
  ('Claudio Benítez (demo)', 'mecanico4@demo.autocar.com.ar'),
  ('Lucas Medina (demo)',   'mecanico5@demo.autocar.com.ar')
) AS u(nombre, email)
JOIN rol r ON r.nombre = 'mecanico'
ON CONFLICT (email) DO NOTHING;

INSERT INTO taller (usuario_id, nombre, direccion, localidad, telefono, precio_inspeccion, demo)
SELECT u.id, t.nombre, t.direccion, t.localidad, t.telefono, t.precio, true
FROM (VALUES
  ('mecanico1@demo.autocar.com.ar', 'Taller Ferreyra (demo)',     'Calle 29 n.º 600',     'Mercedes', '02324-000001', 130000),
  ('mecanico2@demo.autocar.com.ar', 'Mecánica Gómez (demo)',      'Calle 12 n.º 1150',    'Mercedes', '02324-000002', 130000),
  ('mecanico3@demo.autocar.com.ar', 'Acosta Service (demo)',      'Av. 40 n.º 300',       'Mercedes', '02324-000003', 120000),
  ('mecanico4@demo.autocar.com.ar', 'Taller Benítez (demo)',      'San Martín 450',       'Luján',    '02323-000004', 130000),
  ('mecanico5@demo.autocar.com.ar', 'Medina Mecánica (demo)',     'Av. Constitución 1800', 'Luján',   '02323-000005', 140000)
) AS t(email, nombre, direccion, localidad, telefono, precio)
JOIN usuario u ON u.email = t.email
ON CONFLICT (usuario_id) DO NOTHING;
