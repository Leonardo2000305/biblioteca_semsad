SET FOREIGN_KEY_CHECKS=0;
-- Limpieza: borra procedimientos y triggers de un intento anterior
DROP PROCEDURE IF EXISTS sp_registrar_prestamo;
DROP PROCEDURE IF EXISTS sp_sancionar_usuario_moroso;
DROP TRIGGER IF EXISTS trg_calcular_multa_mora;
DROP TRIGGER IF EXISTS trg_actualizar_ejemplar_devolucion;

-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: mysql-bibliosemsad.alwaysdata.net
-- Generation Time: Nov 06, 2026 at 02:24 AM
-- Server version: 11.8.9-MariaDB
-- PHP Version: 8.4.27

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bibliosemsad_db`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_registrar_prestamo` (IN `p_id_usuario` INT, IN `p_id_bibliotecario` INT, IN `p_id_ejemplar` INT, IN `p_dias` INT)   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_sancionar_usuario_moroso` (IN `p_id_usuario` INT)   BEGIN
    UPDATE Usuario SET estado = 'Suspendido' WHERE id_usuario = p_id_usuario;
    UPDATE Prestamo SET estado = 'Con Mora' 
    WHERE id_usuario = p_id_usuario AND estado = 'Activo' AND fecha_limite < NOW();
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `adquisicion`
--

DROP TABLE IF EXISTS `adquisicion`;
CREATE TABLE `adquisicion` (
  `id_adquisicion` int(11) NOT NULL,
  `id_proveedor` int(11) NOT NULL,
  `tipo_adquisicion` varchar(50) NOT NULL,
  `fecha_adquisicion` date NOT NULL,
  `costo_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `numero_documento` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `adquisicion`
--

INSERT INTO `adquisicion` (`id_adquisicion`, `id_proveedor`, `tipo_adquisicion`, `fecha_adquisicion`, `costo_total`, `numero_documento`) VALUES
(1, 1, 'Compra', '2026-01-15', 1500.00, 'FAC-1024');

-- --------------------------------------------------------

--
-- Table structure for table `auditoria_recuperacion`
--

