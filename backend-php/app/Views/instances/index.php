<div class="d-flex justify-content-between align-items-center mb-4 mt-3">
    <div class="d-flex align-items-center">
        <div class="bg-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width:48px;height:48px;">
            <i class="fas fa-mobile-alt text-dark"></i>
        </div>
        <h2 class="m-0 fw-bold" style="letter-spacing:-1px;">Instancias</h2>
    </div>
    <a href="/instances/create" class="pill-btn btn-black shadow-sm"><i class="fas fa-plus me-1"></i> Nova Instancia</a>
</div>

<div class="glass-card p-4">
    <div class="table-responsive">
        <table class="table custom-table table-borderless align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Nome</th>
                    <th>UUID</th>
                    <th>Status</th>
                    <th>Acesso</th>
                    <th>Mensagens App</th>
                    <th class="text-end pe-3">Acoes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($instances)): ?>
                <tr>
                    <td colspan="6" class="text-center py-5 text-muted">Nenhuma instancia encontrada. Crie uma para comecar.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($instances as $instance): ?>
                <tr>
                    <td class="ps-3 fw-bold"><?= htmlspecialchars($instance['name']) ?></td>
                    <td class="text-muted" style="font-size:0.85rem;"><?= htmlspecialchars($instance['uuid']) ?></td>
                    <td>
                        <?php
                        $badgeClass = 'badge-gray';
                        if ($instance['status'] === 'connected') $badgeClass = 'badge-green';
                        if ($instance['status'] === 'error') $badgeClass = 'badge-purple';
                        if ($instance['status'] === 'connecting' || $instance['status'] === 'waiting_qr') $badgeClass = 'badge-yellow';
                        ?>
                        <span class="<?= $badgeClass ?>"><?= ucfirst($instance['status']) ?></span>
                    </td>
                    <td>
                        <?php if (($instance['access_role'] ?? 'owner') === 'owner'): ?>
                            <span class="badge-gray"><i class="fas fa-crown me-1"></i> Proprietario</span>
                        <?php else: ?>
                            <span class="badge-purple"><i class="fas fa-user-group me-1"></i> Compartilhada</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="metric-inline">
                            <span class="badge-gray"><?= (int) ($instance['app_messages_count'] ?? 0) ?> total</span>
                            <div>
                                <span>Usuarios <?= (int) ($instance['app_user_count'] ?? 0) ?></span>
                                <span>Grupos <?= (int) ($instance['app_group_count'] ?? 0) ?></span>
                                <span>Newsletter <?= (int) ($instance['app_newsletter_count'] ?? 0) ?></span>
                            </div>
                        </div>
                    </td>
                    <td class="text-end pe-3">
                        <div class="d-inline-flex gap-2">
                            <a href="/instances/<?= (int) $instance['id'] ?>" class="pill-btn btn-white btn-sm">Gerenciar</a>
                            <?php if (($instance['access_role'] ?? 'owner') === 'owner'): ?>
                                <button type="button" class="pill-btn btn-white btn-sm text-danger" onclick="deleteInstance(<?= (int) $instance['id'] ?>, '<?= htmlspecialchars($instance['name'], ENT_QUOTES) ?>')">
                                    <i class="fas fa-trash"></i>
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
function deleteInstance(id, name) {
    Swal.fire({
        title: 'Excluir instancia?',
        text: `Esta acao remove "${name}" e seus dados vinculados.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Excluir',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#dc3545'
    }).then((result) => {
        if (!result.isConfirmed) return;

        fetch(`/instances/${id}/delete`, { method: 'POST' })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Excluida', 'Instancia excluida com sucesso.', 'success')
                        .then(() => window.location.href = data.redirect || '/instances');
                } else {
                    Swal.fire('Erro', data.error || 'Nao foi possivel excluir.', 'error');
                }
            })
            .catch(() => Swal.fire('Erro', 'Erro de conexao ao excluir.', 'error'));
    });
}
</script>
<?php $scripts = ob_get_clean(); ?>
