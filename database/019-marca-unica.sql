-- AutoCar · una marca no se puede repetir con otra escritura: si existe "DS", la base rechaza "Ds" o "ds".
-- La clave de la tabla marca es el nombre tal cual, y para Postgres "DS" y "Ds" son textos distintos; este índice
-- compara el nombre en minúsculas. (Los acentos sí cuentan: "Citroën" y "Citroen" serían distintas.)
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main). Es idempotente. Si falla con "could not create unique
-- index", hay una marca repetida: unificarla antes. Una base nueva no la necesita: schema.sql ya la trae.

CREATE UNIQUE INDEX IF NOT EXISTS marca_nombre_unico ON marca (lower(nombre));
