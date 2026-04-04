// ================================================================
// CONTROLE DE CARGA — JavaScript Principal
// ================================================================

// ── Relógio ao vivo ──────────────────────────────────────────────
function atualizarRelogio() {
    const el = document.getElementById('relogio');
    if (!el) return;

    const agora = new Date();
    const h = String(agora.getHours()).padStart(2, '0');
    const m = String(agora.getMinutes()).padStart(2, '0');
    const s = String(agora.getSeconds()).padStart(2, '0');
    el.textContent = `${h}:${m}:${s}`;
}

setInterval(atualizarRelogio, 1000);
atualizarRelogio(); // Executa imediatamente ao carregar

// ── Controle de modais ───────────────────────────────────────────
function abrirModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.style.display = 'flex';
        // Foca no primeiro campo do modal
        setTimeout(() => {
            const primeiro = modal.querySelector('input, select, textarea');
            if (primeiro) primeiro.focus();
        }, 100);
    }
}

function fecharModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.style.display = 'none';
}

// Fecha modal ao clicar fora
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.style.display = 'none';
    }
});

// Atalho: ESC fecha qualquer modal aberto
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(m => {
            m.style.display = 'none';
        });
    }
});

// ── Troca de logo ────────────────────────────────────────────────
function abrirTrocarLogo() {
    abrirModal('modal-logo');
}

// ── Alerta de confirmação customizado ────────────────────────────
// Uso: confirmarAcao('Tem certeza?', function() { ... });
function confirmarAcao(mensagem, callback) {
    if (confirm(mensagem)) callback();
}

// ── Exibe/oculta senha ───────────────────────────────────────────
function toggleSenha(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
}