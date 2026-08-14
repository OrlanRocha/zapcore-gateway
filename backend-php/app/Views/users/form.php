<?php
$isEdit = !empty($user);
$old = $old ?? [];
$value = static function (string $field, mixed $default = '') use ($old, $user, $isEdit) {
    if (array_key_exists($field, $old)) {
        return $old[$field];
    }
    if ($isEdit && property_exists($user, $field)) {
        return $user->{$field};
    }
    return $default;
};
$currentAuthUser = \App\Core\Auth::user();
$editingSelf = $isEdit && $currentAuthUser && (int) $currentAuthUser->id === (int) $user->id;
?>

<div class="d-flex align-items-center mb-4 mt-3">
    <a href="/users" class="btn btn-light rounded-circle shadow-sm me-3" style="width:40px;height:40px;display:flex;align-items:center;justify-content:center;">
        <i class="fas fa-arrow-left"></i>
    </a>
    <div>
        <h2 class="m-0 fw-bold" style="letter-spacing:-1px;"><?= $isEdit ? 'Editar usuario' : 'Novo usuario' ?></h2>
        <div class="text-muted" style="font-size:0.9rem;">Defina dados e perfil de acesso</div>
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

            <form method="POST" action="<?= $isEdit ? '/users/' . (int) $user->id : '/users' ?>" class="inner-card">
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Nome</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($value('name')) ?>" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" required>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">E-mail</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($value('email')) ?>" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" required>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Senha <?= $isEdit ? '(deixe em branco para manter)' : '' ?></label>
                    <input type="password" name="password" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" <?= $isEdit ? '' : 'required' ?> autocomplete="new-password">
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Perfil de acesso</label>
                        <select name="role" class="form-select rounded-pill border-0 shadow-sm px-4 py-2" <?= $editingSelf ? 'disabled' : '' ?>>
                            <?php foreach ($roles as $role => $label): ?>
                                <option value="<?= htmlspecialchars($role) ?>" <?= $value('role', 'user') === $role ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($editingSelf): ?>
                            <input type="hidden" name="role" value="<?= htmlspecialchars($user->role) ?>">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <label class="d-flex align-items-center gap-2 bg-white rounded-pill px-4 py-2 shadow-sm w-100">
                            <input type="checkbox" name="active" value="1" <?= (int) $value('active', 1) === 1 ? 'checked' : '' ?> <?= $editingSelf ? 'disabled' : '' ?>>
                            <span>Usuario ativo</span>
                        </label>
                        <?php if ($editingSelf): ?>
                            <input type="hidden" name="active" value="1">
                        <?php endif; ?>
                    </div>
                </div>

                <div class="d-flex gap-2 flex-wrap">
                    <button type="submit" class="pill-btn btn-black flex-grow-1 shadow-sm"><?= $isEdit ? 'Salvar usuario' : 'Criar usuario' ?></button>
                    <?php if ($isEdit): ?>
                        <?php if ($editingSelf): ?>
                            <button type="button" class="pill-btn btn-white text-muted shadow-sm" disabled title="Voce nao pode excluir seu proprio usuario">
                                <i class="fas fa-lock"></i> Excluir
                            </button>
                        <?php else: ?>
                            <button type="button" class="pill-btn btn-white text-danger shadow-sm" onclick="deleteUser(<?= (int) $user->id ?>, '<?= htmlspecialchars($user->name, ENT_QUOTES) ?>')">
                                <i class="fas fa-trash"></i> Excluir
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="glass-card p-4 h-100">
            <h5 class="fw-bold mb-3">Perfis disponiveis</h5>
            <div class="inner-card">
                <div class="mb-3">
                    <span class="badge-purple me-2">admin</span>
                    <span class="text-muted">Gerencia usuarios, perfis e configuracoes administrativas.</span>
                </div>
                <div>
                    <span class="badge-gray me-2">user</span>
                    <span class="text-muted">Acesso operacional ao painel.</span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($isEdit): ?>
<?php ob_start(); ?>
<script>
function deleteUser(id, name) {
    Swal.fire({
        title: 'Excluir usuario?',
        text: `Esta acao remove "${name}" e os dados vinculados a ele.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Excluir',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#dc3545'
    }).then((result) => {
        if (!result.isConfirmed) return;

        fetch(`/users/${id}/delete`, { method: 'POST' })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Excluido', 'Usuario excluido com sucesso.', 'success')
                        .then(() => window.location.href = data.redirect || '/users');
                } else {
                    Swal.fire('Erro', data.error || 'Nao foi possivel excluir.', 'error');
                }
            })
            .catch(() => Swal.fire('Erro', 'Erro de conexao ao excluir.', 'error'));
    });
}
</script>
<?php $scripts = ob_get_clean(); ?>
<?php endif; ?>
