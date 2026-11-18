-- AutoCar · estadísticas por tipo de usuario: cuántos buscaron en el metabuscador y qué orden eligen.
-- Dos tipos de evento nuevos en la tabla evento (028):
--   busco → el comprador abrió los resultados de una búsqueda guardada (lo anota el back al armar el reporte,
--           solo la primera página). ref = el orden con el que se abrió (recomendado, precio, km o nuevos).
--   orden → el comprador eligió un orden en "Ordenar por" (lo manda el front). ref = recomendado, precio, km o nuevos.
-- Con eso el panel del admin cruza cada tipo de usuario ("¿Para qué buscás el auto?") con lo que busca y lo que paga.
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main), ANTES de desplegar el back que la usa. Es idempotente.
-- Una base nueva no la necesita: schema.sql ya trae todo.

ALTER TABLE evento DROP CONSTRAINT IF EXISTS evento_tipo_check;
ALTER TABLE evento ADD CONSTRAINT evento_tipo_check CHECK (tipo IN ('mostrado', 'tocado', 'busco', 'orden'));

-- Las consultas del panel cuentan compradores distintos por tipo de evento en un período
CREATE INDEX IF NOT EXISTS idx_evento_tipo ON evento (tipo, created_at, usuario_id);
