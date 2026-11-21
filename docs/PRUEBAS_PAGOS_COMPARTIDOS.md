# Biblioteca SEMSAD — integración de operaciones de prueba entre estudiante y administrador

Este cambio **no conecta bancos ni PayPal reales**. Usa métodos de prueba predefinidos y registra el resultado en tablas dedicadas, de modo que el estudiante y el administrador puedan consultar la misma operación desde distintos equipos. Un cobro verdadero exige una pasarela oficial de pagos y su confirmación de servidor.

## Preparación sin afectar el proyecto publicado

1. Hacer **copia de seguridad** de la base de datos desde phpMyAdmin (Exportar). Conservarla fuera del repositorio.
2. Revisar y ejecutar `db/migracion_pagos_demo.sql` en la base de datos de AlwaysData. Solo crea `pago_demo` y `pago_demo_detalle`. **No altera** las tablas de multas, préstamos, usuarios, caja o comprobantes auténticos.
3. **Publicar** `api/pagos_demo_sirve_para_registrar_y_listar.php` en la carpeta `api/` de AlwaysData. El dominio de Vercel reenvía `/backend/*` a AlwaysData; **fusionar GitHub no garantiza que el archivo PHP se instale automáticamente en AlwaysData**. Comprobar primero la forma de despliegue de tu servidor.
4. Desplegar los archivos frontend de esta rama a Vercel después de confirmar que la migración y la nueva API funcionan. No actualizar `main` ni datos del servidor sin revisión del grupo.

Si la migración o el nuevo endpoint todavía no están publicados, **no usar la integración en la presentación**: la página de pagos mostrará un error y no confirmará operaciones que el administrador no pueda consultar.

## Pruebas de aceptación recomendadas

1. Iniciar sesión como estudiante con una multa pendiente y abrir `principal/pagar_multa.html`.
2. Elegir una tarjeta **predefinida** o el recorrido de PayPal de ejemplo; nunca introducir datos financieros propios.
3. Confirmar el flujo. El servidor debe devolver un código DEMO y un ID de registro. Abrir la constancia `comprobante.html?tipo=demo&id=...`.
4. Desde **otro navegador o equipo**, ingresar como Administrador, abrir **Comprobantes → Operaciones de prueba** y pulsar **Actualizar**. Debe aparecer el mismo código, usuario, importe, método y una vista previa que se pueda imprimir.
5. En **Control de Multas**, confirmar que esa multa tiene la etiqueta **Cubierta en pruebas**, con un enlace a su constancia. La deuda **real** debe continuar pendiente hasta un cobro verdadero.
6. En **Reportes → Reportes de pagos DEMO**, verificar la cantidad y los importes. Los reportes de caja, los ingresos reales y la tabla de multas auténticas no deben cambiar.
7. Recargar la página del estudiante desde otro navegador: la operación debe seguir apareciendo y no debe permitirse repetir la prueba de la misma multa.
8. Abrir el historial de préstamos: si un libro todavía no se devolvió, debe seguir figurando como vencido aunque la multa esté cubierta en el entorno de pruebas.

## Comprobantes anteriores

Los comprobantes de las PR #32–34 solo estaban guardados en `localStorage` del navegador; **el administrador no podía verlos**. Si tienen un identificador válido de multa y corresponden al estudiante que inició sesión, la página de pago ofrece **«Incluir comprobantes anteriores en el registro»**. Se validan de nuevo en el servidor y se evita duplicar una misma multa. Los comprobantes antiguos sin vínculo verificable no deben registrarse automáticamente.

## Reglas de integridad

- Ninguna operación de prueba modifica `multa.estado_pago`, `prestamo.estado`, `usuario.estado`, caja, estadísticas financieras ni recibos reales.
- Los importes se obtienen de las multas pendientes de la cuenta autenticada **en el servidor**; no se confía en montos enviados por el navegador.
- El personal solo puede consultar la lista global; el estudiante solo consulta sus propias operaciones.
- Cada multa puede tener como máximo una operación de prueba registrada por estudiante. No se exponen números de tarjetas, CVV ni credenciales PayPal.
- Las constancias y la pestaña correspondiente están identificadas como **entorno de pruebas / sin cobro bancario**, aunque el diseño sea profesional.

## Limitaciones

No se ha realizado una validación extremo a extremo con la base de AlwaysData: faltan credenciales y despliegue del nuevo endpoint. Esta PR es un cambio preparado para revisión y pruebas del grupo. Para que una pasarela realmente procese operaciones sandbox hay que integrar el SDK/API oficial de un proveedor compatible con el proyecto y validar el resultado en el backend.
