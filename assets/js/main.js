// ============================================================
// assets/js/main.js — JS Global do Sistema
// Parte 11: Sistema de notificações com polling e toasts
// ============================================================

// ── Relógio do header ────────────────────────────────────────────
(function () {
    function atualizarRelogio() {
        var el = document.getElementById('relogio');
        if (!el) return;
        var agora = new Date();
        var h = String(agora.getHours()).padStart(2, '0');
        var m = String(agora.getMinutes()).padStart(2, '0');
        var s = String(agora.getSeconds()).padStart(2, '0');
        el.textContent = h + ':' + m + ':' + s;
    }
    atualizarRelogio();
    setInterval(atualizarRelogio, 1000);
})();

// ── Modais ────────────────────────────────────────────────────────
function abrirModal(id) {
    var m = document.getElementById(id);
    if (m) {
        m.style.display = 'flex';
        var primeiro = m.querySelector('input:not([type=hidden]), select, textarea');
        if (primeiro) setTimeout(function () { primeiro.focus(); }, 80);
    }
}
function fecharModal(id) {
    var m = document.getElementById(id);
    if (m) m.style.display = 'none';
}

// ESC fecha apenas modais que NÃO são persistentes
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(function (m) {
            if (m.dataset.persistent === 'true') return;
            m.style.display = 'none';
        });
    }
});

// Clique no overlay fecha apenas modais que NÃO são persistentes
document.addEventListener('click', function (e) {
    if (e.target.classList.contains('modal')) {
        if (e.target.dataset.persistent === 'true') return;
        e.target.style.display = 'none';
    }
});

// ── Mostrar/ocultar senha ─────────────────────────────────────────
function toggleSenha(id) {
    var input = document.getElementById(id);
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
}

// ── Troca de logo (modal) ─────────────────────────────────────────
function abrirTrocarLogo() {
    abrirModal('modal-logo');
}

// ================================================================
// SISTEMA DE NOTIFICAÇÕES — PARTE 11
// ================================================================

