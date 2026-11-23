-- 035: sin historial por patente (decisión del 23/11/2026, después de la devolución del profe).
-- El informe ya no trae las inspecciones anteriores del mismo auto: un auto puede dejar de estar publicado a las dos
-- semanas y ese historial queda sin sentido. La patente y la foto de la chapa se quedan (las carga el mecánico y
-- prueban que el informe es de ese auto); solo se va el índice que servía para buscar las anteriores por patente.
-- Se puede correr más de una vez.

DROP INDEX IF EXISTS idx_inspeccion_patente;
