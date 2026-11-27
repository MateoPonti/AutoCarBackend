-- Ajuste de datos de la demo en bases existentes; ejecutar después de 035.
-- La 007 marca sus talleres como demo. Las cuentas de muestra de la entrega usan autocar.test.
UPDATE taller t
SET precio_inspeccion = p.valor, updated_at = now()
FROM usuario u, parametro p
WHERE u.id = t.usuario_id
  AND (t.demo OR split_part(lower(u.email), '@', 2) = 'autocar.test')
  AND p.clave = 'precio_maximo'
  AND t.precio_inspeccion > p.valor;
