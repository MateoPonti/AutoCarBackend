-- Datos de prueba (solo vehículos). Ejecutar UNA vez, después de schema.sql. Los usuarios se crean desde POST /api/registro;
-- para tener un administrador, ver README ("Crear el primer administrador").


INSERT INTO vehiculos (marca, modelo, anio, precio, kilometraje, combustible, transmision, estado, descripcion) VALUES
('Toyota',     'Corolla XEi',  2021, 24500000, 48000,  'nafta',     'automatica', 'disponible', 'Único dueño, service oficial al día. Cubiertas nuevas.'),
('Volkswagen', 'Amarok V6',    2020, 41800000, 72000,  'diesel',    'automatica', 'disponible', 'Cabina doble 4x4, full equipo. Papeles al día.'),
('Ford',       'Fiesta Kinetic',2018, 11200000, 95000,  'nafta',     'manual',     'disponible', 'Muy buen estado general, ideal para ciudad.'),
('Renault',    'Kwid Zen',     2022, 12900000, 21000,  'nafta',     'manual',     'disponible', 'Poco uso, patentes pagas.'),
('Chevrolet',  'Cruze LTZ',    2019, 19700000, 83000,  'nafta',     'automatica', 'disponible', 'Techo solar, cuero, cámara de retroceso.'),
('Toyota',     'Hilux SRV',    2022, 52000000, 35000,  'diesel',    'automatica', 'disponible', '4x4, caja automática, garantía vigente.'),
('Fiat',       'Cronos Drive', 2021, 15800000, 40000,  'gnc',       'manual',     'disponible', 'Equipo de GNC de quinta generación, obleas al día.'),
('Peugeot',    '208 Allure',   2023, 21500000, 12000,  'nafta',     'manual',     'disponible', 'Prácticamente 0 km.'),
('Toyota',     'Corolla Cross Hybrid', 2023, 36900000, 15000, 'hibrido', 'automatica', 'disponible', 'Híbrido, bajo consumo, un solo dueño.'),
('Nissan',     'Leaf',         2020, 28500000, 30000,  'electrico', 'automatica', 'disponible', 'Eléctrico, batería en excelente estado.'),
('Honda',      'Fit EX',       2017, 13400000, 110000, 'nafta',     'automatica', 'vendido',    'Vendido, queda como referencia.'),
('Volkswagen', 'Gol Trend',    2019, 10900000, 78000,  'nafta',     'manual',     'disponible', 'Económico y confiable.');
