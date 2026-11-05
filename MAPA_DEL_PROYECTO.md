# Mapa del proyecto BIBLIOSEMSAD

Generado a partir de los encabezados de cada archivo. Cada fila dice para qué sirve el archivo y en qué capa está.

| Archivo | Capa | Sirve para |
|---|---|---|
| `api/buscar_libros_sirve_para_buscador_catalogo.php` | BACKEND | Devolver la lista de libros del catálogo para el buscador que usa el estudiante. |
| `api/cambiar_estado_ejemplar_sirve_para_mantenimiento_o_baja.php` | BACKEND | Cambiar el estado de una copia (ejemplar) de un libro: mandarla a mantenimiento, darla de baja o dejarla disponible. |
| `api/cambiar_estado_usuario_sirve_para_suspender_o_reactivar.php` | BACKEND | Suspender (desactivar) o reactivar la cuenta de un usuario. |
| `api/conexion_sirve_para_conectar_base_datos.php` | BACKEND | Es el archivo base de la API: abre la conexión a la base de datos y reúne funciones que usan todos los demás archivos. |
| `api/devolver_libro_sirve_para_devolucion_desde_ventanilla.php` | BACKEND | Registrar la devolución de un libro prestado cuando la persona lo entrega en la ventanilla. |
| `api/eliminar_libro_sirve_para_quitar_material.php` | BACKEND | Quitar un libro del inventario junto con todas sus copias (ejemplares). |
| `api/enviar_codigo_sirve_para_registro_de_usuario.php` | BACKEND | Enviar al correo de una persona nueva el código de 6 dígitos que necesita para crear su cuenta. |
| `api/guardar_libro_sirve_para_crear_o_editar_material.php` | BACKEND | Crear un libro nuevo en el catálogo o editar uno que ya existe (datos, autores y número de copias). |
| `api/guardar_usuario_sirve_para_crear_o_editar_cuenta.php` | BACKEND | Crear una cuenta de usuario nueva o editar una que ya existe (nombre, correo, teléfono, CI, contraseña y rol). |
| `api/index.php` | — | (pendiente de documentar) |
| `api/listar_ejemplares_sirve_para_copias_de_un_libro.php` | BACKEND | Mostrar en la pantalla de administración las copias (ejemplares) de un libro, con su estado y si están prestadas. |
| `api/listar_prestamos_sirve_para_panel_admin.php` | BACKEND | Mostrar en el panel del administrador la lista de préstamos que todavía no se devolvieron. |
| `api/login_sirve_para_iniciar_sesion.php` | BACKEND | Iniciar sesión con correo o CI, y contraseña. |
| `api/logout_sirve_para_cerrar_sesion.php` | BACKEND | Cerrar la sesión del usuario (salir del sistema). |
| `api/mis_prestamos_sirve_para_historial_del_estudiante.php` | BACKEND | Mostrar al estudiante el historial de sus propios préstamos. |
| `api/obtener_auditoria_sirve_para_ver_intentos_de_acceso.php` | BACKEND | Mostrar al personal el registro de intentos de recuperación de contraseña (quién, cuándo, desde qué IP y con qué resultado). |
| `api/obtener_dashboard_sirve_para_indicadores_del_dia.php` | BACKEND | Dar los tres números del inicio del panel de personal: títulos en el catálogo, títulos con copias disponibles y préstamos activos. |
| `api/obtener_libros_sirve_para_mostrar_catalogo.php` | BACKEND | mostrar el catálogo de libros con cuántas copias están disponibles. |
| `api/obtener_multas_sirve_para_cobrar_y_ver_mora.php` | BACKEND | mostrar al personal las multas pendientes y calcular los días de retraso. |
| `api/obtener_reportes_sirve_para_reportes_e_indicadores.php` | BACKEND | dar al personal los números del día, los libros más pedidos, los morosos, el stock bajo y el inventario. |
| `api/obtener_usuarios_sirve_para_padron_de_usuarios.php` | BACKEND | mostrar al personal la lista de todos los usuarios (el padrón). |
| `api/plantilla_config_sirve_para_copiar_y_poner_claves.php` | BACKEND | ser la plantilla de la configuración de la base de datos (se copia como config.php y se llena con tus datos). |
| `api/registrar_devolucion_sirve_para_recibir_libro_con_estado.php` | BACKEND | registrar que un libro prestado fue devuelto a la biblioteca y en qué estado llegó. |
| `api/registrar_pago_multa_sirve_para_cobrar_multa.php` | BACKEND | registrar que el usuario pagó una multa en ventanilla. |
| `api/registrar_prestamo_sirve_para_pedir_libro_estudiante.php` | BACKEND | dejar que un estudiante o docente pida un libro prestado desde su cuenta. |
| `api/registrar_prestamo_ventanilla_sirve_para_prestamo_en_mostrador.php` | BACKEND | que el bibliotecario preste un libro en mostrador (ventanilla) a un usuario. |
| `api/registrar_usuario_sirve_para_crear_cuenta_al_registrarse.php` | BACKEND | crear una cuenta nueva de estudiante cuando alguien se registra desde la página de registro. |
| `api/reglas_prestamos_sirve_para_multas_y_plazos.php` | BACKEND | guardar en un solo lugar las reglas de préstamos, multas y lista de espera, para que todas las pantallas usen el mismo criterio. |
| `api/renovar_prestamo_sirve_para_ampliar_7_dias.php` | BACKEND | dejar que el estudiante amplíe 7 días más el plazo de un libro que tiene prestado. |
| `api/reservar_material_sirve_para_lista_de_espera.php` | BACKEND | anotar a un estudiante o docente en la lista de espera de un libro sin copias libres, o sacarlo de esa lista. |
| `api/restablecer_contrasena_sirve_para_cambiar_clave_con_enlace.php` | BACKEND | cambiar la contraseña olvidada usando el enlace que llegó al correo. |
| `api/solicitar_recuperacion_sirve_para_enviar_enlace_de_clave.php` | BACKEND | enviar por correo un enlace para cambiar una contraseña olvidada. |
| `api/usuario_actual_sirve_para_identificar_quien_pide.php` | BACKEND | saber qué usuario está conectado, usando solo la sesión del servidor. |
| `api/verificar_sesion_sirve_para_saber_si_hay_login.php` | BACKEND | decirle a la página si hay alguien con la sesión iniciada. |
| `js/config.js` | FRONTEND | guardar en un solo lugar las funciones comunes que usan todas las páginas de la biblioteca. |
| `login/index.html` | FRONTEND | que la persona inicie sesión con su correo o código y su contraseña. |
| `login/recuperar.html` | FRONTEND | que la persona pida un enlace para cambiar su contraseña si la olvidó. |
| `login/registro.html` | FRONTEND | que un estudiante cree su cuenta nueva, confirmando su correo con un código. |
| `login/restablecer.html` | FRONTEND | que la persona escriba una nueva contraseña desde el enlace que llegó a su correo. |
| `principal/admin.html` | FRONTEND | panel operativo del personal (Administrador y Bibliotecario) para gestionar libros, préstamos, multas, reportes, auditoría y usuarios. |
| `principal/index.html` | FRONTEND | portal de estudiantes y docentes: ver el catálogo, buscar libros, pedir préstamos, anotarse en la lista de espera y revisar el historial. |

Archivos marcados como *revisar* en sus comentarios necesitan confirmación antes de cambiarlos.
