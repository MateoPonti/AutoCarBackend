-- AutoCar · el ranking de los mecánicos en Parámetros (misma fórmula, mismos valores que tenía en el código).
-- puntaje = 5 × (1 − ranking_veces_reclamo × reclamos que procedieron ÷ inspecciones firmadas), con mínimo 0.
-- Con menos de ranking_minimo_firmadas firmadas no hay puntaje ("Nuevo en la red"). La especialidad en un modelo o
-- marca es buena desde ranking_especialidad_buena. El 5 queda fijo en el código (el sitio dibuja cinco estrellas).
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main). Es idempotente. Sin correrla el back usa los mismos valores.
-- Una base nueva no la necesita: schema.sql ya trae todo.

INSERT INTO parametro (clave, valor, minimo, maximo, unidad, descripcion, orden) VALUES
  ('ranking_veces_reclamo',      2,   0, 10, 'veces',  'Ranking de mecánicos: cuánto baja el puntaje cada reclamo que procedió (el "2" de la fórmula)', 34),
  ('ranking_minimo_firmadas',    3,   1, 50, 'veces',  'Ranking de mecánicos: inspecciones firmadas que hacen falta para tener puntaje (antes es "Nuevo en la red")', 35),
  ('ranking_especialidad_buena', 2.5, 0, 5,  'puntos', 'Ranking de mecánicos: desde qué puntaje en un modelo o marca la especialidad es buena', 36)
ON CONFLICT (clave) DO NOTHING;
