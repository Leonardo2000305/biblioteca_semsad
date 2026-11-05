// ARCHIVO: app.js
// CAPA: FRONTEND (lo que ve y usa la persona en el navegador)
// SIRVE PARA: lógica del portal de estudiantes: catálogo, búsqueda, reserva y lista de préstamos del usuario.
// QUÉ HACE: al abrir la página carga los libros y los préstamos del usuario, dibuja las tarjetas,
//   filtra por categoría o texto y abre un modal para reservar. Al confirmar, envía la reserva a la API.
// CONECTA CON: ../api/buscar_libros_sirve_para_buscador_catalogo.php,
//   listar_prestamos_sirve_para_panel_admin.php, verificar_sesion_sirve_para_saber_si_hay_login.php,
//   registrar_prestamo_sirve_para_pedir_libro_estudiante.php y logout_sirve_para_cerrar_sesion.php.
// NOTA: ninguna página HTML carga este archivo; principal/index.html tiene su propio script
//   (revisar si este archivo se usa o se puede eliminar).
/* ==========================================================================
   BIBLIOTECA SEMSAD - LÓGICA PORTAL ESTUDIANTES (APP.JS)
   Conectado al Backend PHP y MySQL - Versión estable
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

// Estado de la app: usuario actual, categoría elegida, texto buscado, préstamos y libros.
const state = {
    currentUser: { id: null, ci: null, name: null, role: null, active: false },
    activeCategory: "all",
    searchQuery: "",
    myLoans: [],
    books: []
};

// ── INICIALIZACIÓN ─────────────────────────────────────────────────────────
// Cuando la página termina de cargar, se inicia la aplicación.
document.addEventListener("DOMContentLoaded", () => { initApp(); });

// Orden de arranque: primero lo que se muestra al instante (datos guardados), luego lo que llega del servidor.
async function initApp() {
    loadUserFromStorage(); // 1. Leer usuario guardado en localStorage (inmediato, sin esperar red)
    renderUserStatus();    // 2. Mostrar badge de usuario de inmediato

    await loadBooks();     // 3. Cargar catálogo desde DB

    if (state.currentUser.active) {
        await loadMyLoans(); // 4. Cargar préstamos solo si hay sesión
    }

    renderCategoryCounts();
    renderCatalog();
    renderMyLoansCount();
    setupEventListeners();

    // 5. Verificar en segundo plano si la sesión PHP sigue activa
    verifySessionInBackground();
}

// ── SESIÓN ─────────────────────────────────────────────────────────────────

// Leer datos del usuario desde localStorage (guardados al hacer login)
function loadUserFromStorage() {
    try {
        const stored = localStorage.getItem("semsad_user");
        if (stored) {
            const user = JSON.parse(stored);
            state.currentUser.id     = user.id;
            state.currentUser.name   = user.name || "Usuario";
            state.currentUser.role   = user.role || "Estudiante";
            state.currentUser.ci     = user.ci || "";
            state.currentUser.active = true;
        }
    } catch (e) {
        console.error("Error leyendo usuario de storage:", e);
    }
}

// Verificar en segundo plano la sesión PHP sin tumbar el estado de localStorage
async function verifySessionInBackground() {
    try {
        // Pregunta al servidor si la sesión PHP sigue activa; si sí, actualiza los datos locales.
        const response = await fetch(`${API_BASE}/verificar_sesion_sirve_para_saber_si_hay_login.php`, { credentials: "include" });
        const data = await response.json();

        if (data.status === "success" && data.sesion_activa && data.usuario) {
            // Sincronizar datos del servidor con el estado local
            state.currentUser.id     = data.usuario.id_usuario;
            state.currentUser.name   = data.usuario.nombre || state.currentUser.name || "Usuario";
            state.currentUser.role   = data.usuario.rol || state.currentUser.role || "Estudiante";
            state.currentUser.ci     = data.usuario.ci || state.currentUser.ci || "";
            state.currentUser.active = true;

            localStorage.setItem("semsad_user", JSON.stringify({
                id: state.currentUser.id,
                name: state.currentUser.name,
                role: state.currentUser.role,
                ci: state.currentUser.ci
            }));
            renderUserStatus();
        }
    } catch (e) {
        console.warn("No se pudo verificar sesión PHP:", e);
    }
}

// ── CATÁLOGO ───────────────────────────────────────────────────────────────

// Descarga el catálogo de libros desde la API y lo guarda en state.books.
async function loadBooks() {
    try {
        const response = await fetch(`${API_BASE}/buscar_libros_sirve_para_buscador_catalogo.php`, { credentials: "include" });
        const data = await response.json();
        if (data.status === "success") {
            state.books = data.datos;
        }
    } catch (error) {
        console.error("Error al cargar el catálogo:", error);
    }
}

// Descarga los préstamos del usuario. Usa el endpoint del panel admin con el parámetro usuario (revisar si es el endpoint correcto).
async function loadMyLoans() {
    try {
        const response = await fetch(`${API_BASE}/listar_prestamos_sirve_para_panel_admin.php?usuario=${state.currentUser.id}`, { credentials: "include" });
        const data = await response.json();
        if (data.status === "success") {
            state.myLoans = data.datos;
        }
    } catch (error) {
        console.error("Error al cargar préstamos:", error);
    }
}

// ── UI ─────────────────────────────────────────────────────────────────────

// Dibuja en la barra la sesión: nombre, CI, rol y botón Salir. Si no hay sesión, muestra Iniciar sesión.
function renderUserStatus() {
    const userBadge = document.getElementById("userBadgeContainer");
    if (!userBadge) return;

    if (state.currentUser.active) {
        userBadge.innerHTML = `
            <div class="d-flex align-items-center gap-2">
                <div class="text-end">
                    <span class="fw-bold d-block text-white" style="font-size:0.9rem;">${escHtml(state.currentUser.name)}</span>
                    <small class="text-white-50" style="font-size:0.72rem;">CI: ${escHtml(state.currentUser.ci)} &bull; ${escHtml(state.currentUser.role)}</small>
                </div>
                <button class="btn btn-danger btn-sm px-3 py-1" id="btnLogout">
                    <i class="fa-solid fa-right-from-bracket me-1"></i>Salir
                </button>
            </div>
        `;
        // Conecta el botón Salir. Se conecta aquí porque el botón se crea con JavaScript.
        document.getElementById("btnLogout").addEventListener("click", handleLogout);
    } else {
        userBadge.innerHTML = `
            <a class="btn btn-outline-light btn-sm" href="../login/index.html">
                <i class="fa-solid fa-right-to-bracket me-1"></i>Iniciar sesión
            </a>
        `;
    }
}

// Suma las copias disponibles de cada categoría (software, telecom y db) y las escribe en las tarjetas.
function renderCategoryCounts() {
    const counts = {
        software: state.books.filter(b => b.category === "software").reduce((s, b) => s + b.stock, 0),
        telecom:  state.books.filter(b => b.category === "telecom").reduce((s, b) => s + b.stock, 0),
        db:       state.books.filter(b => b.category === "db").reduce((s, b) => s + b.stock, 0)
    };
    const el = (id) => document.getElementById(id);
    if (el("countSoftware")) el("countSoftware").textContent = `${counts.software} copias`;
    if (el("countTelecom"))  el("countTelecom").textContent  = `${counts.telecom} copias`;
    if (el("countDb"))       el("countDb").textContent       = `${counts.db} copias`;
}

// Dibuja el catálogo: primero aplica el filtro de categoría y el texto de búsqueda.
function renderCatalog() {
    const container = document.getElementById("catalogGrid");
    if (!container) return;

    // Un libro pasa el filtro si coincide con la categoría y con el texto (título, autor o ISBN).
    const filtered = state.books.filter(book => {
        const matchCat = state.activeCategory === "all" || book.category === state.activeCategory;
        const q = state.searchQuery.toLowerCase().trim();
        const matchQ = !q ||
            book.title.toLowerCase().includes(q) ||
            book.author.toLowerCase().includes(q) ||
            (book.isbn && book.isbn.toLowerCase().includes(q));
        return matchCat && matchQ;
    });

    // Sin resultados: muestra un mensaje con el botón Restablecer.
    if (filtered.length === 0) {
        container.innerHTML = `
            <div class="col-12 text-center py-5">
                <i class="fa-solid fa-book-open-reader fa-2x text-muted mb-2"></i>
                <p class="text-muted">Sin resultados. <button class="btn btn-link btn-sm" id="btnReset">Restablecer</button></p>
            </div>`;
        // Restablecer: vuelve a mostrar todo el catálogo y limpia el buscador y el selector.
        document.getElementById("btnReset")?.addEventListener("click", () => {
            state.activeCategory = "all";
            state.searchQuery = "";
            const si = document.getElementById("searchInput");
            const cs = document.getElementById("categorySelect");
            if (si) si.value = "";
            if (cs) cs.value = "all";
            renderCatalog();
        });
        return;
    }

    // Crea una tarjeta por libro. Está disponible si tiene stock mayor a 0; si no, muestra Agotado.
    container.innerHTML = filtered.map(book => {
        const avail = book.stock > 0;
        const badge = book.categoryTheme === "green" ? "badge-green" : (book.categoryTheme === "red" ? "badge-red" : "badge-blue");
        return `
            <div class="col-lg-3 col-md-4 col-sm-6">
                <div class="book-card-flat">
                    <div class="book-cover-container">
                        <img src="${escHtml(book.coverImg)}" alt="${escHtml(book.title)}"
                             onerror="this.src='https://placehold.co/300x400/1e293b/ffffff?text=${encodeURIComponent(book.title).replace(/'/g, "%27")}'">
                    </div>
                    <div class="book-info-body">
                        <div class="mb-1">
                            <small class="text-muted">${escHtml(book.classification || '000.0')}</small>
                            <span class="badge ${badge} float-end" style="font-size:0.68rem;">${escHtml(book.categoryLabel || 'General')}</span>
                        </div>
                        <h6 class="book-title-flat">${escHtml(book.title)}</h6>
                        <p class="book-author-flat"><i class="fa-solid fa-pen-nib me-1"></i>${escHtml(book.author)}</p>
                        <div class="mt-auto">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="${avail ? 'stock-indicator stock-available' : 'stock-indicator stock-out'}">
                                    <i class="fa-solid ${avail ? 'fa-check' : 'fa-xmark'}"></i>
                                    ${avail ? `Disp: ${escHtml(book.stock)}/${escHtml(book.total)}` : 'Sin stock'}
                                </span>
                                <small class="text-muted" style="font-size:0.72rem;">${escHtml(book.shelf)}</small>
                            </div>
                            <button class="btn ${avail ? 'btn-flat-blue' : 'btn-flat-neutral'} btn-sm w-100"
                                onclick="openReserveModal(${Number(book.id)})" ${!avail ? 'disabled' : ''}>
                                <i class="fa-solid ${avail ? 'fa-bookmark' : 'fa-clock'} me-1"></i>
                                ${avail ? 'Reservar' : 'Agotado'}
                            </button>
                        </div>
                    </div>
                </div>
            </div>`;
    }).join("");
}

// Escribe la cantidad de préstamos en el indicador del menú.
function renderMyLoansCount() {
    const badge = document.getElementById("navLoansBadge");
    if (badge) badge.textContent = state.myLoans.length;
}

// Dibuja la lista de préstamos dentro del modal: vencidos en rojo y activos en azul.
function renderMyLoansList() {
    const container = document.getElementById("loansListContainer");
    if (!container) return;
    if (state.myLoans.length === 0) {
        container.innerHTML = `<div class="text-center py-4"><i class="fa-solid fa-folder-open fa-2x text-muted mb-2"></i><p class="text-muted small">No tienes préstamos activos.</p></div>`;
        return;
    }
    container.innerHTML = state.myLoans.map(loan => `
        <div class="card mb-2 p-3 border-start border-4 border-${loan.status === 'Vencido' ? 'danger' : 'primary'}">
            <div class="d-flex justify-content-between">
                <div>
                    <span class="badge bg-secondary mb-1">${escHtml(loan.code)}</span>
                    <h6 class="fw-bold mb-1">${escHtml(loan.bookTitle)}</h6>
                    <small class="text-muted">Solicitado: ${escHtml(loan.issueDate)} &bull; Vence: <strong>${escHtml(loan.dueDate)}</strong></small>
                </div>
                <span class="badge bg-${loan.status === 'Vencido' ? 'danger' : 'success'} align-self-center">
                    ${loan.status === 'Vencido' ? `${escHtml(loan.overdueDays)}d mora` : `${escHtml(loan.daysLeft)}d restantes`}
                </span>
            </div>
        </div>`).join("");
}

// ── LISTENERS ──────────────────────────────────────────────────────────────

// Conecta los controles de la página (buscador, categorías y enlace de préstamos) con sus funciones.
function setupEventListeners() {
    // Buscador: cada vez que se escribe, guarda el texto y vuelve a dibujar el catálogo.
    document.getElementById("searchInput")?.addEventListener("input", e => {
        state.searchQuery = e.target.value;
        renderCatalog();
    });

    // Selector de categoría: al cambiarlo, vuelve a filtrar el catálogo.
    document.getElementById("categorySelect")?.addEventListener("change", e => {
        state.activeCategory = e.target.value;
        renderCatalog();
    });

    // Tarjetas de categoría: al hacer clic filtran el catálogo; otro clic sobre la misma categoría la quita.
    document.querySelectorAll("[data-category-filter]").forEach(card => {
        card.addEventListener("click", function () {
            const cat = this.dataset.categoryFilter;
            state.activeCategory = (state.activeCategory === cat) ? "all" : cat;
            const cs = document.getElementById("categorySelect");
            if (cs) cs.value = state.activeCategory;
            renderCatalog();
            document.getElementById("catalogoSection")?.scrollIntoView({ behavior: "smooth" });
        });
    });

    // Enlace Mis préstamos: dibuja la lista y abre el modal de Bootstrap.
    document.getElementById("navLoansLink")?.addEventListener("click", e => {
        e.preventDefault();
        renderMyLoansList();
        const el = document.getElementById("loansModal");
        if (el) new bootstrap.Modal(el).show();
    });
}

// ── RESERVA ────────────────────────────────────────────────────────────────

// Abre el modal de reserva con los datos del libro. Si no hay sesión, avisa y manda al login.
window.openReserveModal = function (bookId) {
    if (!state.currentUser.active) {
        alert("Debes iniciar sesión para reservar libros.");
        window.location.href = "../login/index.html";
        return;
    }

    const book = state.books.find(b => b.id == bookId);
    // Si el libro no existe o no tiene copias, no hace nada.
    if (!book || book.stock <= 0) return;

    const modalBody = document.getElementById("reserveModalBody");
    if (!modalBody) return;

    // Llena el modal con la portada, el autor y una tabla con categoría, ubicación, copias y plazo (7 días).
    modalBody.innerHTML = `
        <div class="row g-3">
            <div class="col-4 text-center">
                <img src="${escHtml(book.coverImg)}" class="img-fluid rounded border" alt="${escHtml(book.title)}"
                     onerror="this.src='https://placehold.co/200x280/1e293b/fff?text=${encodeURIComponent(book.title).replace(/'/g, "%27")}'">
            </div>
            <div class="col-8">
                <h6 class="fw-bold">${escHtml(book.title)}</h6>
                <p class="text-muted small mb-3">Por: ${escHtml(book.author)}</p>
                <table class="table table-sm table-bordered small">
                    <tr><th>Categoría</th><td>${escHtml(book.categoryLabel)}</td></tr>
                    <tr><th>Ubicación</th><td>${escHtml(book.shelf)}</td></tr>
                    <tr><th>Disponibles</th><td>${escHtml(book.stock)} de ${escHtml(book.total)} copias</td></tr>
                    <tr><th>Plazo</th><td>7 días calendario</td></tr>
                </table>
            </div>
        </div>`;

    // Al hacer clic en Confirmar se reserva este libro.
    document.getElementById("btnConfirmReserve").onclick = () => confirmReservation(book);
    new bootstrap.Modal(document.getElementById("reserveModal")).show();
};

// Envía la reserva (POST) con el id del usuario, el id del libro y 7 días de préstamo.
async function confirmReservation(book) {
    try {
        const response = await fetch(`${API_BASE}/registrar_prestamo_sirve_para_pedir_libro_estudiante.php`, {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id_usuario: state.currentUser.id, id_material: book.id, dias_prestamo: 7 })
        });
        const data = await response.json();

        // Si se reservó: cierra el modal, avisa y vuelve a cargar libros, préstamos y contadores.
        if (data.status === "success") {
            bootstrap.Modal.getInstance(document.getElementById("reserveModal"))?.hide();
            showToast(`Reserva confirmada: "${book.title}"`, "success");
            await loadBooks();
            await loadMyLoans();
            renderCategoryCounts();
            renderCatalog();
            renderMyLoansCount();
        } else {
            showToast(data.mensaje || "Error al reservar.", "danger");
        }
    } catch (err) {
        showToast("Error de conexión al registrar reserva.", "danger");
    }
}

// ── LOGOUT ─────────────────────────────────────────────────────────────────

// Cierra la sesión tras confirmar: borra el usuario del navegador, avisa al servidor y regresa al login.
async function handleLogout() {
    if (!confirm("¿Deseas cerrar tu sesión?")) return;
    localStorage.removeItem("semsad_user");
    try { await fetch(`${API_BASE}/logout_sirve_para_cerrar_sesion.php`, { credentials: "include" }); } catch (e) {}
    window.location.href = "../login/index.html";
}

// ── TOAST ──────────────────────────────────────────────────────────────────

// Muestra un aviso de Bootstrap; el color cambia según el tipo (éxito, error o normal).
function showToast(message, type = "info") {
    const toastEl = document.getElementById("liveToast");
    const toastBody = document.getElementById("toastMessageBody");
    if (!toastEl || !toastBody) return;
    toastBody.textContent = message;
    toastEl.className = `toast align-items-center text-white border-0 bg-${type === "success" ? "success" : type === "danger" ? "danger" : "primary"}`;
    new bootstrap.Toast(toastEl, { delay: 4000 }).show();
}
