<div class="d-flex justify-content-between align-items-center mb-4 mt-3">
    <div class="d-flex align-items-center">
        <a href="/instances/<?= (int) $instance->id ?>" class="btn btn-light rounded-circle shadow-sm me-3" style="width:40px;height:40px;display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div>
            <h2 class="m-0 fw-bold" style="letter-spacing:-1px;">Mensagens</h2>
            <div class="text-muted" style="font-size:0.9rem;"><?= htmlspecialchars($instance->name) ?></div>
        </div>
    </div>
</div>

<div class="glass-card p-4 mb-4">
    <form method="GET" class="row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Pesquisar</label>
            <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" placeholder="Texto, JID ou ID da mensagem">
        </div>
        <div class="col-md-2">
            <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Status</label>
            <select name="status" class="form-select rounded-pill border-0 shadow-sm px-4 py-2">
                <option value="">Todos</option>
                <?php foreach (['pending','queued','sent','delivered','read','failed','received'] as $option): ?>
                    <option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>><?= ucfirst($option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Tipo</label>
            <select name="chat_type" class="form-select rounded-pill border-0 shadow-sm px-4 py-2">
                <option value="">Todos</option>
                <option value="user" <?= ($chatType ?? '') === 'user' ? 'selected' : '' ?>>Usuarios</option>
                <option value="group" <?= ($chatType ?? '') === 'group' ? 'selected' : '' ?>>Grupos</option>
                <option value="newsletter" <?= ($chatType ?? '') === 'newsletter' ? 'selected' : '' ?>>Newsletter</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Direcao</label>
            <select name="direction" class="form-select rounded-pill border-0 shadow-sm px-4 py-2">
                <option value="">Todas</option>
                <option value="outbound" <?= $direction === 'outbound' ? 'selected' : '' ?>>Enviada</option>
                <option value="inbound" <?= $direction === 'inbound' ? 'selected' : '' ?>>Recebida</option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="pill-btn btn-black flex-grow-1 shadow-sm"><i class="fas fa-search"></i></button>
            <a href="/instances/<?= (int) $instance->id ?>/messages" class="pill-btn btn-white shadow-sm"><i class="fas fa-rotate-left"></i></a>
        </div>
    </form>
</div>

<div class="glass-card p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">Historico</h5>
        <span class="badge-gray"><?= (int) $total ?> registros</span>
    </div>
    <div class="table-responsive">
        <table class="table custom-table table-borderless align-middle mb-0">
            <thead>
                <tr>
                    <th>Direcao</th>
                    <th>Contato</th>
                    <th>Mensagem</th>
                    <th>Status</th>
                    <th class="text-end">Data</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($messages)): ?>
                    <tr><td colspan="5" class="text-center py-5 text-muted">Nenhuma mensagem encontrada.</td></tr>
                <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                    <tr>
                        <td>
                            <?php if ($msg['direction'] === 'inbound'): ?>
                                <span class="badge-purple"><i class="fas fa-arrow-down me-1"></i> Recebida</span>
                            <?php else: ?>
                                <span class="badge-yellow"><i class="fas fa-arrow-up me-1"></i> App</span>
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold" style="font-size:0.85rem;">
                            <?php
                                $chatTypeLabel = match ($msg['chat_type'] ?? 'user') {
                                    'group' => 'Grupo',
                                    'newsletter' => 'Newsletter',
                                    'unknown' => 'Desconhecido',
                                    default => 'Usuario',
                                };
                                $chatTypeClass = match ($msg['chat_type'] ?? 'user') {
                                    'group' => 'badge-yellow',
                                    'newsletter' => 'badge-purple',
                                    'unknown' => 'badge-gray',
                                    default => 'badge-green',
                                };
                            ?>
                            <div><?= htmlspecialchars($msg['direction'] === 'inbound' ? $msg['from_jid'] : $msg['to_jid']) ?></div>
                            <span class="<?= $chatTypeClass ?>" style="font-size:0.72rem; padding:0.2rem 0.55rem;"><?= $chatTypeLabel ?></span>
                        </td>
                        <td class="text-muted" style="max-width:420px;">
                            <div class="fw-bold text-dark" style="font-size:0.8rem;"><?= htmlspecialchars($msg['message_type']) ?></div>
                            <div class="text-truncate"><?= htmlspecialchars($msg['body'] ?? '') ?></div>
                            <?php if (!empty($msg['media_file_name'])): ?>
                                <a href="/messages/<?= (int) $msg['id'] ?>/media" target="_blank" class="small text-decoration-none"><i class="fas fa-paperclip"></i> <?= htmlspecialchars($msg['media_file_name']) ?></a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                                $sClass = 'badge-gray';
                                if (in_array($msg['status'], ['sent', 'delivered', 'read', 'received'], true)) $sClass = 'badge-green';
                                if (in_array($msg['status'], ['pending', 'queued'], true)) $sClass = 'badge-yellow';
                                if ($msg['status'] === 'failed') $sClass = 'badge-purple';
                            ?>
                            <span class="<?= $sClass ?>"><?= ucfirst($msg['status']) ?></span>
                        </td>
                        <td class="text-end text-muted" style="font-size:0.8rem;"><?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <?php
        $baseParams = array_filter([
            'q' => $query,
            'status' => $status,
            'direction' => $direction,
            'chat_type' => $chatType ?? '',
        ], static fn($value) => $value !== '');
    ?>
    <div class="d-flex justify-content-between align-items-center mt-4">
        <span class="text-muted" style="font-size:0.85rem;">Pagina <?= (int) $page ?> de <?= (int) $totalPages ?></span>
        <div class="d-flex gap-2">
            <?php if ($page > 1): ?>
                <a class="pill-btn btn-white shadow-sm" href="?<?= http_build_query(array_merge($baseParams, ['page' => $page - 1])) ?>">Anterior</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a class="pill-btn btn-black shadow-sm" href="?<?= http_build_query(array_merge($baseParams, ['page' => $page + 1])) ?>">Proxima</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
