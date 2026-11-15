-- AutoCar · cotización del dólar como parámetro (15/11). En el metabuscador, los autos publicados en dólares se
-- muestran y se ordenan en pesos con esta cotización, para que la lista no mezcle monedas. El admin la cambia en
-- Panel → Parámetros. El valor inicial es para arrancar: hay que ponerle la cotización del día.
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main), ANTES de desplegar el back que la usa. Es idempotente.
-- Una base nueva no la necesita: schema.sql ya trae todo.

INSERT INTO parametro (clave, valor, minimo, maximo, unidad, descripcion, orden) VALUES
  ('cotizacion_dolar', 1450, 1, 100000, 'pesos', 'Cotización del dólar: con ella se muestran en pesos los autos publicados en dólares en el metabuscador', 8)
ON CONFLICT (clave) DO NOTHING;
