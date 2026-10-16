-- MIGRACIÓN OPCIONAL PARA RESERVAS, MULTAS, PERFILES, CATÁLOGO E HISTORIAL DE ACCESOS
-- NO se ejecuta desde la aplicación y NO modifica datos por sí sola.
-- Antes de ejecutarla:
-- 1) Haz una copia de seguridad desde phpMyAdmin (Exportar -> SQL).
-- 2) Abre phpMyAdmin -> Estructura en Reserva, Multa, Usuario y MaterialBibliografico.
-- 3) Si cualquiera de estas columnas ya existe, elimina de los ALTER TABLE la columna duplicada.
-- 4) Ejecuta este archivo una sola vez, durante una ventana segura.
--
-- La API actual detecta si estos campos existen. Mientras no se aplique esta migración,
-- el núcleo de préstamos funciona, pero la caducidad de reservas y la persistencia del
-- método/fecha de pago quedan limitadas.

ALTER TABLE Reserva
    ADD COLUMN fecha_solicitud DATETIME NULL,
    ADD COLUMN fecha_caducidad DATETIME NULL,
    ADD COLUMN fecha_atencion DATETIME NULL,
    ADD COLUMN id_ejemplar INT NULL;

UPDATE Reserva
SET fecha_solicitud = NOW()
WHERE fecha_solicitud IS NULL;

CREATE INDEX idx_reserva_estado_material_prioridad
    ON Reserva (estado, id_material, prioridad);

CREATE INDEX idx_reserva_usuario_estado
    ON Reserva (id_usuario, estado);

ALTER TABLE Reserva
    ADD CONSTRAINT fk_reserva_ejemplar
    FOREIGN KEY (id_ejemplar) REFERENCES Ejemplar (id_ejemplar)
    ON DELETE SET NULL
    ON UPDATE CASCADE;

ALTER TABLE Multa
    ADD COLUMN fecha_pago DATETIME NULL,
    ADD COLUMN metodo_pago ENUM('Efectivo','Digital') NULL,
    ADD COLUMN id_bibliotecario_cobro INT NULL;

CREATE INDEX idx_multa_estado_pago
    ON Multa (estado_pago);

ALTER TABLE Multa
    ADD CONSTRAINT fk_multa_bibliotecario_cobro
    FOREIGN KEY (id_bibliotecario_cobro) REFERENCES Usuario (id_usuario)
    ON DELETE SET NULL
    ON UPDATE CASCADE;

-- Campos complementarios para perfil y metadatos bibliográficos.
ALTER TABLE Usuario
    ADD COLUMN carrera VARCHAR(120) NULL,
    ADD COLUMN fotografia VARCHAR(500) NULL,
    ADD COLUMN ultimo_acceso DATETIME NULL;

ALTER TABLE MaterialBibliografico
    ADD COLUMN edicion VARCHAR(80) NULL,
    ADD COLUMN idioma VARCHAR(50) NULL;

-- Historial de accesos exitosos para consulta y auditoría.
CREATE TABLE historial_acceso (
    id_acceso INT NOT NULL AUTO_INCREMENT,
    id_usuario INT NOT NULL,
    fecha_hora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_origen VARCHAR(45) NULL,
    agente_usuario VARCHAR(255) NULL,
    PRIMARY KEY (id_acceso),
    KEY idx_acceso_usuario_fecha (id_usuario, fecha_hora),
    CONSTRAINT fk_historial_acceso_usuario
        FOREIGN KEY (id_usuario) REFERENCES Usuario (id_usuario)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Verificación final recomendada:
-- SHOW COLUMNS FROM Reserva;
-- SHOW COLUMNS FROM Multa;
-- SHOW CREATE TABLE Reserva;
-- SHOW CREATE TABLE Multa;
-- SHOW CREATE TABLE Usuario;
-- SHOW CREATE TABLE MaterialBibliografico;
-- SHOW CREATE TABLE historial_acceso;
