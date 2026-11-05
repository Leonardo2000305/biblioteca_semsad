// ARCHIVO: admin.js
// CAPA: FRONTEND (lo que ve y usa la persona en el navegador)
// SIRVE PARA: lógica del panel del personal: indicadores, inventario, ventanilla, devoluciones y auditoría.
// QUÉ HACE: pide libros, préstamos y auditoría a la API y dibuja los indicadores y las tablas.
//   Permite cambiar de sección desde el menú y registrar devoluciones indicando el estado del libro.
// CONECTA CON: ../api/buscar_libros_sirve_para_buscador_catalogo.php,
//   listar_prestamos_sirve_para_panel_admin.php, obtener_auditoria_sirve_para_ver_intentos_de_acceso.php
//   y registrar_devolucion_sirve_para_recibir_libro_con_estado.php.
// NOTA: ninguna página HTML carga este archivo; principal/admin.html tiene su propio código
//   (revisar si este archivo se usa o se puede eliminar).
/* ==========================================================================
   BIBLIOTECA SEMSAD - PANEL DE ADMINISTRACIÓN (ADMIN.JS)
   Conectado al Backend PHP y MySQL - Versión limpia y estable
   ========================================================================== */

// Ruta base de la API, relativa a la página que carga este archivo.
const API_BASE = "../api";

// Escapa texto antes de meterlo en innerHTML. Mismo comportamiento que SEMSAD.esc (js/config.js);
// este archivo no carga config.js, por eso la función va aquí.
function escHtml(valor) {
    return String(valor == null ? "" : valor).replace(/[&<>"']/g, c => (
        { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]
    ));
}

// Datos del panel: lista de libros y lista de préstamos, que llegan de la API.
const state = {
    books: [],
    loans: []
};

// Cuando la página carga, inicia el panel.
document.addEventListener("DOMContentLoaded", () => {
    initAdminApp();
});

// Orden de arranque: primero el menú, después los datos y por último se dibuja cada sección.
async function initAdminApp() {
    setupNavigation();
    await loadBooks();
    await loadLoans();
    renderDashboard();
    renderBooksTable();
    renderCirculationView();
    await loadAuditLogs();
}

// ── CARGA DE DATOS ─────────────────────────────────────────────────────────

// Descarga el catálogo y lo guarda en state.books.
async function loadBooks() {
    try {
        const res = await fetch(`${API_BASE}/buscar_libros_sirve_para_buscador_catalogo.php`, { credentials: "include" });
        const data = await res.json();
        if (data.status === "success") state.books = data.datos;
    } catch (e) {
        console.error("Error cargando libros:", e);
    }
}

// Descarga los préstamos y los guarda en state.loans.
async function loadLoans() {
    try {
        const res = await fetch(`${API_BASE}/listar_prestamos_sirve_para_panel_admin.php`, { credentials: "include" });
        const data = await res.json();
        if (data.status === "success") state.loans = data.datos;
    } catch (e) {
        console.error("Error cargando préstamos:", e);
    }
}

// ── AUDITORÍA DE SEGURIDAD ─────────────────────────────────────────────────

