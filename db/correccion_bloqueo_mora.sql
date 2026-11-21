-- ARCHIVO: correccion_bloqueo_mora.sql
-- SIRVE PARA: revisar qué cuentas tienen la marca bloqueo_por_mora = 1 y, si el administrador lo decide,
--             quitarla a las cuentas que fueron suspendidas A MANO y no por mora.
-- PROBLEMA QUE RESUELVE: migracion_bloqueo_mora.sql marcó como "bloqueo por mora" a las cuentas
--             Bloqueado/Suspendido que tenían multas pendientes, aunque hayan sido suspendidas manualmente.
--             Si se paga la multa, esas cuentas se reactivarían sin querer.
-- PASO 1 (SOLO LECTURA): ejecutar la consulta. No cambia nada.
-- PASO 2: revisar la lista. Si una cuenta fue suspendida por mora, se deja como está.
--         Si fue suspendida a mano por la administración, anotar su id_usuario.
-- PASO 3: solo para esos id, ejecutar el UPDATE de abajo (quitar el ID de la lista si no corresponde).
-- Hacer una copia de seguridad antes de ejecutar el PASO 3.

-- PASO 1: cuentas con la marca de mora (solo lectura)
SELECT u.id_usuario, u.nombres, u.apellidos, u.correo, u.estado, u.bloqueo_por_mora,
       COUNT(m.id_multa) AS multas_pendientes,
       COALESCE(SUM(m.monto), 0) AS monto_pendiente
FROM usuario u
LEFT JOIN prestamo p ON p.id_usuario = u.id_usuario
LEFT JOIN multa m ON m.id_prestamo = p.id_prestamo AND m.estado_pago = 'Pendiente'
WHERE u.bloqueo_por_mora = 1
GROUP BY u.id_usuario, u.nombres, u.apellidos, u.correo, u.estado, u.bloqueo_por_mora
ORDER BY u.id_usuario;

-- PASO 3: quitar la marca SOLO a cuentas suspendidas a mano (reemplazar la lista de ejemplo por los id revisados).
-- UPDATE usuario
-- SET bloqueo_por_mora = 0
-- WHERE id_usuario IN (0);   -- ejemplo: (12, 15)
