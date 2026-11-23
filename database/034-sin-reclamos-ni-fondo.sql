-- 034: sin reclamos ni fondo de garantía (decisión del 23/11/2026, después de la devolución del profe).
-- El reclamo no se puede verificar (no hay forma de saber si la falla estaba el día de la inspección) y llega cuando
-- el comprador ya compró: el informe firmado reduce el riesgo antes de comprar, no lo elimina, y la decisión es del
-- comprador. Sin reclamos no hay qué pagar: se van el fondo, la franquicia del mecánico, el tope de la compensación y
-- los días para reclamar. Toda la comisión es de AutoCar (lo que antes se apartaba para el fondo, también).
-- El ranking de mecánicos pasa a ser por experiencia (inspecciones firmadas): sus parámetros de reclamos se van.
-- Correrla ANTES de desplegar el back que la usa. Se puede correr más de una vez.

DROP TABLE IF EXISTS reclamo;

ALTER TABLE inspeccion DROP COLUMN IF EXISTS monto_fondo;
ALTER TABLE inspeccion DROP COLUMN IF EXISTS tope_veces;
ALTER TABLE inspeccion DROP COLUMN IF EXISTS dias_reclamo;

-- Sus cambios (parametro_cambio) se borran solos: ON DELETE CASCADE
DELETE FROM parametro WHERE clave IN ('parte_fondo', 'tope_veces', 'dias_reclamo', 'franquicia',
                                      'ranking_veces_reclamo', 'ranking_especialidad_buena');

-- Ya no hay reclamos que resolver: el permiso se va (rol_permiso se borra solo: ON DELETE CASCADE)
DELETE FROM permiso WHERE nombre = 'reclamos.resolver';
UPDATE permiso SET descripcion = 'Cambiar los parámetros del negocio (comisión, precios, crédito, ranking)'
 WHERE nombre = 'parametros.editar';

-- El mínimo del ranking sigue: ahora es cuántas firmadas hacen falta para dejar de ser "Nuevo en la red"
UPDATE parametro SET descripcion = 'Ranking de mecánicos: inspecciones firmadas que hacen falta para dejar de ser "Nuevo en la red"'
 WHERE clave = 'ranking_minimo_firmadas';