// Dibuja la tabla de auditoría (intentos de inicio de sesión).
async function loadAuditLogs() {
    const tbody = document.getElementById("tablaAuditoriaBody") || document.getElementById("auditoriaAdminBody");
    if (!tbody) return;

    try {
        // Pide la auditoría a la API.
        const res = await fetch(`${API_BASE}/obtener_auditoria_sirve_para_ver_intentos_de_acceso.php`, { credentials: "include" });
        const data = await res.json();

        // Si hay datos, arma una fila por intento: éxito en verde y fallo en rojo.
        if (data.status === "success" && data.datos && data.datos.length > 0) {
            tbody.innerHTML = data.datos.map(item => {
                const esExito = item.estado === 'Exitoso';
                const badgeClass = esExito ? 'bg-success' : 'bg-danger';
                const icono = esExito ? 'fa-circle-check' : 'fa-circle-xmark';
                
                return `
                    <tr>
                        <td><strong>${escHtml(item.correo_ingresado)}</strong></td>
                        <td><i class="fa-regular fa-clock text-muted me-1"></i>${escHtml(item.fecha_formateada)}</td>
                        <td>
                            <span class="badge ${badgeClass} p-2">
                                <i class="fa-solid ${icono} me-1"></i>${escHtml(item.estado)}
                            </span>
                        </td>
                        <td><code>${escHtml(item.ip_origen || 'Desconocida')}</code></td>
                    </tr>
                `;
            }).join('');
        } else {
            tbody.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-3">No hay registros de intentos de recuperación.</td></tr>`;
        }
    } catch (e) {
        console.error("Error cargando auditoría:", e);
        tbody.innerHTML = `<tr><td colspan="4" class="text-center text-danger py-3">Error de conexión al cargar auditoría.</td></tr>`;
    }
}

// Permite llamar a esta función desde el HTML.
window.loadAuditLogs = loadAuditLogs;

// ── NAVEGACIÓN ─────────────────────────────────────────────────────────────

// Menú lateral: al hacer clic en una opción se resalta y se muestra su sección; las demás se ocultan.
function setupNavigation() {
    const navItems = document.querySelectorAll(".nav-item-admin");
    const sections = document.querySelectorAll(".admin-section");

    navItems.forEach(item => {
        item.addEventListener("click", (e) => {
            e.preventDefault();
            const targetId = item.getAttribute("data-target");

            navItems.forEach(n => n.classList.remove("active"));
            sections.forEach(s => s.classList.add("d-none"));

            item.classList.add("active");
            const sec = document.getElementById(targetId);
            if (sec) sec.classList.remove("d-none");
        });
    });
}

// ── DASHBOARD ──────────────────────────────────────────────────────────────

// Calcula los indicadores: total de libros, disponibles (suma de stock) y préstamos activos o vencidos.
function renderDashboard() {
    const totalBooks   = state.books.reduce((s, b) => s + (b.total || 0), 0);
    const availBooks   = state.books.reduce((s, b) => s + (b.stock || 0), 0);
    const activeLoans  = state.loans.filter(l => l.status === "Activo" || l.status === "Vencido").length;

    const elTotal  = document.getElementById("kpiTotalBooks");
    const elAvail  = document.getElementById("kpiAvailableBooks");
    const elLoans  = document.getElementById("kpiActiveLoans");

    if (elTotal)  elTotal.textContent  = totalBooks;
    if (elAvail)  elAvail.textContent  = availBooks;
    if (elLoans)  elLoans.textContent  = activeLoans;
}

// ── INVENTARIO ─────────────────────────────────────────────────────────────

// Dibuja la tabla de inventario, una fila por libro. El botón Editar todavía no hace nada (próximamente).
function renderBooksTable() {
    const tbody = document.getElementById("adminBooksTableBody");
    if (!tbody) return;

    if (state.books.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center py-3 text-muted">No se encontraron libros en la base de datos.</td></tr>`;
        return;
    }

    tbody.innerHTML = state.books.map(b => `
        <tr>
            <td>
                <span class="fw-bold text-primary">${escHtml(b.classification || "000.0")}</span>
                <small class="d-block text-muted">ISBN: ${escHtml(b.isbn || "S/C")}</small>
            </td>
            <td>
                <strong>${escHtml(b.title)}</strong>
                <small class="d-block text-muted">${escHtml(b.author)}</small>
            </td>
            <td><span class="badge bg-secondary">${escHtml(b.categoryLabel || "General")}</span></td>
            <td>
                <span class="fw-bold ${b.stock > 0 ? "text-success" : "text-danger"}">${escHtml(b.stock)}</span>
                <span class="text-muted"> / ${escHtml(b.total)}</span>
            </td>
            <td><small class="text-muted">${escHtml(b.shelf || "Sala Central")}</small></td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-secondary" title="Editar (próximamente)">
                    <i class="fa-solid fa-pen"></i>
                </button>
            </td>
        </tr>
    `).join("");
}

