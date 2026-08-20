<div class="d-flex justify-content-between align-items-center mb-4 mt-3">
    <div class="d-flex align-items-center">
        <a href="/instances/<?= (int) $instance->id ?>" class="btn btn-light rounded-circle shadow-sm me-3" style="width:40px;height:40px;display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div>
            <h2 class="m-0 fw-bold" style="letter-spacing:-1px;">Webhooks</h2>
            <div class="text-muted" style="font-size:0.9rem;"><?= htmlspecialchars($instance->name) ?></div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="glass-card p-4 h-100">
            <h5 class="fw-bold mb-4">Novo Webhook da Instancia</h5>
            <form action="/instances/<?= (int) $instance->id ?>/webhooks" method="POST" class="inner-card">
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Nome</label>
                    <input type="text" name="name" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" required placeholder="CRM ou Atendimento">
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">URL</label>
                    <input type="url" name="url" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" required placeholder="https://exemplo.com/webhook">
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Secret</label>
                    <input type="text" name="secret" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" placeholder="Gerado automaticamente se vazio">
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Eventos</label>
                    <div class="row g-2">
                        <?php foreach (['instance.qr','instance.connected','instance.disconnected','instance.logged_out','message.received','message.sent','message.delivered','message.read','message.failed','recipient.opted_in','recipient.opted_out'] as $event): ?>
                        <div class="col-md-6">
                            <label class="d-flex align-items-center gap-2 bg-white rounded-pill px-3 py-2 shadow-sm">
                                <input type="checkbox" name="events[]" value="<?= htmlspecialchars($event) ?>">
                                <span style="font-size:0.85rem;"><?= htmlspecialchars($event) ?></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <label class="d-flex align-items-center gap-2 mb-4">
                    <input type="checkbox" name="active" value="1" checked>
                    <span>Ativo</span>
                </label>
                <button type="submit" class="pill-btn btn-black w-100 shadow-sm">Cadastrar</button>
            </form>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="glass-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="fw-bold mb-0">Webhooks desta instancia</h5>
                <span class="badge-gray"><?= count($webhooks ?? []) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table custom-table table-borderless align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Nome</th>
                            <th>URL</th>
                            <th class="text-end pe-3">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($webhooks)): ?>
                            <tr><td colspan="3" class="text-center py-5 text-muted">Nenhum webhook configurado para esta instancia.</td></tr>
                        <?php else: ?>
                            <?php foreach ($webhooks as $wh): ?>
                            <tr>
                                <td class="ps-3 fw-bold"><?= htmlspecialchars($wh['name']) ?></td>
                                <td class="text-muted"><code class="bg-light px-2 py-1 rounded-3"><?= htmlspecialchars($wh['url']) ?></code></td>
                                <td class="text-end pe-3">
                                    <?= $wh['active'] ? '<span class="badge-green">Ativo</span>' : '<span class="badge-gray">Inativo</span>' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="glass-card p-4">
    <h5 class="fw-bold mb-4">Logs de Webhook desta instancia</h5>
    <div class="table-responsive">
        <table class="table custom-table table-borderless align-middle mb-0">
            <thead>
                <tr>
                    <th>Webhook</th>
                    <th>Evento</th>
                    <th>Status HTTP</th>
                    <th>Resultado</th>
                    <th class="text-end">Data</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($webhookLogs)): ?>
                    <tr><td colspan="5" class="text-center py-5 text-muted">Nenhum envio registrado ainda.</td></tr>
                <?php else: ?>
                    <?php foreach ($webhookLogs as $log): ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($log['webhook_name']) ?></td>
                        <td><code><?= htmlspecialchars($log['event_name']) ?></code></td>
                        <td><?= $log['response_status'] ? (int) $log['response_status'] : '-' ?></td>
                        <td><?= $log['success'] ? '<span class="badge-green">OK</span>' : '<span class="badge-yellow">Erro</span>' ?></td>
                        <td class="text-end text-muted" style="font-size:0.8rem;"><?= date('d/m/Y H:i', strtotime($log['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
