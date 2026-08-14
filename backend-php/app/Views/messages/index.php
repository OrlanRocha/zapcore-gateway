<div class="d-flex align-items-center mb-4 mt-3">
    <div class="bg-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width:48px;height:48px;">
        <i class="fas fa-envelope text-dark"></i>
    </div>
    <h2 class="m-0 fw-bold" style="letter-spacing:-1px;">Mensagens</h2>
</div>

<div class="glass-card p-4">
    <div class="table-responsive">
        <table class="table custom-table table-borderless align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Instancia</th>
                    <th>Direcao</th>
                    <th>Contato</th>
                    <th>Mensagem</th>
                    <th>Status</th>
                    <th class="text-end pe-3">Data</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($messages)): ?>
                <tr>
                    <td colspan="6" class="text-center py-5 text-muted">Nenhuma mensagem encontrada.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                <tr>
                    <td class="ps-3 fw-bold text-muted"><?= htmlspecialchars($msg['instance_name'] ?? ('#' . $msg['instance_id'])) ?></td>
                    <td>
                        <?php if($msg['direction'] === 'inbound'): ?>
                            <span class="badge-purple"><i class="fas fa-arrow-down me-1"></i> Recebida</span>
                        <?php else: ?>
                            <span class="badge-yellow"><i class="fas fa-arrow-up me-1"></i> Enviada</span>
                        <?php endif; ?>
                    </td>
                    <td class="fw-bold" style="font-size:0.85rem;"><?= $msg['direction'] === 'inbound' ? htmlspecialchars($msg['from_jid']) : htmlspecialchars($msg['to_jid']) ?></td>
                    <td class="text-muted" style="max-width:360px;">
                        <div class="fw-bold text-dark" style="font-size:0.8rem;"><?= htmlspecialchars($msg['message_type']) ?></div>
                        <div class="text-truncate"><?= htmlspecialchars($msg['body'] ?? '') ?></div>
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
                    <td class="text-end pe-3 text-muted" style="font-size:0.8rem;"><?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
