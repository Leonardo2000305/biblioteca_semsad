<?php
/**
 * Copia este archivo como  api/config.php  (en el servidor de AlwaysData) y completa tus datos.
 * api/config.php NO se sube a GitHub (está en .gitignore).
 */
return [
    'host'  => 'mysql-bibliosemsad.alwaysdata.net',   // Panel AlwaysData > Bases de datos > MySQL
    'port'  => '3306',
    'name'  => 'bibliosemsad_db',
    'user'  => 'bibliosemsad',
    'pass'  => 'PON_AQUI_TU_NUEVA_CONTRASEÑA',

    // Mientras pruebas, pon true para ver el error real de la base de datos. Luego vuelve a false.
    'debug' => false,

    // Solo si usas un dominio propio para el frontend (los *.vercel.app ya están permitidos):
    // 'allowed_origins' => ['https://biblioteca.midominio.com'],
];
