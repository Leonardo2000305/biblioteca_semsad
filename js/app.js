/* ==========================================================================
   BIBLIOTECA SEMSAD - LÓGICA PORTAL ESTUDIANTES (APP.JS)
   Conectado al Backend PHP y MySQL - Versión estable
   ========================================================================== */

const API_BASE = "../api";

const state = {
    currentUser: { id: null, ci: null, name: null, role: null, active: false },
    activeCategory: "all",
    searchQuery: "",
    myLoans: [],
    books: []
};

// ── INICIALIZACIÓN ─────────────────────────────────────────────────────────
document.addEventListener("DOMContentLoaded", () => { initApp(); });

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

// Verificar en segundo plano si la sesión PHP sigue viva
async function verifySessionInBackground() {
    try {
        const response = await fetch(`${API_BASE}/verificar_sesion.php`, { credentials: "include" });
        const data = await response.json();

        if (!data.sesion_activa) {
            // Sesión expiró en el servidor
            localStorage.removeItem("semsad_user");
            state.currentUser.active = false;
            renderUserStatus();
        }
    } catch (e) {
        // Error de red: no cerrar sesión, podría ser temporal
        console.warn("No se pudo verificar sesión PHP:", e);
    }
}

// ── CATÁLOGO ───────────────────────────────────────────────────────────────

async function loadBooks() {
    try {
        const response = await fetch(`${API_BASE}/buscar_libros.php`, { credentials: "include" });
        const data = await response.json();
        if (data.status === "success") {
            state.books = data.datos;
        }
    } catch (error) {
        console.error("Error al cargar el catálogo:", error);
    }
}

async function loadMyLoans() {
    try {
        const response = await fetch(`${API_BASE}/listar_prestamos.php?usuario=${state.currentUser.id}`, { credentials: "include" });
        const data = await response.json();
        if (data.status === "success") {
            state.myLoans = data.datos;
        }
    } catch (error) {
        console.error("Error al cargar préstamos:", error);
    }
}

// ── UI ─────────────────────────────────────────────────────────────────────

function renderUserStatus() {
    const userBadge = document.getElementById("userBadgeContainer");
    if (!userBadge) return;

    if (state.currentUser.active) {
        userBadge.innerHTML = `
            <div class="d-flex align-items-center gap-2">
                <div class="text-end">
                    <span class="fw-bold d-block text-white" style="font-size:0.9rem;">${state.currentUser.name}</span>
                    <small class="text-white-50" style="font-size:0.72rem;">CI: ${state.currentUser.ci} &bull; ${state.currentUser.role}</small>
                </div>
                <button class="btn btn-danger btn-sm px-3 py-1" id="btnLogout">
                    <i class="fa-solid fa-right-from-bracket me-1"></i>Salir
                </button>
            </div>
        `;
        document.getElementById("btnLogout").addEventListener("click", handleLogout);
    } else {
        userBadge.innerHTML = `
            <a class="btn btn-outline-light btn-sm" href="../login/index.html">
                <i class="fa-solid fa-right-to-bracket me-1"></i>Iniciar sesión
            </a>
        `;
    }
}

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

