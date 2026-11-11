-- Datos de prueba. Ejecutar UNA vez, después de schema.sql. Los usuarios se crean desde POST /api/registro;
-- para tener un administrador, ver README ("Crear el primer administrador").
-- Todo vehículo tiene un vendedor: los de prueba son de la cuenta demo vendedor-demo@autocar.test
-- (contraseña VendedorDemo2026), que puede ingresar y contestar los chats.
-- El modelo va SIN la versión ("Corolla" + versión "XEi"): así coincide con MercadoLibre, Kavak y Autocosmos en el comparador.

INSERT INTO usuario (nombre, email, password_hash, rol_id)
SELECT 'Vendedor demo', 'vendedor-demo@autocar.test',
       '$2y$12$GN2ml5KX.hieSOh03pG3duNDCz0eUyLRZjZEVC19t31vWceomEfu2',
       (SELECT id FROM rol WHERE nombre = 'usuario')
ON CONFLICT (email) DO NOTHING;

-- Marcas y modelos de estos autos, por si la lista de schema.sql no los tuviera (la base solo acepta los de la lista)
INSERT INTO marca (nombre) VALUES ('Toyota'), ('Volkswagen'), ('Ford'), ('Renault'), ('Chevrolet'), ('Fiat'), ('Peugeot'), ('Nissan'), ('Honda')
ON CONFLICT DO NOTHING;
INSERT INTO modelo (marca, nombre) VALUES
  ('Toyota', 'Corolla'), ('Volkswagen', 'Amarok'), ('Ford', 'Fiesta'), ('Renault', 'Kwid'), ('Chevrolet', 'Cruze'),
  ('Toyota', 'Hilux'), ('Fiat', 'Cronos'), ('Peugeot', '208'), ('Toyota', 'Corolla Cross'), ('Nissan', 'Leaf'),
  ('Honda', 'Fit'), ('Volkswagen', 'Gol Trend')
ON CONFLICT DO NOTHING;

INSERT INTO vehiculo (usuario_id, marca, modelo, version, anio, precio, kilometraje, combustible, transmision, estado, descripcion)
SELECT (SELECT id FROM usuario WHERE email = 'vendedor-demo@autocar.test'), d.* FROM (VALUES
('Toyota',     'Corolla', 'XEi', 2021, 24500000, 48000,  'nafta',     'automatica', 'disponible', 'Único dueño, service oficial al día. Cubiertas nuevas.'),
('Volkswagen', 'Amarok', 'V6', 2020, 41800000, 72000,  'diesel',    'automatica', 'disponible', 'Cabina doble 4x4, full equipo. Papeles al día.'),
('Ford',       'Fiesta', 'Kinetic', 2018, 11200000, 95000,  'nafta',     'manual',     'disponible', 'Muy buen estado general, ideal para ciudad.'),
('Renault',    'Kwid', 'Zen', 2022, 12900000, 21000,  'nafta',     'manual',     'disponible', 'Poco uso, patentes pagas.'),
('Chevrolet',  'Cruze', 'LTZ', 2019, 19700000, 83000,  'nafta',     'automatica', 'disponible', 'Techo solar, cuero, cámara de retroceso.'),
('Toyota',     'Hilux', 'SRV', 2022, 52000000, 35000,  'diesel',    'automatica', 'disponible', '4x4, caja automática, garantía vigente.'),
('Fiat',       'Cronos', 'Drive', 2021, 15800000, 40000,  'gnc',       'manual',     'disponible', 'Equipo de GNC de quinta generación, obleas al día.'),
('Peugeot',    '208', 'Allure', 2023, 21500000, 12000,  'nafta',     'manual',     'disponible', 'Prácticamente 0 km.'),
('Toyota',     'Corolla Cross', 'Hybrid', 2023, 36900000, 15000, 'hibrido', 'automatica', 'disponible', 'Híbrido, bajo consumo, un solo dueño.'),
('Nissan',     'Leaf', NULL, 2020, 28500000, 30000,  'electrico', 'automatica', 'disponible', 'Eléctrico, batería en excelente estado.'),
('Honda',      'Fit', 'EX', 2017, 13400000, 110000, 'nafta',     'automatica', 'vendido',    'Vendido, queda como referencia.'),
('Volkswagen', 'Gol Trend', NULL, 2019, 10900000, 78000,  'nafta',     'manual',     'disponible', 'Económico y confiable.')
) AS d(marca, modelo, version, anio, precio, kilometraje, combustible, transmision, estado, descripcion);
