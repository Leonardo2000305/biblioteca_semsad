// ARCHIVO: login.js
// CAPA: FRONTEND (lo que ve y usa la persona en el navegador)
// SIRVE PARA: procesar el formulario de inicio de sesión (correo y código de empleado).
// QUÉ HACE: envía los datos a la API; si el login es correcto guarda el usuario en localStorage
//   y lo lleva a su portal (estudiantes y docentes al portal; el resto al panel admin).
//   Si falla, muestra el mensaje de error debajo del formulario.
// CONECTA CON: ../api/login_sirve_para_iniciar_sesion.php
// NOTA: ninguna página HTML carga este archivo. La página login/index.html tiene su propio script
//   (revisar si este archivo se usa o se puede eliminar).
document.addEventListener("DOMContentLoaded", () => {
    // Busca el formulario y el cuadro donde se muestra el error.
    const formLogin = document.getElementById("form-login");
    const errorDiv = document.getElementById("login-error");

    if (formLogin) {
        // Al enviar el formulario se ejecuta esta función. Es async porque espera la respuesta del servidor.
        formLogin.addEventListener("submit", async (e) => {
            // Evita que el formulario recargue la página; el envío se hace con fetch.
            e.preventDefault();

            // Lee el correo y el código de empleado, quitando espacios sobrantes.
            const correo = document.getElementById("correo").value.trim();
            const codigo_empleado = document.getElementById("codigo_empleado").value.trim();
            // Oculta el error anterior antes de intentar de nuevo.
            errorDiv.style.display = "none";

            try {
                // Llamada POST a la API de login con los datos en JSON. credentials include permite guardar la cookie de sesión.
                const response = await fetch("../api/login_sirve_para_iniciar_sesion.php", {
                    method: "POST",
                    credentials: "include",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ correo, codigo_empleado })
                });

                // Convierte la respuesta del servidor de texto a un objeto.
                const data = await response.json();

                // Respuesta correcta: el servidor confirmó el inicio de sesión.
                if (data.status === "success") {
                    // Guardar TODOS los datos del usuario en localStorage como fuente confiable
                    localStorage.setItem("semsad_user", JSON.stringify({
                        id: data.usuario.id_usuario,
                        name: data.usuario.nombre,
                        role: data.usuario.rol,
                        ci: data.usuario.ci,
                        correo: data.usuario.correo
                    }));

                    // Redirigir según el rol
                    // Estudiantes y docentes van al portal; cualquier otro rol va al panel admin.
                    if (data.usuario.rol === "Estudiante" || data.usuario.rol === "Docente") {
                        window.location.href = "../principal/index.html";
                    } else {
                        window.location.href = "../principal/admin.html";
                    }
                } else {
                    // Credenciales incorrectas u otro error del servidor: se muestra su mensaje.
                    errorDiv.textContent = data.mensaje || "Credenciales incorrectas.";
                    errorDiv.style.display = "block";
                }
            // Si no hubo respuesta (sin red o servidor apagado), se muestra un error de conexión.
            } catch (error) {
                errorDiv.textContent = "Error de conexión con el servidor. ¿Está XAMPP encendido?";
                errorDiv.style.display = "block";
            }
        });
    }
});
