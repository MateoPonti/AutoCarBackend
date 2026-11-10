-- AutoCar · roles del equipo. El admin dejaba ver "Mi taller" porque tenía todos los permisos, también los del
-- mecánico, sin tener un taller. Desde ahora el admin tiene todo MENOS verificaciones.ver y verificaciones.realizar.
-- El resto de la regla va en el código: las cuentas del equipo (admin, moderador) y las de los talleres no compran
-- ni venden (guard 'usuario' en routes.php), y nadie modera su propia publicación (409).
-- Se ejecuta UNA vez en el SQL Editor de Neon (dev y main) ANTES de desplegar el back que la usa. Es idempotente.
-- Una base nueva no la necesita: schema.sql ya trae todo.

DELETE FROM rol_permiso
WHERE rol_id = (SELECT id FROM rol WHERE nombre = 'admin')
  AND permiso_id IN (SELECT id FROM permiso WHERE nombre LIKE 'verificaciones.%');

UPDATE rol SET descripcion = 'Puede todo menos lo del taller: usuarios, moderación, bajas, talleres y métricas. No compra ni vende'
WHERE nombre = 'admin';
UPDATE rol SET descripcion = 'Revisa publicaciones, reportes y reclamos. No compra ni vende'
WHERE nombre = 'moderador';
