<section class="login-stage">
    <div class="login-signal" aria-hidden="true">
        <span></span>
        <span></span>
        <span></span>
    </div>

    <div class="setup-panel">
        <div class="login-copy">
            <div class="login-mark">zc.</div>
            <h1>Instalacao concluida</h1>
            <p>O administrador inicial foi criado e a sessao ja esta ativa.</p>
        </div>

        <div class="login-card glass-card">
            <div class="text-center mb-4">
                <h2 class="fw-bold mb-1">Tudo pronto</h2>
                <p class="text-muted mb-0" style="font-size:0.9rem;">Guarde as informacoes abaixo antes de continuar.</p>
            </div>

            <?php if (!empty($issuedToken)): ?>
                <div class="inner-card mb-4">
                    <div class="text-muted fw-bold text-uppercase mb-2" style="font-size:0.75rem; letter-spacing:1px;">Token de API inicial</div>
                    <code class="d-block bg-white rounded-4 p-3 text-break"><?= htmlspecialchars($issuedToken['token']) ?></code>
                    <div class="text-muted mt-2" style="font-size:0.85rem;">Este token aparece somente agora.</div>
                </div>
            <?php endif; ?>

            <a href="/dashboard" class="pill-btn btn-black w-100 py-3 shadow-sm">
                Abrir painel <i class="fas fa-arrow-right ms-2"></i>
            </a>
        </div>
    </div>
</section>