DROP TABLE IF EXISTS `auditoria_recuperacion`;
CREATE TABLE `auditoria_recuperacion` (
  `id_log` int(11) NOT NULL,
  `correo_ingresado` varchar(150) NOT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  `fecha_hora` datetime DEFAULT current_timestamp(),
  `estado` varchar(60) NOT NULL,
  `ip_origen` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `auditoria_recuperacion`
--

INSERT INTO `auditoria_recuperacion` (`id_log`, `correo_ingresado`, `id_usuario`, `fecha_hora`, `estado`, `ip_origen`) VALUES
(1, 'estudiante@semsad.edu.bo', NULL, '2026-10-11 22:47:07', 'Correo No Encontrado', '166.114.171.21'),
(2, 'estudiante@semsad.edu.bo', NULL, '2026-10-12 04:49:42', 'Correo No Encontrado', '166.114.171.21'),
(3, 'estudiante@semsad.edu.bo', NULL, '2026-10-14 01:19:52', 'Correo No Encontrado', '181.115.244.154'),
(4, 'lfernandez@semsad.edu.bo', 2, '2026-10-14 01:20:18', 'Exitoso', '181.115.244.154'),
(5, 'leogutisp@gmail.com', 7, '2026-10-15 20:21:53', 'Exitoso', '166.114.171.21'),
(6, 'leogutisp@gmail.com', 7, '2026-10-15 17:50:03', 'Solicitud enviada', '166.114.171.21'),
(7, 'leogutisp@gmail.com', 7, '2026-10-15 17:50:37', 'Cambio exitoso', '166.114.171.21'),
(8, 'leogutisp@gmail.com', 7, '2026-10-15 17:52:34', 'Solicitud enviada', '166.114.171.21'),
(9, 'leogutisp@gmail.com', 7, '2026-10-15 17:52:53', 'Cambio exitoso', '166.114.171.21'),
(10, 'leogutisp@gmail.com', 7, '2026-10-15 19:10:04', 'Solicitud enviada', '181.115.244.154'),
(11, 'leogutisp@gmail.com', 7, '2026-10-15 19:10:31', 'Cambio exitoso', '181.115.244.154'),
(12, 'leogutisp@gmail.com', 7, '2026-10-16 00:01:36', 'Solicitud enviada', '166.114.171.21'),
(13, 'leogutisp@gmail.com', 7, '2026-10-16 00:01:54', 'Cambio exitoso', '166.114.171.21'),
(14, 'angeloyujra721@gmail.com', 14, '2026-10-29 10:01:09', 'Solicitud enviada', '166.114.170.67'),
(15, 'angeloyujra721@gmail.com', 14, '2026-10-29 10:01:32', 'Cambio exitoso', '166.114.170.67');

-- --------------------------------------------------------

--
-- Table structure for table `autor`
--

DROP TABLE IF EXISTS `autor`;
CREATE TABLE `autor` (
  `id_autor` int(11) NOT NULL,
  `nombres` varchar(100) NOT NULL,
  `apellidos` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `autor`
--

INSERT INTO `autor` (`id_autor`, `nombres`, `apellidos`) VALUES
(1, 'Gabriel', 'García Márquez'),
(2, 'Ian', 'Sommerville'),
(3, 'Abraham', 'Silberschatz'),
(4, 'Oropeza', ''),
(5, 'Rosay', ''),
(6, 'Rosnay', ''),
(7, 'Rosnaj', ''),
(8, 'Oropesa', ''),
(9, 'Miranda', '');

-- --------------------------------------------------------

--
-- Table structure for table `categoria`
--

DROP TABLE IF EXISTS `categoria`;
CREATE TABLE `categoria` (
  `id_categoria` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `id_categoria_padre` int(11) DEFAULT NULL,
  `observacion` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `categoria`
--

INSERT INTO `categoria` (`id_categoria`, `nombre`, `id_categoria_padre`, `observacion`) VALUES
(1, 'Ingeniería', NULL, NULL),
(2, 'Sistemas e Informática', 1, NULL),
(3, 'Ciencias Exactas', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `codigo_verificacion`
--

DROP TABLE IF EXISTS `codigo_verificacion`;
CREATE TABLE `codigo_verificacion` (
  `id` int(11) NOT NULL,
  `correo` varchar(150) NOT NULL,
  `codigo` varchar(6) NOT NULL,
  `expiracion` datetime NOT NULL,
  `creado_en` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `codigo_verificacion`
--

INSERT INTO `codigo_verificacion` (`id`, `correo`, `codigo`, `expiracion`, `creado_en`) VALUES
(1, 'ramos087@est.semsad.edu.bo', '572745', '2026-10-13 23:03:33', '2026-10-13 22:58:33'),
(3, 'kenjiramos087@gmail.com', '033692', '2026-10-13 23:22:00', '2026-10-13 23:07:00'),
(40, 'leogutisp@gmail.com', '742735', '2026-10-15 17:51:15', '2026-10-15 21:36:15'),
(45, 'robertleonardoyujra@gmail.com', '723765', '2026-10-16 00:06:16', '2026-10-16 04:01:16'),
(46, 'leonix20sp@gmail.com', '497346', '2026-10-19 22:37:47', '2026-10-20 02:32:47');

-- --------------------------------------------------------

--
-- Table structure for table `detalle_prestamo`
--

DROP TABLE IF EXISTS `detalle_prestamo`;
CREATE TABLE `detalle_prestamo` (
  `id_detalle` int(11) NOT NULL,
  `id_prestamo` int(11) NOT NULL,
  `id_ejemplar` int(11) NOT NULL,
  `condicion_devolucion` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `detalle_prestamo`
--

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
(11, 11, 3, 'Bueno'),
(12, 12, 1, 'Bueno'),
(13, 13, 3, 'Bueno'),
(14, 14, 1, 'Bueno'),
(15, 15, 1, NULL),
(16, 16, 3, NULL),
(17, 17, 4, NULL),
(18, 18, 2, NULL),
(19, 19, 4, NULL),
(20, 20, 6, NULL),
(21, 21, 5, NULL),
(22, 22, 5, NULL),
(23, 23, 3, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `editorial`
--

DROP TABLE IF EXISTS `editorial`;
CREATE TABLE `editorial` (
  `id_editorial` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `editorial`
--

INSERT INTO `editorial` (`id_editorial`, `nombre`) VALUES
(4, 'Alfaomega'),
(1, 'Editorial Don Bosco'),
(3, 'McGraw-Hill'),
(2, 'Océano');

-- --------------------------------------------------------

--
-- Table structure for table `ejemplar`
--

DROP TABLE IF EXISTS `ejemplar`;
CREATE TABLE `ejemplar` (
  `id_ejemplar` int(11) NOT NULL,
  `id_material` int(11) NOT NULL,
  `id_adquisicion` int(11) DEFAULT NULL,
  `id_ubicacion` int(11) NOT NULL,
  `estado_ejemplar` enum('Disponible','Prestado','Reservado','En Mantenimiento','Baja') NOT NULL DEFAULT 'Disponible',
  `condicion_fisica` varchar(100) NOT NULL DEFAULT 'Bueno'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ejemplar`
--

INSERT INTO `ejemplar` (`id_ejemplar`, `id_material`, `id_adquisicion`, `id_ubicacion`, `estado_ejemplar`, `condicion_fisica`) VALUES
(1, 1, 1, 2, 'Disponible', 'Excelente'),
(2, 1, 1, 1, 'Disponible', 'Bueno'),
(3, 2, 1, 2, 'Prestado', 'Excelente'),
(4, 3, 1, 1, 'Prestado', 'Excelente'),
(5, 4, NULL, 1, 'Prestado', 'Bueno'),
(6, 5, NULL, 1, 'Prestado', 'Bueno'),
(7, 12, NULL, 1, 'Disponible', 'Bueno');

-- --------------------------------------------------------

--
-- Table structure for table `intentos_seguridad`
--

DROP TABLE IF EXISTS `intentos_seguridad`;
CREATE TABLE `intentos_seguridad` (
  `clave` varchar(190) NOT NULL,
  `fallos` int(11) NOT NULL DEFAULT 0,
  `primer_fallo` datetime NOT NULL,
  `bloqueado_hasta` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `materialbibliografico`
--

DROP TABLE IF EXISTS `materialbibliografico`;
CREATE TABLE `materialbibliografico` (
  `id_material` int(11) NOT NULL,
  `codigo_isbn_issn` varchar(30) DEFAULT NULL,
  `titulo` varchar(255) NOT NULL,
  `id_tipo_material` int(11) NOT NULL,
  `id_editorial` int(11) DEFAULT NULL,
  `id_categoria` int(11) NOT NULL,
  `agno_publicacion` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `materialbibliografico`
--

INSERT INTO `materialbibliografico` (`id_material`, `codigo_isbn_issn`, `titulo`, `id_tipo_material`, `id_editorial`, `id_categoria`, `agno_publicacion`) VALUES
(1, '978-0137081899', 'Ingeniería de Software', 1, 3, 2, 2019),
(2, '978-0136061694', 'Conceptos de Bases de Datos', 1, 3, 2, 2020),
(3, '978-8420471839', 'Cien Años de Soledad', 1, 2, 3, 1967),
(4, '202-1238545216', 'Ingles Basico 1', 1, NULL, 1, 2024),
(5, '202020', 'Ingles 2', 1, NULL, 1, 2024),
(12, '226592178', 'Ecuaciones Diff.', 1, NULL, 1, 2020);

-- --------------------------------------------------------

--
-- Table structure for table `material_autor`
--

DROP TABLE IF EXISTS `material_autor`;
CREATE TABLE `material_autor` (
  `id_material` int(11) NOT NULL,
  `id_autor` int(11) NOT NULL,
  `orden_autor` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `material_autor`
--

INSERT INTO `material_autor` (`id_material`, `id_autor`, `orden_autor`) VALUES
(1, 2, 1),
(2, 3, 1),
(3, 1, 1),
(4, 8, 1),
(5, 6, 1),
(12, 9, 1);

-- --------------------------------------------------------

--
-- Table structure for table `multa`
--

DROP TABLE IF EXISTS `multa`;
CREATE TABLE `multa` (
  `id_multa` int(11) NOT NULL,
  `id_prestamo` int(11) NOT NULL,
  `id_detalle` int(11) DEFAULT NULL,
  `id_tipo_multa` int(11) DEFAULT NULL,
  `monto` decimal(10,2) NOT NULL,
  `estado_pago` enum('Pendiente','Pagado','Condonado') NOT NULL DEFAULT 'Pendiente',
  `tipo_multa` varchar(50) NOT NULL DEFAULT 'Por Mora'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `multa`
--

INSERT INTO `multa` (`id_multa`, `id_prestamo`, `id_detalle`, `id_tipo_multa`, `monto`, `estado_pago`, `tipo_multa`) VALUES
(1, 19, 19, NULL, 40.00, 'Pendiente', 'Por Mora'),
(2, 20, 20, NULL, 40.00, 'Pendiente', 'Por Mora'),
(3, 22, 22, NULL, 30.00, 'Pendiente', 'Por Mora'),
(4, 23, 23, NULL, 10.00, 'Pendiente', 'Por Mora');

--
-- Triggers `multa`
--
DELIMITER $$
CREATE TRIGGER `trg_calcular_multa_mora` BEFORE INSERT ON `multa` FOR EACH ROW BEGIN
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
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `prestamo`
--

DROP TABLE IF EXISTS `prestamo`;
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

--
-- Dumping data for table `prestamo`
--

INSERT INTO `prestamo` (`id_prestamo`, `id_usuario`, `id_bibliotecario_entrega`, `fecha_salida`, `fecha_limite`, `fecha_devolucion_real`, `estado`, `num_renovaciones`) VALUES
(1, 3, 2, '2026-08-01 09:00:00', '2026-08-08 18:00:00', '2026-10-20 23:18:59', 'Devuelto', 1),
(2, 4, 4, '2026-10-02 08:52:37', '2026-10-09 08:52:37', '2026-10-02 10:00:24', 'Devuelto', 0),
(3, 4, 4, '2026-10-02 10:25:22', '2026-10-09 10:25:22', '2026-10-02 10:28:50', 'Devuelto', 0),
(4, 4, 4, '2026-10-02 10:25:30', '2026-10-09 10:25:30', '2026-10-02 10:28:48', 'Devuelto', 0),
(5, 4, 4, '2026-10-02 11:08:24', '2026-10-09 11:08:24', '2026-10-02 11:33:04', 'Devuelto', 0),
(6, 4, 4, '2026-10-02 11:58:37', '2026-10-09 11:58:37', '2026-10-02 19:54:38', 'Devuelto', 0),
(7, 4, 4, '2026-10-02 19:56:11', '2026-10-09 19:56:11', '2026-10-02 20:03:58', 'Devuelto', 0),
(8, 4, 4, '2026-10-02 19:56:19', '2026-10-09 19:56:19', '2026-10-02 20:03:55', 'Devuelto', 0),
(9, 4, 4, '2026-10-02 20:05:58', '2026-10-09 20:05:58', '2026-10-02 20:06:49', 'Devuelto', 0),
(10, 4, 4, '2026-10-02 20:48:54', '2026-10-09 20:48:54', '2026-10-02 20:49:51', 'Devuelto', 0),
(11, 4, 4, '2026-10-02 21:16:06', '2026-10-09 21:16:06', '2026-10-12 04:01:31', 'Devuelto', 0),
(12, 4, 1, '2026-10-12 03:27:15', '2026-10-19 03:27:15', '2026-10-12 12:32:11', 'Devuelto', 0),
(13, 4, 1, '2026-10-12 12:31:59', '2026-10-19 12:31:59', '2026-10-12 12:32:10', 'Devuelto', 0),
(14, 4, 4, '2026-10-12 12:32:28', '2026-10-19 12:32:28', '2026-10-12 12:32:43', 'Devuelto', 0),
(15, 3, 1, '2026-10-16 00:10:02', '2026-10-23 00:10:02', '2026-10-20 04:31:59', 'Devuelto', 0),
(16, 3, 1, '2026-10-16 00:10:13', '2026-10-23 00:10:13', '2026-10-20 04:31:58', 'Devuelto', 0),
(17, 3, 1, '2026-10-16 00:10:33', '2026-10-23 00:10:33', '2026-10-20 04:31:55', 'Devuelto', 0),
(18, 4, 1, '2026-10-16 00:37:58', '2026-10-23 00:37:58', '2026-10-20 04:31:53', 'Devuelto', 0),
(19, 7, 1, '2026-10-20 17:19:54', '2026-10-27 17:19:54', NULL, 'Con Mora', 0),
(20, 7, 1, '2026-10-21 07:14:07', '2026-10-28 07:14:07', NULL, 'Con Mora', 0),
(21, 9, 1, '2026-10-22 18:44:39', '2026-10-22 23:59:59', '2026-10-23 00:44:53', 'Devuelto', 0),
(22, 9, 1, '2026-10-23 08:10:57', '2026-10-30 08:10:57', NULL, 'Con Mora', 0),
(23, 7, 1, '2026-10-26 17:00:53', '2026-11-02 17:00:53', NULL, 'Con Mora', 0);

--
-- Triggers `prestamo`
--
DELIMITER $$
CREATE TRIGGER `trg_actualizar_ejemplar_devolucion` AFTER UPDATE ON `prestamo` FOR EACH ROW BEGIN
    IF NEW.estado = 'Devuelto' AND OLD.estado != 'Devuelto' THEN
        UPDATE ejemplar
        SET estado_ejemplar = 'Disponible'
        WHERE id_ejemplar IN (SELECT id_ejemplar FROM detalle_prestamo WHERE id_prestamo = NEW.id_prestamo);
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `proveedor`
--

DROP TABLE IF EXISTS `proveedor`;
CREATE TABLE `proveedor` (
  `id_proveedor` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `tipo` varchar(50) NOT NULL,
  `contacto` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `proveedor`
--

INSERT INTO `proveedor` (`id_proveedor`, `nombre`, `tipo`, `contacto`) VALUES
(1, 'Librería Yachaywasi', 'Empresa', '70012345'),
(2, 'Ministerio de Educación', 'Donante', 'contacto@minedu.gob.bo');

-- --------------------------------------------------------

--
-- Table structure for table `reglaprestamo`
--

DROP TABLE IF EXISTS `reglaprestamo`;
CREATE TABLE `reglaprestamo` (
  `id_regla` int(11) NOT NULL,
  `id_rol` int(11) NOT NULL,
  `id_tipo_material` int(11) NOT NULL,
  `dias_prestamo` int(11) NOT NULL DEFAULT 7,
  `max_renovaciones` int(11) NOT NULL DEFAULT 0,
  `max_prestamos_simultaneos` int(11) NOT NULL DEFAULT 5,
  `permite_domicilio` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `reglaprestamo`
--

INSERT INTO `reglaprestamo` (`id_regla`, `id_rol`, `id_tipo_material`, `dias_prestamo`, `max_renovaciones`, `max_prestamos_simultaneos`, `permite_domicilio`) VALUES
(1, 4, 1, 7, 2, 3, 1),
(2, 3, 1, 15, 3, 5, 1);

-- --------------------------------------------------------

--
-- Table structure for table `reserva`
--

DROP TABLE IF EXISTS `reserva`;
CREATE TABLE `reserva` (
  `id_reserva` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `id_material` int(11) NOT NULL,
  `estado` enum('Pendiente','Atendida','Cancelada','Vencida') NOT NULL DEFAULT 'Pendiente',
  `prioridad` int(11) NOT NULL DEFAULT 1,
  `fecha_aviso` datetime DEFAULT NULL,
  `fecha_limite_retiro` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `reserva`
--

INSERT INTO `reserva` (`id_reserva`, `id_usuario`, `id_material`, `estado`, `prioridad`, `fecha_aviso`, `fecha_limite_retiro`) VALUES
(1, 9, 3, 'Pendiente', 1, NULL, NULL),
(2, 9, 5, 'Cancelada', 1, NULL, NULL),
(3, 12, 5, 'Cancelada', 2, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `rol`
--

DROP TABLE IF EXISTS `rol`;
CREATE TABLE `rol` (
  `id_rol` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rol`
--

INSERT INTO `rol` (`id_rol`, `nombre`) VALUES
(1, 'Administrador'),
(2, 'Bibliotecario'),
(3, 'Docente'),
(4, 'Estudiante');

-- --------------------------------------------------------

--
-- Table structure for table `tipomaterial`
--

DROP TABLE IF EXISTS `tipomaterial`;
CREATE TABLE `tipomaterial` (
  `id_tipo_material` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tipomaterial`
--

INSERT INTO `tipomaterial` (`id_tipo_material`, `nombre`) VALUES
(1, 'Libro'),
(4, 'Proyecto de Grado'),
(2, 'Revista'),
(3, 'Tesis');

-- --------------------------------------------------------

--
-- Table structure for table `ubicacionfisica`
--

DROP TABLE IF EXISTS `ubicacionfisica`;
CREATE TABLE `ubicacionfisica` (
  `id_ubicacion` int(11) NOT NULL,
  `sala` varchar(50) NOT NULL,
  `estante` varchar(50) NOT NULL,
  `nivel` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ubicacionfisica`
--

INSERT INTO `ubicacionfisica` (`id_ubicacion`, `sala`, `estante`, `nivel`) VALUES
(1, 'Sala Central', 'A-1', 'Nivel 2'),
(2, 'Sala Central', 'B-3', 'Nivel 1');

-- --------------------------------------------------------

--
-- Table structure for table `usuario`
--

DROP TABLE IF EXISTS `usuario`;
CREATE TABLE `usuario` (
  `id_usuario` int(11) NOT NULL,
  `nombres` varchar(100) NOT NULL,
  `apellidos` varchar(100) NOT NULL,
  `correo` varchar(150) NOT NULL,
  `contrasena` varchar(255) DEFAULT NULL,
  `id_rol` int(11) NOT NULL,
  `codigo_empleado` varchar(50) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `nivel_permiso` int(11) NOT NULL DEFAULT 1,
  `estado` enum('Activo','Suspendido','Bloqueado') NOT NULL DEFAULT 'Activo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `usuario`
--

INSERT INTO `usuario` (`id_usuario`, `nombres`, `apellidos`, `correo`, `contrasena`, `id_rol`, `codigo_empleado`, `telefono`, `nivel_permiso`, `estado`) VALUES
(1, 'Carlos', 'Mendoza', 'admin@semsad.edu.bo', '$2y$10$abcdefghijklmnopqrstuu.dMwedzAQDvB9n9w1WARue.WqffiA4K', 1, 'ADM-001', NULL, 3, 'Activo'),
(2, 'Lucía', 'Fernández', 'lfernandez@semsad.edu.bo', '$2y$10$e8T3.5S8Zq8Jq2g0k3h.vO9WzG4Z.X9Y2Z0Z0Z0Z0Z0Z0Z0Z0Z', 2, 'BIB-001', NULL, 2, 'Suspendido'),
(3, 'Juan', 'Pérez', 'jperez@est.semsad.edu.bo', '$2y$10$e8T3.5S8Zq8Jq2g0k3h.vO9WzG4Z.X9Y2Z0Z0Z0Z0Z0Z0Z0Z0Z', 4, 'EST-202401', NULL, 1, 'Suspendido'),
(4, 'Maria', 'Gomez', 'mgomez@est.semsad.edu.bo', '$2y$10$e8T3.5S8Zq8Jq2g0k3h.vO9WzG4Z.X9Y2Z0Z0Z0Z0Z0Z0Z0Z0Z', 4, '$2y$10$OLVvj3QU8QvRkyZxHZ0oSucfiVem.9CHYM6sO.TTI6.', NULL, 1, 'Suspendido'),
(5, 'Roberto', 'Siles', 'rsiles@doc.semsad.edu.bo', '$2y$10$e8T3.5S8Zq8Jq2g0k3h.vO9WzG4Z.X9Y2Z0Z0Z0Z0Z0Z0Z0Z0Z', 3, 'DOC-001', NULL, 2, 'Suspendido'),
(7, 'Leonardo', 'Yujra', 'leogutisp@gmail.com', '$2y$12$HFh.3kjd7QpD6ii9rMZ/YeyDxThj0YJQ3cclzwad.Hn7jVbOdMCYe', 4, '11305021', '67720023', 1, 'Activo'),
(8, 'Elizabeth', 'Gutierrez', 'eligutisp07@gmail.com', '$2y$12$jAYc60S3scOxAxVW9//sJ.Jo7EGN0gcCdP.lkN4UtWRAOZwEqT06O', 4, '9030489', '67723881', 1, 'Activo'),
(9, 'kenji albert', 'ramos choque', 'ramoskenji087@gmail.com', '$2y$12$JJJ0UwDBR.hIE0YFD1Nju.sXow3oV3344acq4BNBu1bz97e/0kN7W', 4, '9490428', '77640394', 1, 'Activo'),
(10, 'Guery Fernando', 'Alarcon Hurtado', 'gueryfalarcon@gmail.com', '$2y$12$XdyjgfvWC.00GSJV5j7kVezd4U7yhlNDZk9LnDNz/Vgunzx4rXnTC', 4, '8981285', '75631175', 1, 'Activo'),
(11, 'Berman Mishael', 'Cabrera Parra', 'bmcabreraparra@gmail.com', '$2y$12$Ct4qI4.Ak85numIAF/t4wOBrv5mh0ou4tuts31iHNGalZ171tMk4e', 4, '13540789', '63546559', 1, 'Activo'),
(12, 'Gutemberg', 'Hurtado', 'gutemhurtado777@gmail.com', '$2y$12$zbrGBhsZVeticKtquDBoneXIYbOunj7/5RrpMzR1kX1gcwHNmX5A6', 4, '8185693', '62214922', 1, 'Activo'),
(13, 'Nayara Thyare', 'Mamani Challapa', 'nayarathiare31@gmail.com', '$2y$12$cHLNl2tjx6rHltEfP43gR.uB.F7sGNbhnjCK8pOi2pxhQfiQ5nRXC', 4, '21499480-1', '930117139', 1, 'Activo'),
(14, 'Pepe', 'El pollo', 'angeloyujra721@gmail.com', '$2y$12$OPggRwqFgx2CoWRmr0opHuBzG/dYfaLUXL2v26DSxs4tAiozgnPdW', 4, '12345678', '123456789', 1, 'Activo');

-- --------------------------------------------------------

--
-- Indexes for dumped tables
--

--
-- Indexes for table `adquisicion`
--
ALTER TABLE `adquisicion`
  ADD PRIMARY KEY (`id_adquisicion`),
  ADD KEY `fk_adquisicion_proveedor` (`id_proveedor`);

--
-- Indexes for table `auditoria_recuperacion`
--
ALTER TABLE `auditoria_recuperacion`
  ADD PRIMARY KEY (`id_log`),
  ADD KEY `id_usuario` (`id_usuario`);

--
-- Indexes for table `autor`
--
ALTER TABLE `autor`
  ADD PRIMARY KEY (`id_autor`);

--
-- Indexes for table `categoria`
--
ALTER TABLE `categoria`
  ADD PRIMARY KEY (`id_categoria`),
  ADD KEY `fk_categoria_padre` (`id_categoria_padre`);

--
-- Indexes for table `codigo_verificacion`
--
ALTER TABLE `codigo_verificacion`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `detalle_prestamo`
--
ALTER TABLE `detalle_prestamo`
  ADD PRIMARY KEY (`id_detalle`),
  ADD KEY `fk_detalle_prestamo` (`id_prestamo`),
  ADD KEY `fk_detalle_ejemplar` (`id_ejemplar`);

--
-- Indexes for table `editorial`
--
ALTER TABLE `editorial`
  ADD PRIMARY KEY (`id_editorial`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indexes for table `ejemplar`
--
ALTER TABLE `ejemplar`
  ADD PRIMARY KEY (`id_ejemplar`),
  ADD KEY `fk_ejemplar_material` (`id_material`),
  ADD KEY `fk_ejemplar_adquisicion` (`id_adquisicion`),
  ADD KEY `fk_ejemplar_ubicacion` (`id_ubicacion`);

--
-- Indexes for table `intentos_seguridad`
--
ALTER TABLE `intentos_seguridad`
  ADD PRIMARY KEY (`clave`);

--
-- Indexes for table `materialbibliografico`
--
ALTER TABLE `materialbibliografico`
  ADD PRIMARY KEY (`id_material`),
  ADD UNIQUE KEY `codigo_isbn_issn` (`codigo_isbn_issn`),
  ADD KEY `fk_material_tipo` (`id_tipo_material`),
  ADD KEY `fk_material_editorial` (`id_editorial`),
  ADD KEY `fk_material_categoria` (`id_categoria`);

--
-- Indexes for table `material_autor`
--
ALTER TABLE `material_autor`
  ADD PRIMARY KEY (`id_material`,`id_autor`),
  ADD KEY `fk_ma_autor` (`id_autor`);

--
-- Indexes for table `multa`
--
ALTER TABLE `multa`
  ADD PRIMARY KEY (`id_multa`),
  ADD KEY `fk_multa_prestamo` (`id_prestamo`),
  ADD KEY `fk_multa_detalle` (`id_detalle`);

--
-- Indexes for table `prestamo`
--
ALTER TABLE `prestamo`
  ADD PRIMARY KEY (`id_prestamo`),
  ADD KEY `fk_prestamo_usuario` (`id_usuario`),
  ADD KEY `fk_prestamo_bibliotecario` (`id_bibliotecario_entrega`);

--
-- Indexes for table `proveedor`
--
ALTER TABLE `proveedor`
  ADD PRIMARY KEY (`id_proveedor`);

--
-- Indexes for table `reglaprestamo`
--
ALTER TABLE `reglaprestamo`
  ADD PRIMARY KEY (`id_regla`),
  ADD KEY `fk_regla_rol` (`id_rol`),
  ADD KEY `fk_regla_tipomaterial` (`id_tipo_material`);

--
-- Indexes for table `reserva`
--
ALTER TABLE `reserva`
  ADD PRIMARY KEY (`id_reserva`),
  ADD KEY `fk_reserva_usuario` (`id_usuario`),
  ADD KEY `fk_reserva_material` (`id_material`);

--
-- Indexes for table `rol`
--
ALTER TABLE `rol`
  ADD PRIMARY KEY (`id_rol`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indexes for table `tipomaterial`
--
ALTER TABLE `tipomaterial`
  ADD PRIMARY KEY (`id_tipo_material`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indexes for table `ubicacionfisica`
--
ALTER TABLE `ubicacionfisica`
  ADD PRIMARY KEY (`id_ubicacion`);

--
-- Indexes for table `usuario`
--
ALTER TABLE `usuario`
  ADD PRIMARY KEY (`id_usuario`),
  ADD UNIQUE KEY `correo` (`correo`),
  ADD UNIQUE KEY `telefono` (`telefono`),
  ADD KEY `fk_usuario_rol` (`id_rol`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `adquisicion`
--
ALTER TABLE `adquisicion`
  MODIFY `id_adquisicion` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `auditoria_recuperacion`
--
ALTER TABLE `auditoria_recuperacion`
  MODIFY `id_log` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `autor`
--
ALTER TABLE `autor`
  MODIFY `id_autor` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `categoria`
--
ALTER TABLE `categoria`
  MODIFY `id_categoria` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `codigo_verificacion`
--
ALTER TABLE `codigo_verificacion`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `detalle_prestamo`
--
ALTER TABLE `detalle_prestamo`
  MODIFY `id_detalle` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `editorial`
--
ALTER TABLE `editorial`
  MODIFY `id_editorial` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `ejemplar`
--
ALTER TABLE `ejemplar`
  MODIFY `id_ejemplar` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `materialbibliografico`
--
ALTER TABLE `materialbibliografico`
  MODIFY `id_material` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `multa`
--
ALTER TABLE `multa`
  MODIFY `id_multa` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `prestamo`
--
ALTER TABLE `prestamo`
  MODIFY `id_prestamo` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `proveedor`
--
ALTER TABLE `proveedor`
  MODIFY `id_proveedor` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `reglaprestamo`
--
ALTER TABLE `reglaprestamo`
  MODIFY `id_regla` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `reserva`
--
ALTER TABLE `reserva`
  MODIFY `id_reserva` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `rol`
--
ALTER TABLE `rol`
  MODIFY `id_rol` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tipomaterial`
--
ALTER TABLE `tipomaterial`
  MODIFY `id_tipo_material` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `ubicacionfisica`
--
ALTER TABLE `ubicacionfisica`
  MODIFY `id_ubicacion` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `usuario`
--
ALTER TABLE `usuario`
  MODIFY `id_usuario` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `adquisicion`
--
ALTER TABLE `adquisicion`
  ADD CONSTRAINT `fk_adquisicion_proveedor` FOREIGN KEY (`id_proveedor`) REFERENCES `proveedor` (`id_proveedor`) ON UPDATE CASCADE;

--
-- Constraints for table `auditoria_recuperacion`
--
ALTER TABLE `auditoria_recuperacion`
  ADD CONSTRAINT `auditoria_recuperacion_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`) ON DELETE SET NULL;

--
-- Constraints for table `categoria`
--
ALTER TABLE `categoria`
  ADD CONSTRAINT `fk_categoria_padre` FOREIGN KEY (`id_categoria_padre`) REFERENCES `categoria` (`id_categoria`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `detalle_prestamo`
--
ALTER TABLE `detalle_prestamo`
  ADD CONSTRAINT `fk_detalle_ejemplar` FOREIGN KEY (`id_ejemplar`) REFERENCES `ejemplar` (`id_ejemplar`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_detalle_prestamo` FOREIGN KEY (`id_prestamo`) REFERENCES `prestamo` (`id_prestamo`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `ejemplar`
--
ALTER TABLE `ejemplar`
  ADD CONSTRAINT `fk_ejemplar_adquisicion` FOREIGN KEY (`id_adquisicion`) REFERENCES `adquisicion` (`id_adquisicion`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ejemplar_material` FOREIGN KEY (`id_material`) REFERENCES `materialbibliografico` (`id_material`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ejemplar_ubicacion` FOREIGN KEY (`id_ubicacion`) REFERENCES `ubicacionfisica` (`id_ubicacion`) ON UPDATE CASCADE;

--
-- Constraints for table `materialbibliografico`
--
ALTER TABLE `materialbibliografico`
  ADD CONSTRAINT `fk_material_categoria` FOREIGN KEY (`id_categoria`) REFERENCES `categoria` (`id_categoria`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_material_editorial` FOREIGN KEY (`id_editorial`) REFERENCES `editorial` (`id_editorial`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_material_tipo` FOREIGN KEY (`id_tipo_material`) REFERENCES `tipomaterial` (`id_tipo_material`) ON UPDATE CASCADE;

--
-- Constraints for table `material_autor`
--
ALTER TABLE `material_autor`
  ADD CONSTRAINT `fk_ma_autor` FOREIGN KEY (`id_autor`) REFERENCES `autor` (`id_autor`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ma_material` FOREIGN KEY (`id_material`) REFERENCES `materialbibliografico` (`id_material`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `multa`
--
ALTER TABLE `multa`
  ADD CONSTRAINT `fk_multa_detalle` FOREIGN KEY (`id_detalle`) REFERENCES `detalle_prestamo` (`id_detalle`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_multa_prestamo` FOREIGN KEY (`id_prestamo`) REFERENCES `prestamo` (`id_prestamo`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `prestamo`
--
ALTER TABLE `prestamo`
  ADD CONSTRAINT `fk_prestamo_bibliotecario` FOREIGN KEY (`id_bibliotecario_entrega`) REFERENCES `usuario` (`id_usuario`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_prestamo_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`) ON UPDATE CASCADE;

--
-- Constraints for table `reglaprestamo`
--
ALTER TABLE `reglaprestamo`
  ADD CONSTRAINT `fk_regla_rol` FOREIGN KEY (`id_rol`) REFERENCES `rol` (`id_rol`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_regla_tipomaterial` FOREIGN KEY (`id_tipo_material`) REFERENCES `tipomaterial` (`id_tipo_material`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `reserva`
--
ALTER TABLE `reserva`
  ADD CONSTRAINT `fk_reserva_material` FOREIGN KEY (`id_material`) REFERENCES `materialbibliografico` (`id_material`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_reserva_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `usuario`
--
ALTER TABLE `usuario`
  ADD CONSTRAINT `fk_usuario_rol` FOREIGN KEY (`id_rol`) REFERENCES `rol` (`id_rol`) ON UPDATE CASCADE;

--