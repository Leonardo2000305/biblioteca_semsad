/* ==========================================================================
   BIBLIOTECA SEMSAD - PANEL DE ADMINISTRACIÓN (ADMIN.JS)
   Conectado al Backend PHP y MySQL - Versión limpia y estable
   ========================================================================== */

const API_BASE = "../api";

const state = {
    books: [],
    loans: []
};

document.addEventListener("DOMContentLoaded", () => {
    initAdminApp();
});

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

async function loadBooks() {
    try {
        const res = await fetch(`${API_BASE}/buscar_libros.php`, { credentials: "include" });
        const data = await res.json();
        if (data.status === "success") state.books = data.datos;
    } catch (e) {
        console.error("Error cargando libros:", e);
    }
}

async function loadLoans() {
    try {
        const res = await fetch(`${API_BASE}/listar_prestamos.php`, { credentials: "include" });
        const data = await res.json();
        if (data.status === "success") state.loans = data.datos;
    } catch (e) {
        console.error("Error cargando préstamos:", e);
    }
}

// ── AUDITORÍA DE SEGURIDAD ─────────────────────────────────────────────────

async function loadAuditLogs() {
    const tbody = document.getElementById("tablaAuditoriaBody") || document.getElementById("auditoriaAdminBody");
    if (!tbody) return;

    try {
        const res = await fetch(`${API_BASE}/obtener_auditoria.php`, { credentials: "include" });
        const data = await res.json();

        if (data.status === "success" && data.datos && data.datos.length > 0) {
            tbody.innerHTML = data.datos.map(item => {
                const esExito = item.estado === 'Exitoso';
                const badgeClass = esExito ? 'bg-success' : 'bg-danger';
                const icono = esExito ? 'fa-circle-check' : 'fa-circle-xmark';
                
                return `
                    <tr>
                        <td><strong>${item.correo_ingresado}</strong></td>
                        <td><i class="fa-regular fa-clock text-muted me-1"></i>${item.fecha_formateada}</td>
                        <td>
                            <span class="badge ${badgeClass} p-2">
                                <i class="fa-solid ${icono} me-1"></i>${item.estado}
                            </span>
                        </td>
                        <td><code>${item.ip_origen || 'Desconocida'}</code></td>
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

window.loadAuditLogs = loadAuditLogs;

// ── NAVEGACIÓN ─────────────────────────────────────────────────────────────

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
                <span class="fw-bold text-primary">${b.classification || "000.0"}</span>
                <small class="d-block text-muted">ISBN: ${b.isbn || "S/C"}</small>
            </td>
            <td>
                <strong>${b.title}</strong>
                <small class="d-block text-muted">${b.author}</small>
            </td>
            <td><span class="badge bg-secondary">${b.categoryLabel || "General"}</span></td>
            <td>
                <span class="fw-bold ${b.stock > 0 ? "text-success" : "text-danger"}">${b.stock}</span>
                <span class="text-muted"> / ${b.total}</span>
            </td>
            <td><small class="text-muted">${b.shelf || "Sala Central"}</small></td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-secondary" title="Editar (próximamente)">
                    <i class="fa-solid fa-pen"></i>
                </button>
            </td>
        </tr>
    `).join("");
}

// ── VENTANILLA ─────────────────────────────────────────────────────────────

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
                <strong>${l.userName}</strong>
                <small class="d-block text-muted">CI: ${l.userCi}</small>
            </td>
            <td><strong>${l.bookTitle}</strong></td>
            <td>${l.issueDate}</td>
            <td>
                <span class="badge ${l.status === "Vencido" ? "bg-danger" : "bg-success"}">${l.dueDate}</span>
            </td>
            <td>
                ${l.overdueDays > 0
                    ? `<span class="badge bg-danger">${l.overdueDays} días demora</span>`
                    : `<span class="badge bg-secondary">A tiempo</span>`
                }
            </td>
            <td class="text-end">
                <button class="btn btn-success btn-sm" onclick="processReturn(${l.id})">
                    <i class="fa-solid fa-rotate-left me-1"></i> Recibir
                </button>
            </td>
        </tr>
    `).join("");
}

// ── DEVOLUCIÓN ─────────────────────────────────────────────────────────────

window.processReturn = async function(loanId) {
    if (!confirm("¿Confirmar recepción y devolución del libro?")) return;

    // El bibliotecario registra el estado físico real del libro
    const respuesta = prompt("Estado físico del libro al recibirlo: escriba Bueno, Regular o Malo", "Bueno");
    if (respuesta === null) return; // canceló
    const condicion = respuesta.trim();
    if (!["Bueno", "Regular", "Malo"].includes(condicion)) {
        showToast("Estado no válido. Escriba Bueno, Regular o Malo.", "danger");
        return;
    }

    try {
        const res = await fetch(`${API_BASE}/registrar_devolucion.php`, {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id_prestamo: loanId, condicion_devolucion: condicion })
        });
        const data = await res.json();

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

function showToast(msg, type = "info") {
    const toastEl   = document.getElementById("adminToast");
    const toastBody = document.getElementById("adminToastBody");
    if (!toastEl || !toastBody) return;
    toastBody.textContent = msg;
    toastEl.className = `toast align-items-center text-white border-0 bg-${type === "success" ? "success" : type === "danger" ? "danger" : "primary"}`;
    new bootstrap.Toast(toastEl, { delay: 5000 }).show();
}
