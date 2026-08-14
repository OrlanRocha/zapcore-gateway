<div class="d-flex justify-content-between align-items-center mb-4 mt-3">
    <div class="d-flex align-items-center">
        <div class="bg-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width:48px;height:48px;">
            <i class="fas fa-users text-dark"></i>
        </div>
        <div>
            <h2 class="m-0 fw-bold" style="letter-spacing:-1px;">Usuarios</h2>
            <div class="text-muted" style="font-size:0.9rem;">CRUD e perfis de acesso</div>
        </div>
    </div>
    <a href="/users/create" class="pill-btn btn-black shadow-sm"><i class="fas fa-plus me-1"></i> Novo usuario</a>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="glass-card p-4 h-100">
            <h5 class="fw-bold mb-3">Perfis de acesso</h5>
            <div class="inner-card">
                <div class="mb-3">
                    <span class="badge-purple me-2">admin</span>
                    <span class="text-muted">Acesso administrativo, incluindo gestao de usuarios.</span>
                </div>
                <div>
                    <span class="badge-gray me-2">user</span>
                    <span class="text-muted">Acesso operacional ao painel e instancias.</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="glass-card p-4">
    <div class="table-responsive">
        <table class="table custom-table table-borderless align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Nome</th>
                    <th>E-mail</th>
                    <th>Perfil</th>
                    <th>Status</th>
                    <th class="text-end pe-3">Acoes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="5" class="text-center py-5 text-muted">Nenhum usuario encontrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $row): ?>
                    <tr>
                        <td class="ps-3 fw-bold"><?= htmlspecialchars($row['name']) ?></td>
                        <td class="text-muted"><?= htmlspecialchars($row['email']) ?></td>
                        <td><span class="<?= $row['role'] === 'admin' ? 'badge-purple' : 'badge-gray' ?>"><?= htmlspecialchars($row['role']) ?></span></td>
                        <td><?= $row['active'] ? '<span class="badge-green">Ativo</span>' : '<span class="badge-yellow">Inativo</span>' ?></td>
                        <td class="text-end pe-3">
                            <div class="d-inline-flex gap-2">
                                <a href="/users/<?= (int) $row['id'] ?>/edit" class="pill-btn btn-white btn-sm">Editar</a>
                                <?php if ((int) $row['id'] !== (int) \App\Core\Auth::user()->id): ?>
                                    <button type="button" class="pill-btn btn-white btn-sm text-danger" onclick="deleteUser(<?= (int) $row['id'] ?>, '<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>')">
                                        <i class="fas fa-trash"></i> Excluir
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="pill-btn btn-white btn-sm text-muted" disabled title="Voce nao pode excluir seu proprio usuario">
                                        <i class="fas fa-lock"></i> Excluir
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

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
