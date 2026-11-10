-- AutoCar · modelos nuevos para la lista de Publicar: 7 marcas y 46 modelos que trajo la corrida completa de
-- MercadoLibre del 10/11/2026 y no estaban en la 013 (que se armó con el log de una corrida que se cortó al guardar).
-- Solo agrega, nunca borra (las publicaciones que ya existen no se tocan).
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main). Es idempotente. Una base nueva no la necesita: schema.sql ya la trae.

INSERT INTO marca (nombre) VALUES
  ('Abarth'),
  ('GAC'),
  ('GMC'),
  ('Lada'),
  ('Maserati'),
  ('Rastrojero'),
  ('SAAB')
ON CONFLICT DO NOTHING;

INSERT INTO modelo (marca, nombre) VALUES
  ('Abarth', 'FASTBACK'),
  ('Abarth', 'Pulse'),
  ('Chevrolet', 'C-20'),
  ('Chevrolet', 'C30'),
  ('Chevrolet', 'Chevy Van'),
  ('Chevrolet', 'Corsica'),
  ('Fiat', '133'),
  ('Fiat', 'Ducato Maxicargo'),
  ('Fiat', 'Tempra'),
  ('Fiat', 'Uno EVO'),
  ('Ford', 'Cargo'),
  ('Ford', 'Ram 1500'),
  ('GAC', 'Emkoo'),
  ('GAC', 'Empow'),
  ('GAC', 'Gs3 Emzoom'),
  ('GAC', 'gs8'),
  ('GMC', '3500'),
  ('GMC', 'D-20'),
  ('GMC', 'Haval'),
  ('GMC', 'Hummer EV'),
  ('Haval', 'Elite'),
  ('Lada', 'Niva 2121'),
  ('Lada', 'Samara'),
  ('Maserati', '3200 GT'),
  ('Maserati', 'Levante'),
  ('Maserati', 'Mistral'),
  ('Maserati', 'Quattroporte'),
  ('Maserati', 'Spyder'),
  ('Peugeot', '207 SW'),
  ('Rastrojero', 'Pick Up'),
  ('Renault', 'Grand Scénic'),
  ('Renault', 'Kangoo E-Tech'),
  ('Renault', 'Megane 3'),
  ('Renault', 'R 4'),
  ('Renault', 'R21 Alize'),
  ('Renault', 'R21 Nevada'),
  ('SAAB', '9-3'),
  ('SAAB', '9-5'),
  ('SAAB', '900'),
  ('SAAB', '9000'),
  ('Toyota', 'Coaster'),
  ('Toyota', 'Fj Cruiser'),
  ('Toyota', 'Tundra'),
  ('Volkswagen', 'Combi'),
  ('Volkswagen', 'Santana'),
  ('Leapmotor', 'C10')
ON CONFLICT DO NOTHING;
