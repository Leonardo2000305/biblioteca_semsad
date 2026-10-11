SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

DELIMITER $$

CREATE PROCEDURE `sp_registrar_prestamo` (IN `p_id_usuario` INT, IN `p_id_bibliotecario` INT, IN `p_id_ejemplar` INT, IN `p_dias` INT)   BEGIN
    DECLARE v_estado_usuario VARCHAR(20);
    
    SELECT estado INTO v_estado_usuario FROM Usuario WHERE id_usuario = p_id_usuario;
    
    IF v_estado_usuario = 'Activo' THEN
        INSERT INTO Prestamo (id_usuario, id_bibliotecario_entrega, fecha_salida, fecha_limite, estado)
        VALUES (p_id_usuario, p_id_bibliotecario, NOW(), DATE_ADD(NOW(), INTERVAL p_dias DAY), 'Activo');
        
        INSERT INTO Detalle_Prestamo (id_prestamo, id_ejemplar)
        VALUES (LAST_INSERT_ID(), p_id_ejemplar);
        
        UPDATE Ejemplar SET estado_ejemplar = 'Prestado' WHERE id_ejemplar = p_id_ejemplar;
    END IF;
END$$

CREATE PROCEDURE `sp_sancionar_usuario_moroso` (IN `p_id_usuario` INT)   BEGIN
    UPDATE Usuario SET estado = 'Suspendido' WHERE id_usuario = p_id_usuario;
    UPDATE Prestamo SET estado = 'Con Mora' WHERE id_usuario = p_id_usuario AND estado = 'Activo' AND fecha_limite < NOW();
END$$

DELIMITER ;

