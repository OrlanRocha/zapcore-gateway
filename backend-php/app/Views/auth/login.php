<section class="login-stage">
    <div class="login-signal" aria-hidden="true">
        <span></span>
        <span></span>
        <span></span>
    </div>

    <div class="login-panel">
        <div class="login-copy">
            <div class="login-mark">zc.</div>
            <h1>ZapCore Gateway</h1>
            <p>Gerencie instancias, filas e webhooks em um painel direto e seguro.</p>
            <div class="login-status-strip" aria-label="Status do sistema">
                <span><i class="fas fa-shield-halved"></i> Sessao segura</span>
                <span><i class="fas fa-bolt"></i> Worker Baileys</span>
                <span><i class="fas fa-database"></i> MySQL</span>
            </div>
        </div>

        <div class="login-card glass-card">
            <div class="text-center mb-4">
                <h2 class="fw-bold mb-1">Entrar</h2>
                <p class="text-muted mb-0" style="font-size:0.9rem;">Acesse sua conta administrativa</p>
            </div>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger rounded-4 border-0 shadow-sm text-center" style="font-size:0.9rem;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="/login" class="login-form">
                <div class="mb-3">
                    <label for="email" class="form-label text-muted fw-bold" style="font-size:0.85rem;">E-mail</label>
                    <input type="email" class="form-control rounded-pill border-0 shadow-sm px-4 py-3" id="email" name="email" required placeholder="admin@zapcore.local" autocomplete="email">
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label text-muted fw-bold" style="font-size:0.85rem;">Senha</label>
                    <input type="password" class="form-control rounded-pill border-0 shadow-sm px-4 py-3" id="password" name="password" required placeholder="********" autocomplete="current-password">
                </div>
                <button type="submit" class="pill-btn btn-black w-100 py-3 mt-2 shadow-sm">
                    Entrar <i class="fas fa-arrow-right ms-2"></i>
                </button>
            </form>
        </div>
    </div>
</section>
