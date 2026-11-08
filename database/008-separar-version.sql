-- AutoCar · separa la versión del modelo en los vehículos de prueba (los de seed.sql).
-- Se cargaron con la versión pegada al modelo ("Corolla XEi") y el comparador no los encuentra en MercadoLibre,
-- Kavak ni Autocosmos, que tienen el modelo ("Corolla") y la versión ("XEi") por separado.
-- Se ejecuta UNA vez en el SQL Editor de Neon (primero dev, después main). No borra datos y es idempotente:
-- después de correrla ningún modelo coincide con el de la izquierda, así que la segunda vez no cambia nada.
-- Solo toca los que no tienen versión cargada, para no pisar una que haya escrito alguien.
-- No cambia la estructura de la base: schema.sql no necesita nada (y seed.sql ya trae los datos corregidos).

UPDATE vehiculo SET modelo = 'Corolla',       version = 'XEi'     WHERE marca = 'Toyota'     AND modelo = 'Corolla XEi'          AND version IS NULL;
UPDATE vehiculo SET modelo = 'Amarok',        version = 'V6'      WHERE marca = 'Volkswagen' AND modelo = 'Amarok V6'            AND version IS NULL;
UPDATE vehiculo SET modelo = 'Fiesta',        version = 'Kinetic' WHERE marca = 'Ford'       AND modelo = 'Fiesta Kinetic'       AND version IS NULL;
UPDATE vehiculo SET modelo = 'Kwid',          version = 'Zen'     WHERE marca = 'Renault'    AND modelo = 'Kwid Zen'             AND version IS NULL;
UPDATE vehiculo SET modelo = 'Cruze',         version = 'LTZ'     WHERE marca = 'Chevrolet'  AND modelo = 'Cruze LTZ'            AND version IS NULL;
UPDATE vehiculo SET modelo = 'Hilux',         version = 'SRV'     WHERE marca = 'Toyota'     AND modelo = 'Hilux SRV'            AND version IS NULL;
UPDATE vehiculo SET modelo = 'Cronos',        version = 'Drive'   WHERE marca = 'Fiat'       AND modelo = 'Cronos Drive'         AND version IS NULL;
UPDATE vehiculo SET modelo = '208',           version = 'Allure'  WHERE marca = 'Peugeot'    AND modelo = '208 Allure'           AND version IS NULL;
UPDATE vehiculo SET modelo = 'Corolla Cross', version = 'Hybrid'  WHERE marca = 'Toyota'     AND modelo = 'Corolla Cross Hybrid' AND version IS NULL;
UPDATE vehiculo SET modelo = 'Fit',           version = 'EX'      WHERE marca = 'Honda'      AND modelo = 'Fit EX'               AND version IS NULL;

-- Para revisar después: autos cuyo modelo parece tener la versión pegada (más de dos palabras o letras de versión al final).
-- SELECT id, marca, modelo, version FROM vehiculo WHERE modelo ~* '\s(XEi|XLi|SRV|SR|LTZ|LT|Zen|Drive|Allure|Kinetic|EX|V6|Hybrid|Highline|Comfortline|Trendline)$';