var Notificacoes = (function () {

    // Configuração
    var INTERVALO_POLLING = 30000;  // 30 segundos
    var DURACAO_TOAST     = 7000;   // 7 segundos visível
    var BASE              = (typeof BASE_URL !== 'undefined') ? BASE_URL : '';

    var ultimoTs      = Math.floor(Date.now() / 1000);
    var ultimoTotal   = -1;  // -1 = primeira checagem
    var timerPolling  = null;
    var toastContainer = null;
    var dotEl          = null;
    var tituloOriginal = document.title;

    // ── Inicia o sistema ─────────────────────────────────────────
    function iniciar() {
        if (!BASE) return;  // sem BASE_URL não funciona

        // Cria container de toasts
        toastContainer = document.createElement('div');
        toastContainer.className = 'toast-container';
        document.body.appendChild(toastContainer);

        // Cria indicador de status
        var statusEl = document.createElement('div');
        statusEl.className = 'notif-status';
        statusEl.innerHTML = '<span class="notif-dot" id="notif-dot"></span> monitorando';
        document.body.appendChild(statusEl);
        dotEl = document.getElementById('notif-dot');

        // Solicita permissão para notificações do browser
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }

        // Primeira checagem imediata após 3s (página acabou de carregar)
        setTimeout(checar, 3000);
        timerPolling = setInterval(checar, INTERVALO_POLLING);
    }

    // ── Checa a API de alertas ───────────────────────────────────
    function checar() {
        if (dotEl) dotEl.className = 'notif-dot ativo';

        fetch(BASE + '/api/alertas.php?ts=' + ultimoTs, {
            credentials: 'same-origin',
            cache: 'no-store'
        })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (dados) {
            if (dotEl) dotEl.className = 'notif-dot ativo';

            // Atualiza badge na aba do browser
            atualizarTitulo(dados.total || 0);

            // Atualiza badge na navbar
            atualizarBadgeNav(dados.total || 0);

            // Mostra toasts apenas se não for a primeira checagem
            // e se houver novidades
            if (ultimoTotal >= 0 && dados.toasts && dados.toasts.length > 0) {
                dados.toasts.forEach(function (t) {
                    mostrarToast(t.msg, t.tipo || 'aviso');
                });
            }

            // Alerta sonoro se total aumentou
            if (ultimoTotal >= 0 && (dados.total || 0) > ultimoTotal) {
                tocarSom();
            }

            ultimoTotal = dados.total || 0;
            ultimoTs    = dados.ts    || Math.floor(Date.now() / 1000);
        })
        .catch(function () {
            if (dotEl) dotEl.className = 'notif-dot erro';
        });
    }

    // ── Atualiza o título da aba com badge numérico ──────────────
    function atualizarTitulo(total) {
        document.title = total > 0
            ? '(' + total + ') ' + tituloOriginal
            : tituloOriginal;
    }

    // ── Atualiza badge no link "Início" da navbar ────────────────
    function atualizarBadgeNav(total) {
        var linkInicio = document.querySelector('.site-nav a:first-child');
        if (!linkInicio) return;

        var badge = linkInicio.querySelector('.nav-badge-alerta');
        if (total > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'nav-badge-alerta';
                linkInicio.appendChild(badge);
            }
            badge.textContent = total > 99 ? '99+' : total;
        } else {
            if (badge) badge.remove();
        }
    }

    // ── Exibe um toast ───────────────────────────────────────────
    function mostrarToast(mensagem, tipo, duracao) {
        if (!toastContainer) return;
        duracao = duracao || DURACAO_TOAST;

        var icones = { erro: '🚨', aviso: '⚠️', info: 'ℹ️', sucesso: '✅' };
        var icone  = icones[tipo] || '🔔';

        var toast = document.createElement('div');
        toast.className = 'toast toast-' + (tipo || 'aviso');
        toast.innerHTML =
            '<span class="toast-icone">' + icone + '</span>' +
            '<span class="toast-corpo">' + mensagem + '</span>' +
            '<button class="toast-fechar" title="Fechar">✕</button>';

        toastContainer.appendChild(toast);

        // Fecha ao clicar
        toast.addEventListener('click', function () { removerToast(toast); });

        // Remove automaticamente
        var timer = setTimeout(function () { removerToast(toast); }, duracao);

        // Cancela o timer se o usuário fechar manualmente
        toast.querySelector('.toast-fechar').addEventListener('click', function (e) {
            e.stopPropagation();
            clearTimeout(timer);
            removerToast(toast);
        });

        // Notificação nativa do browser (se permitida)
        if ('Notification' in window && Notification.permission === 'granted') {
            try {
                new Notification(document.title, {
                    body: mensagem.replace(/[*_~`]/g, ''),
                    icon: BASE + '/assets/img/logo.png',
                    tag:  'sistema-csr-alerta',
                });
            } catch (e) { /* ignora */ }
        }
    }

    function removerToast(toast) {
        toast.classList.add('saindo');
        setTimeout(function () {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 300);
    }

    // ── Som de notificação (Web Audio API) ───────────────────────
    function tocarSom() {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            osc.frequency.setValueAtTime(660, ctx.currentTime + 0.1);
            gain.gain.setValueAtTime(0.15, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + 0.4);
        } catch (e) { /* sem suporte a AudioContext */ }
    }

    // ── API pública ──────────────────────────────────────────────
    return {
        iniciar:      iniciar,
        mostrarToast: mostrarToast,
        checar:       checar,
    };

})();

// Inicia o sistema de notificações em todas as páginas internas
// (só funciona se BASE_URL estiver definida no PHP da página)
document.addEventListener('DOMContentLoaded', function () {
    if (typeof BASE_URL !== 'undefined' && BASE_URL) {
        Notificacoes.iniciar();
    }
});