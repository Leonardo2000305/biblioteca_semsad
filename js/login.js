document.addEventListener("DOMContentLoaded", () => {
    const formLogin = document.getElementById("form-login");
    const errorDiv = document.getElementById("login-error");

    if (formLogin) {
        formLogin.addEventListener("submit", async (e) => {
            e.preventDefault();

            const correo = document.getElementById("correo").value.trim();
            const codigo_empleado = document.getElementById("codigo_empleado").value.trim();
            errorDiv.style.display = "none";

            try {
                const response = await fetch("../api/login.php", {
                    method: "POST",
                    credentials: "include",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ correo, codigo_empleado })
                });

                const data = await response.json();

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
                    if (data.usuario.rol === "Estudiante" || data.usuario.rol === "Docente") {
                        window.location.href = "../principal/index.html";
                    } else {
                        window.location.href = "../principal/admin.html";
                    }
                } else {
                    errorDiv.textContent = data.mensaje || "Credenciales incorrectas.";
                    errorDiv.style.display = "block";
                }
            } catch (error) {
                errorDiv.textContent = "Error de conexión con el servidor. ¿Está XAMPP encendido?";
                errorDiv.style.display = "block";
            }
        });
    }
});
