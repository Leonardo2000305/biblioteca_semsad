/* ==========================================================================
   BIBLIOTECA SEMSAD - CONFIGURACIÓN COMÚN DEL FRONTEND
   Se carga ANTES del script de cada página:  <script src="../js/config.js"></script>
   ========================================================================== */

// Todas las llamadas al backend pasan por /backend/..., que Vercel reenvía a AlwaysData
// (ver "rewrites" en vercel.json). Así el navegador siempre habla con el mismo dominio:
// sin problemas de CORS y con cookies de sesión normales.
window.SEMSAD_API = window.SEMSAD_API || '/backend';

window.SEMSAD = {
    ROLES_PERSONAL: ['Administrador', 'Bibliotecario'],

    // Escapa texto antes de ponerlo dentro de innerHTML (evita que un nombre como <img onerror=...> ejecute código).
    esc(valor) {
        return String(valor == null ? '' : valor).replace(/[&<>"']/g, c => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));
    },

    // Aviso emergente (toast). tipo: 'success' (verde) | 'error' (rojo) | 'warning' (amarillo) | 'info' (azul)
    toast(mensaje, tipo = 'info', duracionMs = 5000) {
        if (!document.getElementById('semsad-toast-css')) {
            const css = document.createElement('style');
            css.id = 'semsad-toast-css';
            css.textContent = `
                #semsad-toasts{position:fixed;top:16px;right:16px;left:16px;z-index:99999;display:flex;flex-direction:column;align-items:flex-end;gap:10px;pointer-events:none}
                .semsad-toast{pointer-events:auto;max-width:420px;width:100%;padding:12px 16px;border-radius:10px;font:600 14px/1.4 system-ui,Segoe UI,Arial,sans-serif;
                    box-shadow:0 8px 24px rgba(0,0,0,.18);border:1px solid;cursor:pointer;opacity:0;transform:translateY(-8px);transition:opacity .25s,transform .25s;word-break:break-word}
                .semsad-toast.visible{opacity:1;transform:none}
                .semsad-toast.success{background:#dcfce7;color:#166534;border-color:#4ade80}
                .semsad-toast.error{background:#fee2e2;color:#991b1b;border-color:#f87171}
                .semsad-toast.warning{background:#fef9c3;color:#854d0e;border-color:#facc15}
                .semsad-toast.info{background:#dbeafe;color:#1e40af;border-color:#60a5fa}`;
            document.head.appendChild(css);
        }
        let caja = document.getElementById('semsad-toasts');
        if (!caja) {
            caja = document.createElement('div');
            caja.id = 'semsad-toasts';
            caja.setAttribute('aria-live', 'polite');
            document.body.appendChild(caja);
        }
        const t = document.createElement('div');
        t.className = 'semsad-toast ' + tipo;
        t.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
        t.textContent = mensaje;                       // textContent: nunca interpreta HTML
        const cerrar = () => { t.classList.remove('visible'); setTimeout(() => t.remove(), 300); };
        t.addEventListener('click', cerrar);
        caja.appendChild(t);
        requestAnimationFrame(() => t.classList.add('visible'));
        setTimeout(cerrar, duracionMs);
    },

    getUser() {
        try { return JSON.parse(localStorage.getItem('semsad_user')) || null; }
        catch (e) { return null; }
    },

    setUser(u) {
        localStorage.setItem('semsad_user', JSON.stringify(u));
    },

    esPersonal(rol) {
        return this.ROLES_PERSONAL.includes(rol);
    },

    destinoSegunRol(rol) {
        return this.esPersonal(rol) ? '/principal/admin.html' : '/principal/index.html';
    },

    // Guarda de página (solo visual: la seguridad real la aplican las APIs con la sesión PHP).
    exigirSesion(soloPersonal) {
        const u = this.getUser();
        if (!u) { window.location.replace('/login/index.html'); return null; }
        if (soloPersonal && !this.esPersonal(u.role)) { window.location.replace('/principal/index.html'); return null; }
        return u;
    },

    async cerrarSesion(evento) {
        if (evento) evento.preventDefault();
        localStorage.removeItem('semsad_user');
        try { await fetch(window.SEMSAD_API + '/logout.php', { credentials: 'include' }); } catch (e) { /* sin red: igual salimos */ }
        window.location.href = '/login/index.html';
    }
};
