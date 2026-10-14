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