// ── VENTANILLA ─────────────────────────────────────────────────────────────

// Dibuja la tabla de ventanilla con los préstamos activos o vencidos. Cada fila tiene el botón Recibir.
function renderCirculationView() {
    const tbody = document.getElementById("circActiveLoansTable");
    if (!tbody) return;

    const activos = state.loans.filter(l => l.status === "Activo" || l.status === "Vencido");

    if (activos.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center py-3 text-muted">No hay préstamos activos en este momento.</td></tr>`;
        return;
    }

    tbody.innerHTML = activos.map(l => `
        <tr>
            <td>
                <strong>${escHtml(l.userName)}</strong>
                <small class="d-block text-muted">CI: ${escHtml(l.userCi)}</small>
            </td>
            <td><strong>${escHtml(l.bookTitle)}</strong></td>
            <td>${escHtml(l.issueDate)}</td>
            <td>
                <span class="badge ${l.status === "Vencido" ? "bg-danger" : "bg-success"}">${escHtml(l.dueDate)}</span>
            </td>
            <td>
                ${l.overdueDays > 0
                    ? `<span class="badge bg-danger">${escHtml(l.overdueDays)} días demora</span>`
                    : `<span class="badge bg-secondary">A tiempo</span>`
                }
            </td>
            <td class="text-end">
                <button class="btn btn-success btn-sm" onclick="processReturn(${Number(l.id)})">
                    <i class="fa-solid fa-rotate-left me-1"></i> Recibir
                </button>
            </td>
        </tr>
    `).join("");
}

// ── DEVOLUCIÓN ─────────────────────────────────────────────────────────────

// Proceso de devolución: confirmar, pedir el estado físico del libro y enviarlo al servidor.
window.processReturn = async function(loanId) {
    if (!confirm("¿Confirmar recepción y devolución del libro?")) return;

    // El bibliotecario registra el estado físico real del libro
    const respuesta = prompt("Estado físico del libro al recibirlo: escriba Bueno, Regular o Malo", "Bueno");
    if (respuesta === null) return; // canceló
    const condicion = respuesta.trim();
    // Solo acepta Bueno, Regular o Malo; cualquier otra palabra se rechaza.
    if (!["Bueno", "Regular", "Malo"].includes(condicion)) {
        showToast("Estado no válido. Escriba Bueno, Regular o Malo.", "danger");
        return;
    }

    try {
        // Envía el id del préstamo y el estado físico a la API (POST).
        const res = await fetch(`${API_BASE}/registrar_devolucion_sirve_para_recibir_libro_con_estado.php`, {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id_prestamo: loanId, condicion_devolucion: condicion })
        });
        const data = await res.json();

        // Si se registró: muestra el aviso y vuelve a cargar datos, indicadores, ventanilla e inventario.
        if (data.status === "success") {
            showToast("Devolución registrada. El ejemplar vuelve a estar Disponible.", "success");
            await loadLoans();
            await loadBooks();
            renderDashboard();
            renderCirculationView();
            renderBooksTable();
        } else {
            showToast(data.mensaje || "Error al registrar la devolución.", "danger");
        }
    } catch (e) {
        showToast("Error de conexión con el servidor.", "danger");
    }
};

// ── TOAST ──────────────────────────────────────────────────────────────────

// Muestra un aviso de Bootstrap en el contenedor adminToast; el color depende del tipo.
function showToast(msg, type = "info") {
    const toastEl   = document.getElementById("adminToast");
    const toastBody = document.getElementById("adminToastBody");
    if (!toastEl || !toastBody) return;
    toastBody.textContent = msg;
    toastEl.className = `toast align-items-center text-white border-0 bg-${type === "success" ? "success" : type === "danger" ? "danger" : "primary"}`;
    new bootstrap.Toast(toastEl, { delay: 5000 }).show();
}