CREATE TABLE `adquisicion` (
  `id_adquisicion` int(11) NOT NULL,
  `id_proveedor` int(11) NOT NULL,
  `tipo_adquisicion` varchar(50) NOT NULL,
  `fecha_adquisicion` date NOT NULL,
  `costo_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `numero_documento` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `adquisicion` (`id_adquisicion`, `id_proveedor`, `tipo_adquisicion`, `fecha_adquisicion`, `costo_total`, `numero_documento`) VALUES
(1, 1, 'Compra', '2026-01-15', 1500.00, 'FAC-1024');

CREATE TABLE `autor` (
  `id_autor` int(11) NOT NULL,
  `nombres` varchar(100) NOT NULL,
  `apellidos` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `autor` (`id_autor`, `nombres`, `apellidos`) VALUES
(1, 'Gabriel', 'García Márquez'),
(2, 'Ian', 'Sommerville'),
(3, 'Abraham', 'Silberschatz');

CREATE TABLE `categoria` (
  `id_categoria` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `id_categoria_padre` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `categoria` (`id_categoria`, `nombre`, `id_categoria_padre`) VALUES
(1, 'Ingeniería', NULL),
(2, 'Sistemas e Informática', 1),
(3, 'Ciencias Exactas', NULL);

CREATE TABLE `detalle_prestamo` (
  `id_detalle` int(11) NOT NULL,
  `id_prestamo` int(11) NOT NULL,
  `id_ejemplar` int(11) NOT NULL,
  `condicion_devolucion` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `detalle_prestamo` (`id_detalle`, `id_prestamo`, `id_ejemplar`, `condicion_devolucion`) VALUES
(1, 1, 2, NULL),
(2, 2, 3, 'Bueno'),
(3, 3, 3, 'Bueno'),
(4, 4, 1, 'Bueno'),
(5, 5, 3, 'Bueno'),
(6, 6, 1, 'Bueno'),
(7, 7, 3, 'Bueno'),
(8, 8, 1, 'Bueno'),
(9, 9, 3, 'Bueno'),
(10, 10, 3, 'Bueno'),
(11, 11, 3, NULL);

CREATE TABLE `editorial` (
  `id_editorial` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `editorial` (`id_editorial`, `nombre`) VALUES
(4, 'Alfaomega'),
(1, 'Editorial Don Bosco'),
(3, 'McGraw-Hill'),
(2, 'Océano');

CREATE TABLE `ejemplar` (
  `id_ejemplar` int(11) NOT NULL,
  `id_material` int(11) NOT NULL,
  `id_adquisicion` int(11) DEFAULT NULL,
  `id_ubicacion` int(11) NOT NULL,
  `estado_ejemplar` enum('Disponible','Prestado','Reservado','En Mantenimiento','Baja') NOT NULL DEFAULT 'Disponible',
  `condicion_fisica` varchar(100) NOT NULL DEFAULT 'Bueno'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ejemplar` (`id_ejemplar`, `id_material`, `id_adquisicion`, `id_ubicacion`, `estado_ejemplar`, `condicion_fisica`) VALUES
(1, 1, 1, 2, 'Disponible', 'Excelente'),
(2, 1, 1, 1, 'Prestado', 'Bueno'),
(3, 2, 1, 2, 'Prestado', 'Excelente');

CREATE TABLE `materialbibliografico` (
  `id_material` int(11) NOT NULL,
  `codigo_isbn_issn` varchar(30) DEFAULT NULL,
  `titulo` varchar(255) NOT NULL,
  `id_tipo_material` int(11) NOT NULL,
  `id_editorial` int(11) DEFAULT NULL,
  `id_categoria` int(11) NOT NULL,
  `agno_publicacion` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `materialbibliografico` (`id_material`, `codigo_isbn_issn`, `titulo`, `id_tipo_material`, `id_editorial`, `id_categoria`, `agno_publicacion`) VALUES
(1, '978-0137081899', 'Ingeniería de Software', 1, 3, 2, 2019),
(2, '978-0136061694', 'Conceptos de Bases de Datos', 1, 3, 2, 2020),
(3, '978-8420471839', 'Cien Años de Soledad', 1, 2, 3, 1967);

CREATE TABLE `material_autor` (
  `id_material` int(11) NOT NULL,
  `id_autor` int(11) NOT NULL,
  `orden_autor` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `material_autor` (`id_material`, `id_autor`, `orden_autor`) VALUES
(1, 2, 1),
(2, 3, 1),
(3, 1, 1);

CREATE TABLE `multa` (
  `id_multa` int(11) NOT NULL,
  `id_prestamo` int(11) NOT NULL,
  `id_detalle` int(11) DEFAULT NULL,
  `id_tipo_multa` int(11) DEFAULT NULL,
  `monto` decimal(10,2) NOT NULL,
  `estado_pago` enum('Pendiente','Pagado','Condonado') NOT NULL DEFAULT 'Pendiente',
  `tipo_multa` varchar(50) NOT NULL DEFAULT 'Por Mora'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER `trg_calcular_multa_mora` BEFORE INSERT ON `multa` FOR EACH ROW BEGIN
    DECLARE v_dias_retraso INT DEFAULT 0;
    DECLARE v_monto_calculado DECIMAL(10,2);
    
    IF NEW.tipo_multa IS NULL THEN
        SET NEW.tipo_multa = 'Por Mora';
    END IF;
    
    IF NEW.monto IS NULL OR NEW.monto = 0 THEN
        SELECT DATEDIFF(NOW(), p.fecha_limite) INTO v_dias_retraso
        FROM Prestamo p
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
END
$$
DELIMITER ;

CREATE TABLE `prestamo` (
  `id_prestamo` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `id_bibliotecario_entrega` int(11) NOT NULL,
  `fecha_salida` datetime NOT NULL,
  `fecha_limite` datetime NOT NULL,
  `fecha_devolucion_real` datetime DEFAULT NULL,
  `estado` enum('Activo','Devuelto','Con Mora','Perdido') NOT NULL DEFAULT 'Activo',
  `num_renovaciones` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `prestamo` (`id_prestamo`, `id_usuario`, `id_bibliotecario_entrega`, `fecha_salida`, `fecha_limite`, `fecha_devolucion_real`, `estado`, `num_renovaciones`) VALUES
(1, 3, 2, '2026-08-01 09:00:00', '2026-08-08 18:00:00', NULL, 'Con Mora', 1),
(2, 4, 4, '2026-10-02 08:52:37', '2026-10-09 08:52:37', '2026-10-02 10:00:24', 'Devuelto', 0),
(3, 4, 4, '2026-10-02 10:25:22', '2026-10-09 10:25:22', '2026-10-02 10:28:50', 'Devuelto', 0),
(4, 4, 4, '2026-10-02 10:25:30', '2026-10-09 10:25:30', '2026-10-02 10:28:48', 'Devuelto', 0),
(5, 4, 4, '2026-10-02 11:08:24', '2026-10-09 11:08:24', '2026-10-02 11:33:04', 'Devuelto', 0),
(6, 4, 4, '2026-10-02 11:58:37', '2026-10-09 11:58:37', '2026-10-02 19:54:38', 'Devuelto', 0),
(7, 4, 4, '2026-10-02 19:56:11', '2026-10-09 19:56:11', '2026-10-02 20:03:58', 'Devuelto', 0),
(8, 4, 4, '2026-10-02 19:56:19', '2026-10-09 19:56:19', '2026-10-02 20:03:55', 'Devuelto', 0),
(9, 4, 4, '2026-10-02 20:05:58', '2026-10-09 20:05:58', '2026-10-02 20:06:49', 'Devuelto', 0),
(10, 4, 4, '2026-10-02 20:48:54', '2026-10-09 20:48:54', '2026-10-02 20:49:51', 'Devuelto', 0),
(11, 4, 4, '2026-10-02 21:16:06', '2026-10-09 21:16:06', NULL, 'Activo', 0);

DELIMITER $$
CREATE TRIGGER `trg_actualizar_ejemplar_devolucion` AFTER UPDATE ON `prestamo` FOR EACH ROW BEGIN
    IF NEW.estado = 'Devuelto' AND OLD.estado != 'Devuelto' THEN
        UPDATE Ejemplar 
        SET estado_ejemplar = 'Disponible'
        WHERE id_ejemplar IN (SELECT id_ejemplar FROM Detalle_Prestamo WHERE id_prestamo = NEW.id_prestamo);
    END IF;
END
$$
DELIMITER ;

CREATE TABLE `proveedor` (
  `id_proveedor` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `tipo` varchar(50) NOT NULL,
  `contacto` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `proveedor` (`id_proveedor`, `nombre`, `tipo`, `contacto`) VALUES
(1, 'Librería Yachaywasi', 'Empresa', '70012345'),
(2, 'Ministerio de Educación', 'Donante', 'contacto@minedu.gob.bo');

CREATE TABLE `reglaprestamo` (
  `id_regla` int(11) NOT NULL,
  `id_rol` int(11) NOT NULL,
  `id_tipo_material` int(11) NOT NULL,
  `dias_prestamo` int(11) NOT NULL DEFAULT 7,
  `max_renovaciones` int(11) NOT NULL DEFAULT 0,
  `max_prestamos_simultaneos` int(11) NOT NULL DEFAULT 5,
  `permite_domicilio` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `reglaprestamo` (`id_regla`, `id_rol`, `id_tipo_material`, `dias_prestamo`, `max_renovaciones`, `max_prestamos_simultaneos`, `permite_domicilio`) VALUES
(1, 4, 1, 7, 2, 3, 1),
(2, 3, 1, 15, 3, 5, 1);

CREATE TABLE `reserva` (
  `id_reserva` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `id_material` int(11) NOT NULL,
  `estado` enum('Pendiente','Atendida','Cancelada','Vencida') NOT NULL DEFAULT 'Pendiente',
  `prioridad` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rol` (
  `id_rol` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `rol` (`id_rol`, `nombre`) VALUES
(1, 'Administrador'),
(2, 'Bibliotecario'),
(3, 'Docente'),
(4, 'Estudiante');

CREATE TABLE `tipomaterial` (
  `id_tipo_material` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tipomaterial` (`id_tipo_material`, `nombre`) VALUES
(1, 'Libro'),
(4, 'Proyecto de Grado'),
(2, 'Revista'),
(3, 'Tesis');

CREATE TABLE `ubicacionfisica` (
  `id_ubicacion` int(11) NOT NULL,
  `sala` varchar(50) NOT NULL,
  `estante` varchar(50) NOT NULL,
  `nivel` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ubicacionfisica` (`id_ubicacion`, `sala`, `estante`, `nivel`) VALUES
(1, 'Sala Central', 'A-1', 'Nivel 2'),
(2, 'Sala Central', 'B-3', 'Nivel 1');

CREATE TABLE `usuario` (
  `id_usuario` int(11) NOT NULL,
  `nombres` varchar(100) NOT NULL,
  `apellidos` varchar(100) NOT NULL,
  `correo` varchar(150) NOT NULL,
  `id_rol` int(11) NOT NULL,
  `codigo_empleado` varchar(50) DEFAULT NULL,
  `nivel_permiso` int(11) NOT NULL DEFAULT 1,
  `estado` enum('Activo','Suspendido','Bloqueado') NOT NULL DEFAULT 'Activo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `usuario` (`id_usuario`, `nombres`, `apellidos`, `correo`, `id_rol`, `codigo_empleado`, `nivel_permiso`, `estado`) VALUES
(1, 'Carlos', 'Mendoza', 'admin@semsad.edu.bo', 1, 'ADM-001', 3, 'Activo'),
(2, 'Lucía', 'Fernández', 'lfernandez@semsad.edu.bo', 2, 'BIB-001', 2, 'Activo'),
(3, 'Juan', 'Pérez', 'jperez@est.semsad.edu.bo', 4, 'EST-202401', 1, 'Suspendido'),
(4, 'Maria', 'Gomez', 'mgomez@est.semsad.edu.bo', 4, 'EST-202402', 1, 'Activo'),
(5, 'Roberto', 'Siles', 'rsiles@doc.semsad.edu.bo', 3, 'DOC-001', 2, 'Activo');

ALTER TABLE `adquisicion`
  ADD PRIMARY KEY (`id_adquisicion`),
  ADD KEY `fk_adquisicion_proveedor` (`id_proveedor`);

ALTER TABLE `autor`
  ADD PRIMARY KEY (`id_autor`);

ALTER TABLE `categoria`
  ADD PRIMARY KEY (`id_categoria`),
  ADD KEY `fk_categoria_padre` (`id_categoria_padre`);

ALTER TABLE `detalle_prestamo`
  ADD PRIMARY KEY (`id_detalle`),
  ADD KEY `fk_detalle_prestamo` (`id_prestamo`),
  ADD KEY `fk_detalle_ejemplar` (`id_ejemplar`);

ALTER TABLE `editorial`
  ADD PRIMARY KEY (`id_editorial`),
  ADD UNIQUE KEY `nombre` (`nombre`);

ALTER TABLE `ejemplar`
  ADD PRIMARY KEY (`id_ejemplar`),
  ADD KEY `fk_ejemplar_material` (`id_material`),
  ADD KEY `fk_ejemplar_adquisicion` (`id_adquisicion`),
  ADD KEY `fk_ejemplar_ubicacion` (`id_ubicacion`);

ALTER TABLE `materialbibliografico`
  ADD PRIMARY KEY (`id_material`),
  ADD UNIQUE KEY `codigo_isbn_issn` (`codigo_isbn_issn`),
  ADD KEY `fk_material_tipo` (`id_tipo_material`),
  ADD KEY `fk_material_editorial` (`id_editorial`),
  ADD KEY `fk_material_categoria` (`id_categoria`);

ALTER TABLE `material_autor`
  ADD PRIMARY KEY (`id_material`,`id_autor`),
  ADD KEY `fk_ma_autor` (`id_autor`);

ALTER TABLE `multa`
  ADD PRIMARY KEY (`id_multa`),
  ADD KEY `fk_multa_prestamo` (`id_prestamo`),
  ADD KEY `fk_multa_detalle` (`id_detalle`);

ALTER TABLE `prestamo`
  ADD PRIMARY KEY (`id_prestamo`),
  ADD KEY `fk_prestamo_usuario` (`id_usuario`),
  ADD KEY `fk_prestamo_bibliotecario` (`id_bibliotecario_entrega`);

ALTER TABLE `proveedor`
  ADD PRIMARY KEY (`id_proveedor`);

ALTER TABLE `reglaprestamo`
  ADD PRIMARY KEY (`id_regla`),
  ADD KEY `fk_regla_rol` (`id_rol`),
  ADD KEY `fk_regla_tipomaterial` (`id_tipo_material`);

ALTER TABLE `reserva`
  ADD PRIMARY KEY (`id_reserva`),
  ADD KEY `fk_reserva_usuario` (`id_usuario`),
  ADD KEY `fk_reserva_material` (`id_material`);

ALTER TABLE `rol`
  ADD PRIMARY KEY (`id_rol`),
  ADD UNIQUE KEY `nombre` (`nombre`);

ALTER TABLE `tipomaterial`
  ADD PRIMARY KEY (`id_tipo_material`),
  ADD UNIQUE KEY `nombre` (`nombre`);

ALTER TABLE `ubicacionfisica`
  ADD PRIMARY KEY (`id_ubicacion`);

ALTER TABLE `usuario`
  ADD PRIMARY KEY (`id_usuario`),
  ADD UNIQUE KEY `correo` (`correo`),
  ADD KEY `fk_usuario_rol` (`id_rol`);

ALTER TABLE `adquisicion`
  MODIFY `id_adquisicion` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `autor`
  MODIFY `id_autor` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `categoria`
  MODIFY `id_categoria` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `detalle_prestamo`
  MODIFY `id_detalle` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

ALTER TABLE `editorial`
  MODIFY `id_editorial` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `ejemplar`
  MODIFY `id_ejemplar` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `materialbibliografico`
  MODIFY `id_material` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `multa`
  MODIFY `id_multa` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `prestamo`
  MODIFY `id_prestamo` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

ALTER TABLE `proveedor`
  MODIFY `id_proveedor` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

ALTER TABLE `reglaprestamo`
  MODIFY `id_regla` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

ALTER TABLE `reserva`
  MODIFY `id_reserva` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `rol`
  MODIFY `id_rol` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `tipomaterial`
  MODIFY `id_tipo_material` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `ubicacionfisica`
  MODIFY `id_ubicacion` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

ALTER TABLE `usuario`
  MODIFY `id_usuario` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

ALTER TABLE `adquisicion`
  ADD CONSTRAINT `fk_adquisicion_proveedor` FOREIGN KEY (`id_proveedor`) REFERENCES `proveedor` (`id_proveedor`) ON UPDATE CASCADE;

ALTER TABLE `categoria`
  ADD CONSTRAINT `fk_categoria_padre` FOREIGN KEY (`id_categoria_padre`) REFERENCES `categoria` (`id_categoria`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `detalle_prestamo`
  ADD CONSTRAINT `fk_detalle_ejemplar` FOREIGN KEY (`id_ejemplar`) REFERENCES `ejemplar` (`id_ejemplar`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_detalle_prestamo` FOREIGN KEY (`id_prestamo`) REFERENCES `prestamo` (`id_prestamo`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `ejemplar`
  ADD CONSTRAINT `fk_ejemplar_adquisicion` FOREIGN KEY (`id_adquisicion`) REFERENCES `adquisicion` (`id_adquisicion`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ejemplar_material` FOREIGN KEY (`id_material`) REFERENCES `materialbibliografico` (`id_material`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ejemplar_ubicacion` FOREIGN KEY (`id_ubicacion`) REFERENCES `ubicacionfisica` (`id_ubicacion`) ON UPDATE CASCADE;

ALTER TABLE `materialbibliografico`
  ADD CONSTRAINT `fk_material_categoria` FOREIGN KEY (`id_categoria`) REFERENCES `categoria` (`id_categoria`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_material_editorial` FOREIGN KEY (`id_editorial`) REFERENCES `editorial` (`id_editorial`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_material_tipo` FOREIGN KEY (`id_tipo_material`) REFERENCES `tipomaterial` (`id_tipo_material`) ON UPDATE CASCADE;

ALTER TABLE `material_autor`
  ADD CONSTRAINT `fk_ma_autor` FOREIGN KEY (`id_autor`) REFERENCES `autor` (`id_autor`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ma_material` FOREIGN KEY (`id_material`) REFERENCES `materialbibliografico` (`id_material`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `multa`
  ADD CONSTRAINT `fk_multa_detalle` FOREIGN KEY (`id_detalle`) REFERENCES `detalle_prestamo` (`id_detalle`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_multa_prestamo` FOREIGN KEY (`id_prestamo`) REFERENCES `prestamo` (`id_prestamo`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `prestamo`
  ADD CONSTRAINT `fk_prestamo_bibliotecario` FOREIGN KEY (`id_bibliotecario_entrega`) REFERENCES `usuario` (`id_usuario`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_prestamo_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`) ON UPDATE CASCADE;

ALTER TABLE `reglaprestamo`
  ADD CONSTRAINT `fk_regla_rol` FOREIGN KEY (`id_rol`) REFERENCES `rol` (`id_rol`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_regla_tipomaterial` FOREIGN KEY (`id_tipo_material`) REFERENCES `tipomaterial` (`id_tipo_material`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `reserva`
  ADD CONSTRAINT `fk_reserva_material` FOREIGN KEY (`id_material`) REFERENCES `materialbibliografico` (`id_material`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_reserva_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `usuario`
  ADD CONSTRAINT `fk_usuario_rol` FOREIGN KEY (`id_rol`) REFERENCES `rol` (`id_rol`) ON UPDATE CASCADE;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;