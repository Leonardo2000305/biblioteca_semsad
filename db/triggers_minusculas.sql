DROP TRIGGER IF EXISTS trg_calcular_multa_mora;
DELIMITER $$
CREATE TRIGGER trg_calcular_multa_mora BEFORE INSERT ON multa FOR EACH ROW BEGIN
    DECLARE v_dias_retraso INT DEFAULT 0;
    DECLARE v_monto_calculado DECIMAL(10,2);
    IF NEW.tipo_multa IS NULL THEN
        SET NEW.tipo_multa = 'Por Mora';
    END IF;
    IF NEW.monto IS NULL OR NEW.monto = 0 THEN
        SELECT DATEDIFF(NOW(), p.fecha_limite) INTO v_dias_retraso
        FROM prestamo p
        WHERE p.id_prestamo = NEW.id_prestamo;
        IF v_dias_retraso > 0 THEN
            SET v_monto_calculado = v_dias_retraso * 5.00;
            IF v_monto_calculado > 200.00 THEN
                SET v_monto_calculado = 200.00;
            END IF;
            SET NEW.monto = v_monto_calculado;
        ELSE
            SET NEW.monto = 5.00;
        END IF;
    END IF;
END$$
DELIMITER ;
DROP TRIGGER IF EXISTS trg_actualizar_ejemplar_devolucion;
DELIMITER $$
CREATE TRIGGER trg_actualizar_ejemplar_devolucion AFTER UPDATE ON prestamo FOR EACH ROW BEGIN
    IF NEW.estado = 'Devuelto' AND OLD.estado != 'Devuelto' THEN
        UPDATE ejemplar
        SET estado_ejemplar = 'Disponible'
        WHERE id_ejemplar IN (SELECT id_ejemplar FROM detalle_prestamo WHERE id_prestamo = NEW.id_prestamo);
    END IF;
END$$
DELIMITER ;
