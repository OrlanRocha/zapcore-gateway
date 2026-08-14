<?php
$old = $old ?? [];
$value = static fn(string $key, string $default = '') => htmlspecialchars((string) ($old[$key] ?? $default));
?>

<section class="login-stage">
    <div class="login-signal" aria-hidden="true">
        <span></span>
        <span></span>
        <span></span>
    </div>

    <div class="setup-panel">
        <div class="login-copy">
            <div class="login-mark">zc.</div>
            <h1>Primeira instalacao</h1>
            <p>Crie o administrador inicial para liberar o painel do ZapCore Gateway.</p>
        </div>

        <div class="login-card glass-card">
            <div class="text-center mb-4">
                <h2 class="fw-bold mb-1">Criar administrador</h2>
                <p class="text-muted mb-0" style="font-size:0.9rem;">Este passo aparece apenas quando nao existe usuario cadastrado.</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger rounded-4 border-0 shadow-sm" style="font-size:0.9rem;">
                    <?php foreach ($errors as $error): ?>
                        <div><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="/setup" class="login-form">
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Nome</label>
                    <input type="text" name="name" value="<?= $value('name', 'Admin ZapCore') ?>" class="form-control rounded-pill border-0 shadow-sm px-4 py-3" required>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">E-mail</label>
                    <input type="email" name="email" value="<?= $value('email', 'admin@zapcore.local') ?>" class="form-control rounded-pill border-0 shadow-sm px-4 py-3" required autocomplete="email">
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Senha</label>
                    <input type="password" name="password" class="form-control rounded-pill border-0 shadow-sm px-4 py-3" required autocomplete="new-password">
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Confirmar senha</label>
                    <input type="password" name="password_confirm" class="form-control rounded-pill border-0 shadow-sm px-4 py-3" required autocomplete="new-password">
                </div>
                <label class="d-flex align-items-center gap-2 bg-white rounded-pill px-4 py-2 shadow-sm mb-4">
                    <input type="checkbox" name="create_token" value="1" <?= !array_key_exists('create_token', $old) || !empty($old['create_token']) ? 'checked' : '' ?>>
                    <span>Criar token de API inicial</span>
                </label>
                <button type="submit" class="pill-btn btn-black w-100 py-3 shadow-sm">
                    Finalizar instalacao <i class="fas fa-arrow-right ms-2"></i>
                </button>
            </form>
        </div>
    </div>
</section>
