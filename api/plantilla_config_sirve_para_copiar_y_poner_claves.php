<?php
// ARCHIVO: plantilla_config_sirve_para_copiar_y_poner_claves.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: ser la plantilla de la configuración de la base de datos (se copia como config.php y se llena con tus datos).
// QUÉ HACE: Devuelve un arreglo con los datos de conexión (host, puerto, nombre, usuario, contraseña) y la opción debug.
//   El archivo de conexión lo carga al iniciar y mezcla estos valores con sus valores por defecto.
// CONECTA CON: api/conexion_sirve_para_conectar_base_datos.php (lo carga con require)
/**
 * Copia este archivo como  api/config.php  (en el servidor de AlwaysData) y completa tus datos.
 * api/config.php NO se sube a GitHub (está en .gitignore).
 */
// Devuelve el arreglo de configuración; quien lo carga con require recibe estos valores.
return [
    'host'  => 'mysql-bibliosemsad.alwaysdata.net',   // Panel AlwaysData > Bases de datos > MySQL
    'port'  => '3306',
    'name'  => 'bibliosemsad_db',
    'user'  => 'bibliosemsad',
    // Cambia esta contraseña por la real de tu base de datos. Nunca subas el config.php real a GitHub.
    'pass'  => 'PON_AQUI_TU_NUEVA_CONTRASEÑA',

    // Mientras pruebas, pon true para ver el error real de la base de datos. Luego vuelve a false.
    'debug' => false,

    // Solo si usas un dominio propio para el frontend (los *.vercel.app ya están permitidos):
    // 'allowed_origins' => ['https://biblioteca.midominio.com'],
];
