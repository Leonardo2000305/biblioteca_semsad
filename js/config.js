window.SEMSAD_API = 'https://bibliosemsad.alwaysdata.net/api';

window.SEMSAD = window.SEMSAD || {
    setUser: function(user) {
        localStorage.setItem('semsad_user', JSON.stringify(user));
    },
    getUser: function() {
        return JSON.parse(localStorage.getItem('semsad_user') || 'null');
    },
    logout: function() {
        localStorage.removeItem('semsad_user');
        window.location.href = '../login/index.html';
    },
    destinoSegunRol: function(rol) {
        const r = (rol || '').toLowerCase();
        if (r === 'admin' || r === 'bibliotecario') {
            return '../admin.html';
        }
        return '../index.html';
    }
};
