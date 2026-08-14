<div class="d-flex align-items-center mb-4 mt-3">
    <div class="bg-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width:48px;height:48px;">
        <i class="fas fa-home text-dark"></i>
    </div>
    <h2 class="m-0 fw-bold" style="letter-spacing:-1px;">Dashboard</h2>
</div>

<div class="row g-4 mb-5">
    <?php
    $typeLabels = [
        'user' => 'Usuarios',
        'group' => 'Grupos',
        'newsletter' => 'Newsletter',
        'unknown' => 'Outros',
    ];
    $cards = [
        ['label' => 'Conectadas', 'value' => $connectedCount ?? 0, 'icon' => 'fa-check-circle', 'badge' => 'badge-green'],
        ['label' => 'Desconectadas', 'value' => $disconnectedCount ?? 0, 'icon' => 'fa-power-off', 'badge' => 'badge-gray'],
        ['label' => 'Enviadas hoje', 'value' => $sentTodayCount ?? 0, 'icon' => 'fa-paper-plane', 'badge' => 'badge-purple', 'breakdown' => $messageBreakdown['sent'] ?? []],
        ['label' => 'Recebidas hoje', 'value' => $receivedTodayCount ?? 0, 'icon' => 'fa-inbox', 'badge' => 'badge-green', 'breakdown' => $messageBreakdown['received'] ?? []],
        ['label' => 'Falhas de envio', 'value' => $failedCount ?? 0, 'icon' => 'fa-exclamation-triangle', 'badge' => 'badge-yellow', 'breakdown' => $messageBreakdown['failed'] ?? []],
        ['label' => 'Webhooks com erro', 'value' => $webhookErrorCount ?? 0, 'icon' => 'fa-plug-circle-xmark', 'badge' => 'badge-purple'],
    ];
    ?>
    <?php foreach ($cards as $card): ?>
    <div class="col-md-4 col-xl-2">
        <div class="glass-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="text-muted fw-bold text-uppercase" style="font-size:0.75rem; letter-spacing:1px;"><?= htmlspecialchars($card['label']) ?></span>
                <span class="<?= $card['badge'] ?>"><i class="fas <?= $card['icon'] ?>"></i></span>
            </div>
            <div class="huge-number text-dark"><?= (int) $card['value'] ?></div>
            <?php if (!empty($card['breakdown'])): ?>
                <div class="metric-breakdown mt-3">
                    <?php foreach ($typeLabels as $type => $label): ?>
                        <?php if ($type === 'unknown' && (int) ($card['breakdown'][$type] ?? 0) === 0) continue; ?>
                        <div>
                            <span><?= htmlspecialchars($label) ?></span>
                            <strong><?= (int) ($card['breakdown'][$type] ?? 0) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="glass-card p-5 text-center">
    <h4 class="mb-3 fw-bold">ZapCore Gateway</h4>
    <p class="text-muted mb-4" style="max-width: 560px; margin: 0 auto;">Gerencie instancias WhatsApp Web, acompanhe filas, historico de mensagens e eventos de webhook em um unico painel.</p>
    <a href="/instances" class="pill-btn btn-black shadow-lg">Ver Instancias <i class="fas fa-arrow-right ms-2"></i></a>
</div>
