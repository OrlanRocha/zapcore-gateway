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
            <h1>Primeiro acesso</h1>
            <p>Antes de abrir o painel, troque a senha temporaria e confirme seus dados.</p>
        </div>

        <div class="login-card glass-card">
            <div class="text-center mb-4">
                <h2 class="fw-bold mb-1">Atualizar acesso</h2>
                <p class="text-muted mb-0" style="font-size:0.9rem;">Use uma senha propria para substituir o acesso padrao da instalacao.</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger rounded-4 border-0 shadow-sm" style="font-size:0.9rem;">
                    <?php foreach ($errors as $error): ?>
                        <div><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="/first-login" class="login-form">
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Nome</label>
                    <input type="text" name="name" value="<?= $value('name') ?>" class="form-control rounded-pill border-0 shadow-sm px-4 py-3" required>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">E-mail</label>
                    <input type="email" name="email" value="<?= $value('email') ?>" class="form-control rounded-pill border-0 shadow-sm px-4 py-3" required autocomplete="email">
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Nova senha</label>
                    <input type="password" name="password" class="form-control rounded-pill border-0 shadow-sm px-4 py-3" required autocomplete="new-password">
                </div>
                <div class="mb-4">
                    <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Confirmar nova senha</label>
                    <input type="password" name="password_confirm" class="form-control rounded-pill border-0 shadow-sm px-4 py-3" required autocomplete="new-password">
                </div>
                <button type="submit" class="pill-btn btn-black w-100 py-3 shadow-sm">
                    Salvar e entrar <i class="fas fa-arrow-right ms-2"></i>
                </button>
            </form>
        </div>
    </div>
</section>