function renderCatalog() {
    const container = document.getElementById("catalogGrid");
    if (!container) return;

    const filtered = state.books.filter(book => {
        const matchCat = state.activeCategory === "all" || book.category === state.activeCategory;
        const q = state.searchQuery.toLowerCase().trim();
        const matchQ = !q ||
            book.title.toLowerCase().includes(q) ||
            book.author.toLowerCase().includes(q) ||
            (book.isbn && book.isbn.toLowerCase().includes(q));
        return matchCat && matchQ;
    });

    if (filtered.length === 0) {
        container.innerHTML = `
            <div class="col-12 text-center py-5">
                <i class="fa-solid fa-book-open-reader fa-2x text-muted mb-2"></i>
                <p class="text-muted">Sin resultados. <button class="btn btn-link btn-sm" id="btnReset">Restablecer</button></p>
            </div>`;
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

    container.innerHTML = filtered.map(book => {
        const avail = book.stock > 0;
        const badge = book.categoryTheme === "green" ? "badge-green" : (book.categoryTheme === "red" ? "badge-red" : "badge-blue");
        return `
            <div class="col-lg-3 col-md-4 col-sm-6">
                <div class="book-card-flat">
                    <div class="book-cover-container">
                        <img src="${book.coverImg}" alt="${book.title}"
                             onerror="this.src='https://placehold.co/300x400/1e293b/ffffff?text=${encodeURIComponent(book.title)}'">
                    </div>
                    <div class="book-info-body">
                        <div class="mb-1">
                            <small class="text-muted">${book.classification || '000.0'}</small>
                            <span class="badge ${badge} float-end" style="font-size:0.68rem;">${book.categoryLabel || 'General'}</span>
                        </div>
                        <h6 class="book-title-flat">${book.title}</h6>
                        <p class="book-author-flat"><i class="fa-solid fa-pen-nib me-1"></i>${book.author}</p>
                        <div class="mt-auto">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="${avail ? 'stock-indicator stock-available' : 'stock-indicator stock-out'}">
                                    <i class="fa-solid ${avail ? 'fa-check' : 'fa-xmark'}"></i>
                                    ${avail ? `Disp: ${book.stock}/${book.total}` : 'Sin stock'}
                                </span>
                                <small class="text-muted" style="font-size:0.72rem;">${book.shelf}</small>
                            </div>
                            <button class="btn ${avail ? 'btn-flat-blue' : 'btn-flat-neutral'} btn-sm w-100"
                                onclick="openReserveModal(${book.id})" ${!avail ? 'disabled' : ''}>
                                <i class="fa-solid ${avail ? 'fa-bookmark' : 'fa-clock'} me-1"></i>
                                ${avail ? 'Reservar' : 'Agotado'}
                            </button>
                        </div>
                    </div>
                </div>
            </div>`;
    }).join("");
}

function renderMyLoansCount() {
    const badge = document.getElementById("navLoansBadge");
    if (badge) badge.textContent = state.myLoans.length;
}

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
                    <span class="badge bg-secondary mb-1">${loan.code}</span>
                    <h6 class="fw-bold mb-1">${loan.bookTitle}</h6>
                    <small class="text-muted">Solicitado: ${loan.issueDate} &bull; Vence: <strong>${loan.dueDate}</strong></small>
                </div>
                <span class="badge bg-${loan.status === 'Vencido' ? 'danger' : 'success'} align-self-center">
                    ${loan.status === 'Vencido' ? `${loan.overdueDays}d mora` : `${loan.daysLeft}d restantes`}
                </span>
            </div>
        </div>`).join("");
}

// ── LISTENERS ──────────────────────────────────────────────────────────────

function setupEventListeners() {
    document.getElementById("searchInput")?.addEventListener("input", e => {
        state.searchQuery = e.target.value;
        renderCatalog();
    });

    document.getElementById("categorySelect")?.addEventListener("change", e => {
        state.activeCategory = e.target.value;
        renderCatalog();
    });

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

    document.getElementById("navLoansLink")?.addEventListener("click", e => {
        e.preventDefault();
        renderMyLoansList();
        const el = document.getElementById("loansModal");
        if (el) new bootstrap.Modal(el).show();
    });
}

// ── RESERVA ────────────────────────────────────────────────────────────────

window.openReserveModal = function (bookId) {
    if (!state.currentUser.active) {
        alert("Debes iniciar sesión para reservar libros.");
        window.location.href = "../login/index.html";
        return;
    }

    const book = state.books.find(b => b.id == bookId);
    if (!book || book.stock <= 0) return;

    const modalBody = document.getElementById("reserveModalBody");
    if (!modalBody) return;

    modalBody.innerHTML = `
        <div class="row g-3">
            <div class="col-4 text-center">
                <img src="${book.coverImg}" class="img-fluid rounded border" alt="${book.title}"
                     onerror="this.src='https://placehold.co/200x280/1e293b/fff?text=${encodeURIComponent(book.title)}'">
            </div>
            <div class="col-8">
                <h6 class="fw-bold">${book.title}</h6>
                <p class="text-muted small mb-3">Por: ${book.author}</p>
                <table class="table table-sm table-bordered small">
                    <tr><th>Categoría</th><td>${book.categoryLabel}</td></tr>
                    <tr><th>Ubicación</th><td>${book.shelf}</td></tr>
                    <tr><th>Disponibles</th><td>${book.stock} de ${book.total} copias</td></tr>
                    <tr><th>Plazo</th><td>7 días calendario</td></tr>
                </table>
            </div>
        </div>`;

    document.getElementById("btnConfirmReserve").onclick = () => confirmReservation(book);
    new bootstrap.Modal(document.getElementById("reserveModal")).show();
};

async function confirmReservation(book) {
    try {
        const response = await fetch(`${API_BASE}/registrar_prestamo.php`, {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id_usuario: state.currentUser.id, id_material: book.id, dias_prestamo: 7 })
        });
        const data = await response.json();

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

async function handleLogout() {
    if (!confirm("¿Deseas cerrar tu sesión?")) return;
    localStorage.removeItem("semsad_user");
    try { await fetch(`${API_BASE}/logout.php`, { credentials: "include" }); } catch (e) {}
    window.location.href = "../login/index.html";
}

// ── TOAST ──────────────────────────────────────────────────────────────────

function showToast(message, type = "info") {
    const toastEl = document.getElementById("liveToast");
    const toastBody = document.getElementById("toastMessageBody");
    if (!toastEl || !toastBody) return;
    toastBody.textContent = message;
    toastEl.className = `toast align-items-center text-white border-0 bg-${type === "success" ? "success" : type === "danger" ? "danger" : "primary"}`;
    new bootstrap.Toast(toastEl, { delay: 4000 }).show();
}
