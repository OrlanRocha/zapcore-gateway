<div class="d-flex align-items-center mb-4 mt-3">
    <div class="bg-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width:48px;height:48px;">
        <i class="fas fa-user text-dark"></i>
    </div>
    <div>
        <h2 class="m-0 fw-bold" style="letter-spacing:-1px;">Meu perfil</h2>
        <div class="text-muted" style="font-size:0.9rem;">Edite suas configuracoes de acesso</div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="glass-card p-4">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger border-0 rounded-4 shadow-sm">
                    <?php foreach ($errors as $error): ?>
                        <div><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success border-0 rounded-4 shadow-sm"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <form method="POST" action="/profile" class="inner-card">
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Nome</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($user->name) ?>" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" required>
                </div>
                <div class="mb-4">
                    <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">E-mail</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($user->email) ?>" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" required>
                </div>

                <div class="border-top pt-4 mt-4">
                    <h5 class="fw-bold mb-3">Alterar senha</h5>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Senha atual</label>
                        <input type="password" name="current_password" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" autocomplete="current-password">
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Nova senha</label>
                            <input type="password" name="password" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" autocomplete="new-password">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Confirmar nova senha</label>
                            <input type="password" name="password_confirm" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" autocomplete="new-password">
                        </div>
                    </div>
                </div>

                <button type="submit" class="pill-btn btn-black w-100 shadow-sm mt-4">Salvar perfil</button>
            </form>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="glass-card p-4 h-100">
            <h5 class="fw-bold mb-3">Seu perfil de acesso</h5>
            <div class="inner-card">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="text-muted">Perfil</span>
                    <span class="<?= $user->role === 'admin' ? 'badge-purple' : 'badge-gray' ?>"><?= htmlspecialchars($user->role) ?></span>
                </div>
                <p class="text-muted mb-0" style="font-size:0.9rem;">
                    Administradores gerenciam usuarios, instancias e configuracoes. Usuarios comuns acessam o painel operacional conforme liberado.
                </p>
            </div>
        </div>
    </div>
</div>
