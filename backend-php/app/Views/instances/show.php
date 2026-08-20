<?php
$latestError = null;
foreach (($connectionLogs ?? []) as $log) {
    if (($log['event_type'] ?? '') === 'error') {
        $latestError = $log;
        break;
    }
}
?>

<div class="d-flex align-items-center mb-4 mt-3">
    <a href="/instances" class="btn btn-light rounded-circle shadow-sm me-3" style="width:40px;height:40px;display:flex;align-items:center;justify-content:center;">
        <i class="fas fa-arrow-left"></i>
    </a>
    <div>
        <h2 class="m-0 fw-bold" style="letter-spacing:-1px;">Detalhes da Instancia</h2>
        <div class="text-muted" style="font-size:0.9rem;">
            <?= htmlspecialchars($instance->uuid) ?>
            <?php if (!$canManageShares): ?> · Compartilhada com voce<?php endif; ?>
        </div>
    </div>
    <button type="button" class="pill-btn btn-black ms-auto" onclick="openInstanceChat()"><i class="fas fa-comments"></i> Abrir chat</button>
</div>

<div class="offcanvas offcanvas-end instance-chat-drawer" tabindex="-1" id="instanceChat" aria-labelledby="instanceChatLabel">
  <div class="offcanvas-header border-bottom">
    <div><h5 class="fw-bold mb-0" id="instanceChatLabel">Chat da instancia</h5><small class="text-muted">Mensagens, mídias e conversas</small></div>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
  </div>
  <div class="offcanvas-body d-flex flex-column p-0">
    <div class="chat-tabs" role="tablist">
      <button class="chat-tab active" data-chat-type="all" onclick="setChatTab('all', this)"><i class="fas fa-comments"></i><span>Todos</span></button>
      <button class="chat-tab" data-chat-type="user" onclick="setChatTab('user', this)"><i class="fas fa-user"></i><span>Usuarios</span></button>
      <button class="chat-tab" data-chat-type="group" onclick="setChatTab('group', this)"><i class="fas fa-users"></i><span>Grupos</span></button>
      <button class="chat-tab" data-chat-type="newsletter" onclick="setChatTab('newsletter', this)"><i class="fas fa-bullhorn"></i><span>Publicações</span></button>
    </div>
    <div class="chat-searchbar"><i class="fas fa-search"></i><input id="chat-search" placeholder="Pesquisar conversas" onkeydown="if(event.key==='Enter') loadChatContacts()"><button class="chat-refresh" onclick="loadChatContacts()" title="Atualizar"><i class="fas fa-rotate-right"></i></button></div><input type="hidden" id="chat-filter" value="all"><input type="hidden" id="chat-selected-jid" value="">
    <div class="chat-workspace">
      <aside id="chat-contacts" class="chat-contacts"><div class="text-center text-muted py-5">Carregando conversas...</div></aside>
      <section class="chat-conversation"><div id="chat-conversation-header" class="chat-conversation-header"><span class="chat-avatar"><i class="fas fa-user"></i></span><div><strong>Selecione uma conversa</strong><small>Escolha um contato ao lado</small></div></div><div id="chat-messages" class="flex-grow-1 overflow-auto chat-message-list"><div class="text-center text-muted py-5">Selecione uma conversa para ver o histórico.</div></div></section>
    </div>
    <form id="chat-form" class="chat-composer">
      <div class="d-flex gap-2 mb-2"><select id="chat-type" class="form-select"><option value="user">Usuario</option><option value="group">Grupo</option><option value="newsletter">Newsletter</option></select><input id="chat-to" class="form-control" placeholder="Destino ou JID" required></div>
      <textarea id="chat-text" class="form-control mb-2" rows="2" placeholder="Digite uma mensagem"></textarea>
      <div class="d-flex gap-2"><select id="chat-media-type" class="form-select" style="max-width:115px"><option value="image">Imagem</option><option value="video">Video</option><option value="audio">Audio</option><option value="document">Documento</option></select><input id="chat-media-url" class="form-control" placeholder="URL opcional da midia"><button class="pill-btn btn-black" type="submit"><i class="fas fa-paper-plane"></i></button></div>
    </form>
  </div>
</div>

<div class="row g-4">
    <div class="col-md-5">
        <div class="glass-card p-4 h-100 d-flex flex-column">
            <h4 class="fw-bold mb-1"><?= htmlspecialchars($instance->name) ?></h4>
            <p class="text-muted" style="font-size:0.85rem;">Provedor: <?= htmlspecialchars($instance->provider) ?></p>

            <div class="mt-4 mb-4">
                <p class="text-muted fw-bold text-uppercase mb-2" style="font-size:0.8rem; letter-spacing:1px;">Status</p>
                <?php
                    $badgeClass = 'badge-gray';
                    if ($instance->status === 'connected') $badgeClass = 'badge-green';
                    if ($instance->status === 'error') $badgeClass = 'badge-purple';
                    if ($instance->status === 'connecting' || $instance->status === 'waiting_qr') $badgeClass = 'badge-yellow';
                ?>
                <span id="instance-status" class="<?= $badgeClass ?> fs-5 px-3 py-2"><?= ucfirst($instance->status) ?></span>
            </div>

            <?php if ($instance->status === 'error'): ?>
                <div class="alert alert-warning border-0 rounded-4 shadow-sm" style="font-size:0.9rem;">
                    <div class="fw-bold mb-1">A conexao retornou erro.</div>
                    <div class="text-muted"><?= htmlspecialchars($latestError['description'] ?? 'Clique em Conectar para tentar novamente.') ?></div>
                </div>
            <?php endif; ?>

            <div class="mt-auto d-flex gap-2 flex-wrap">
                <button class="pill-btn btn-black flex-grow-1 shadow-sm <?= $instance->status === 'connected' ? 'd-none' : '' ?>" id="btn-connect" onclick="connectInstance('qr')">
                    <i class="fas fa-qrcode"></i> <?= $instance->status === 'error' ? 'Tentar QR' : 'Conectar QR' ?>
                </button>
                <button class="pill-btn btn-white shadow-sm <?= $instance->status === 'connected' ? 'd-none' : '' ?>" id="btn-connect-pin" onclick="promptPinConnection()">
                    <i class="fas fa-key"></i> PIN Code
                </button>
                <button class="pill-btn btn-white shadow-sm <?= $instance->status !== 'connected' ? 'd-none' : '' ?>" id="btn-disconnect" onclick="disconnectInstance()">
                    <i class="fas fa-power-off text-danger"></i> Desconectar
                </button>
                <?php if ($canManageShares): ?>
                    <button class="pill-btn btn-white shadow-sm text-danger" onclick="deleteInstance()">
                        <i class="fas fa-trash"></i> Excluir
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="glass-card p-4 h-100">
            <h5 class="fw-bold mb-4">Enviar Mensagem de Teste</h5>
            <?php if ($instance->status !== 'connected'): ?>
                <div class="alert alert-warning border-0 rounded-4 shadow-sm" style="font-size:0.9rem;">
                    Esta instancia ainda nao esta conectada. Escaneie o QR Code e aguarde o status ficar <strong>connected</strong> antes de enviar.
                </div>
            <?php endif; ?>
            <div class="inner-card">
                <form id="test-msg-form">
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Para</label>
                        <input type="text" id="test-to" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" placeholder="Ex: 5511999999999" <?= $instance->status !== 'connected' ? 'disabled' : '' ?>>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Tipo de conversa</label>
                        <select id="test-chat-type" class="form-select rounded-pill border-0 shadow-sm px-4 py-2" <?= $instance->status !== 'connected' ? 'disabled' : '' ?>>
                            <option value="user">Usuario</option>
                            <option value="group">Grupo (@g.us)</option>
                            <option value="newsletter">Newsletter (@newsletter)</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Mensagem</label>
                        <textarea id="test-msg" class="form-control rounded-4 border-0 shadow-sm p-3" rows="3" placeholder="Digite sua mensagem aqui..." <?= $instance->status !== 'connected' ? 'disabled' : '' ?>></textarea>
                    </div>
                    <button type="button" class="pill-btn btn-black w-100 shadow-sm" onclick="sendTestMessage()" <?= $instance->status !== 'connected' ? 'disabled' : '' ?>>Enviar Mensagem</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($canManageShares): ?>
<div class="row g-4 mt-1">
    <div class="col-12">
        <div class="glass-card p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="fw-bold mb-1">Compartilhamento</h5>
                    <div class="text-muted" style="font-size:0.85rem;">Convide um usuario ativo pelo e-mail ou nome de login.</div>
                </div>
                <span class="badge-gray"><?= count($instanceShares) ?> usuario(s)</span>
            </div>
            <form id="share-instance-form" class="d-flex gap-2 mb-3" onsubmit="shareInstance(event)">
                <input id="share-identity" class="form-control rounded-pill border-0 shadow-sm px-4" placeholder="E-mail ou login do usuario" required>
                <button class="pill-btn btn-black" type="submit"><i class="fas fa-user-plus"></i> Compartilhar</button>
            </form>
            <?php if (!$instanceShares): ?>
                <div class="text-muted py-2">Esta instancia ainda nao foi compartilhada.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table custom-table table-borderless align-middle mb-0">
                        <thead><tr><th>Usuario</th><th>E-mail</th><th>Permissao</th><th class="text-end">Acoes</th></tr></thead>
                        <tbody>
                        <?php foreach ($instanceShares as $sharedUser): ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars($sharedUser['name']) ?></td>
                                <td class="text-muted"><?= htmlspecialchars($sharedUser['email']) ?></td>
                                <td><span class="badge-purple">Editor</span></td>
                                <td class="text-end">
                                    <button type="button" class="pill-btn btn-white btn-sm text-danger" onclick="revokeInstanceShare(<?= (int) $sharedUser['id'] ?>, '<?= htmlspecialchars($sharedUser['name'], ENT_QUOTES) ?>')">
                                        <i class="fas fa-user-minus"></i> Revogar
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canManageShares): ?>
<div class="row g-4 mt-1">
    <div class="col-12">
        <div class="glass-card p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="fw-bold mb-1">Consentimento de destinatarios</h5>
                    <div class="text-muted" style="font-size:0.85rem;">Envios individuais exigem autorizacao ativa do contato.</div>
                </div>
                <span class="badge-gray"><?= count($recipientConsents) ?> contato(s)</span>
            </div>
            <form id="consent-form" class="row g-2 mb-3" onsubmit="grantRecipientConsent(event)">
                <div class="col-md-4"><input id="consent-to" class="form-control rounded-pill border-0 shadow-sm px-4" placeholder="Numero com DDI e DDD" required></div>
                <div class="col-md-5"><input id="consent-note" class="form-control rounded-pill border-0 shadow-sm px-4" placeholder="Origem ou observacao do consentimento" required></div>
                <div class="col-md-3"><button class="pill-btn btn-black w-100" type="submit"><i class="fas fa-user-check"></i> Autorizar</button></div>
            </form>
            <?php if (!$recipientConsents): ?>
                <div class="text-muted py-2">Nenhum consentimento registrado nesta instancia.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table custom-table table-borderless align-middle mb-0">
                        <thead><tr><th>Contato</th><th>Status</th><th>Origem</th><th>Atualizado</th><th class="text-end">Acoes</th></tr></thead>
                        <tbody>
                        <?php foreach ($recipientConsents as $consent): ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars($consent['jid']) ?></td>
                                <td><?= $consent['status'] === 'opted_in' ? '<span class="badge-green">Autorizado</span>' : '<span class="badge-purple">Revogado</span>' ?></td>
                                <td class="text-muted"><?= htmlspecialchars($consent['source']) ?></td>
                                <td class="text-muted" style="font-size:0.82rem;"><?= date('d/m/Y H:i', strtotime($consent['updated_at'])) ?></td>
                                <td class="text-end">
                                    <?php if ($consent['status'] === 'opted_in'): ?>
                                        <button type="button" class="pill-btn btn-white btn-sm text-danger" onclick="revokeRecipientConsent('<?= htmlspecialchars($consent['jid'], ENT_QUOTES) ?>')"><i class="fas fa-user-xmark"></i> Revogar</button>
                                    <?php else: ?>
                                        <button type="button" class="pill-btn btn-white btn-sm" onclick="prefillRecipientConsent('<?= htmlspecialchars($consent['jid'], ENT_QUOTES) ?>')"><i class="fas fa-rotate-left"></i> Reautorizar</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-4 mt-1">
    <?php
    $typeLabels = [
        'user' => 'Usuarios',
        'group' => 'Grupos',
        'newsletter' => 'Newsletter',
        'unknown' => 'Outros',
    ];
    $cards = [
        ['label' => 'Mensagens App', 'value' => $sentByAppCount ?? 0, 'icon' => 'fa-paper-plane', 'badge' => 'badge-purple', 'breakdown' => $messageBreakdown['sent'] ?? []],
        ['label' => 'Recebidas', 'value' => $receivedCount ?? 0, 'icon' => 'fa-inbox', 'badge' => 'badge-green', 'breakdown' => $messageBreakdown['received'] ?? []],
        ['label' => 'Falhas', 'value' => $failedCount ?? 0, 'icon' => 'fa-triangle-exclamation', 'badge' => 'badge-yellow', 'breakdown' => $messageBreakdown['failed'] ?? []],
        ['label' => 'Webhooks', 'value' => $webhookCount ?? 0, 'icon' => 'fa-plug', 'badge' => 'badge-gray'],
    ];
    ?>
    <?php foreach ($cards as $card): ?>
    <div class="col-md-3">
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

<div class="row g-4 mt-1">
    <div class="col-lg-7">
        <div class="glass-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">Mensagens desta instancia</h5>
                <a href="/instances/<?= (int) $instance->id ?>/messages" class="pill-btn btn-white btn-sm">Ver todas</a>
            </div>
            <div class="table-responsive">
                <table class="table custom-table table-borderless align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Direcao</th>
                            <th>Contato</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($latestMessages)): ?>
                        <tr><td colspan="3" class="text-center py-4 text-muted">Nenhuma mensagem nesta instancia.</td></tr>
                    <?php else: ?>
                        <?php foreach ($latestMessages as $msg): ?>
                        <tr>
                            <td><?= $msg['direction'] === 'inbound' ? '<span class="badge-purple">Recebida</span>' : '<span class="badge-yellow">App</span>' ?></td>
                            <td class="text-muted" style="font-size:0.85rem;"><?= htmlspecialchars($msg['direction'] === 'inbound' ? $msg['from_jid'] : $msg['to_jid']) ?></td>
                            <td><span class="badge-gray"><?= htmlspecialchars($msg['status']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="glass-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">Webhooks desta instancia</h5>
                <a href="/instances/<?= (int) $instance->id ?>/webhooks" class="pill-btn btn-white btn-sm">Gerenciar</a>
            </div>
            <div class="table-responsive">
                <table class="table custom-table table-borderless align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th class="text-end">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($instanceWebhooks)): ?>
                        <tr><td colspan="2" class="text-center py-4 text-muted">Nenhum webhook nesta instancia.</td></tr>
                    <?php else: ?>
                        <?php foreach ($instanceWebhooks as $wh): ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($wh['name']) ?></td>
                            <td class="text-end"><?= $wh['active'] ? '<span class="badge-green">Ativo</span>' : '<span class="badge-gray">Inativo</span>' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="glass-card p-4 mt-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="fw-bold mb-0">Logs de Conexao</h5>
        <span class="badge-gray"><?= count($connectionLogs ?? []) ?> eventos</span>
    </div>
    <div class="table-responsive">
        <table class="table custom-table table-borderless align-middle mb-0">
            <thead>
                <tr>
                    <th>Evento</th>
                    <th>Descricao</th>
                    <th class="text-end">Data</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($connectionLogs)): ?>
                    <tr><td colspan="3" class="text-center py-4 text-muted">Nenhum log registrado ainda.</td></tr>
                <?php else: ?>
                    <?php foreach ($connectionLogs as $log): ?>
                    <tr>
                        <td><span class="badge-gray"><?= htmlspecialchars($log['event_type']) ?></span></td>
                        <td class="text-muted"><?= htmlspecialchars($log['description'] ?? '') ?></td>
                        <td class="text-end text-muted" style="font-size:0.8rem;"><?= date('d/m/Y H:i', strtotime($log['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="qrModal" tabindex="-1" aria-labelledby="qrModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="qrModalLabel">Conectar WhatsApp</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" onclick="stopPolling()"></button>
      </div>
      <div class="modal-body text-center">
        <h6 id="qr-status-text">Gerando QR Code... aguarde.</h6>
        <div id="qr-wrapper" style="display:none; min-height: 250px;">
            <img id="qr-image" src="" alt="QR Code" class="img-fluid border p-2" style="max-width: 250px;">
        </div>
        <div id="pin-wrapper" style="display:none; min-height: 190px;">
            <div class="text-muted fw-bold text-uppercase mb-2" style="font-size:0.78rem; letter-spacing:1px;">PIN Code</div>
            <div id="pairing-code" class="fw-bold bg-light rounded-4 shadow-sm px-4 py-3 mx-auto mb-3" style="font-size:2rem; letter-spacing:0.25rem; max-width:260px;"></div>
            <div class="text-muted mx-auto mb-3" style="font-size:0.9rem; max-width:340px;">
                No celular, abra WhatsApp &gt; Aparelhos conectados &gt; Conectar aparelho &gt; Conectar com numero de telefone e digite este codigo.
            </div>
            <button type="button" class="pill-btn btn-white btn-sm" onclick="copyPairingCode()">
                <i class="fas fa-copy"></i> Copiar
            </button>
        </div>
        <div class="spinner-border text-primary mt-3" role="status" id="qr-spinner">
          <span class="visually-hidden">Loading...</span>
        </div>
      </div>
    </div>
  </div>
</div>

<?php ob_start(); ?>
<script>
const instanceId = '<?= (int) $instance->id ?>';
let pollInterval;
let qrModal;
let connectionMode = 'qr';
let instanceChat;
let chatRefreshInterval = null;

document.addEventListener("DOMContentLoaded", function() {
    qrModal = new bootstrap.Modal(document.getElementById('qrModal'));
    instanceChat = new bootstrap.Offcanvas(document.getElementById('instanceChat'));
    const initStatus = '<?= htmlspecialchars($instance->status) ?>';
    if (initStatus === 'connecting' || initStatus === 'waiting_qr') {
        qrModal.show();
        startPolling();
    }
});

function openInstanceChat() { instanceChat.show(); loadChatContacts(); startChatRefresh(); }

function grantRecipientConsent(event) {
    event.preventDefault();
    const to = document.getElementById('consent-to').value.trim();
    const note = document.getElementById('consent-note').value.trim();
    fetch(`/instances/${instanceId}/consents`, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({to, note})})
        .then(r => r.json()).then(data => {
            if (!data.success) return Swal.fire('Erro', data.error || 'Nao foi possivel registrar o consentimento', 'error');
            Swal.fire({icon:'success', title:'Contato autorizado', text:data.jid, timer:1400, showConfirmButton:false}).then(() => location.reload());
        });
}

function revokeRecipientConsent(jid) {
    Swal.fire({title:'Revogar consentimento?', text:'Envios pendentes para este contato serao cancelados.', icon:'warning', showCancelButton:true, confirmButtonText:'Revogar', cancelButtonText:'Cancelar'})
        .then(result => {
            if (!result.isConfirmed) return;
            fetch(`/instances/${instanceId}/consents/revoke`, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({to:jid})})
                .then(r => r.json()).then(data => {
                    if (!data.success) return Swal.fire('Erro', data.error || 'Nao foi possivel revogar', 'error');
                    location.reload();
                });
        });
}

function prefillRecipientConsent(jid) {
    document.getElementById('consent-to').value = jid;
    document.getElementById('consent-note').focus();
}
function startChatRefresh() { if (chatRefreshInterval) clearInterval(chatRefreshInterval); chatRefreshInterval = setInterval(() => { loadChatContacts(); loadInstanceChat(); }, 4000); }
function stopChatRefresh() { if (chatRefreshInterval) { clearInterval(chatRefreshInterval); chatRefreshInterval = null; } }
function setChatTab(type, button) { document.querySelectorAll('.chat-tab').forEach(item => item.classList.remove('active')); button.classList.add('active'); document.getElementById('chat-filter').value = type; if (['user', 'group', 'newsletter'].includes(type)) document.getElementById('chat-type').value = type; document.getElementById('chat-selected-jid').value = ''; document.getElementById('chat-to').value = ''; document.getElementById('chat-conversation-header').innerHTML = '<div><strong>Selecione uma conversa</strong><small>para ver o historico</small></div>'; document.getElementById('chat-messages').innerHTML = '<div class="text-center text-muted py-5">Selecione uma conversa para ver o historico.</div>'; loadChatContacts(); }
function escapeChat(value) { return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); }
function renderChatMedia(message) { if (!message.media_url) return ''; const url = escapeChat(message.media_url), mime = String(message.mime_type || ''); if (mime.startsWith('image/')) return `<a href="${url}" target="_blank"><img class="chat-media-image" src="${url}" alt="Midia recebida" loading="lazy"></a>`; if (mime.startsWith('video/')) return `<video class="chat-media-video" controls preload="metadata" src="${url}"></video>`; if (mime.startsWith('audio/')) return `<audio class="chat-media-audio" controls src="${url}"></audio>`; return `<a class="chat-file-link" href="${url}" target="_blank"><i class="fas fa-paperclip"></i> ${escapeChat(message.file_name || 'Abrir arquivo')}</a>`; }
function renderChatBody(message) { if (message.body) return `<div class="chat-message-body">${escapeChat(message.body)}</div>`; if (!message.media_url && message.message_type === 'unknown') return '<div class="chat-message-body text-muted">Mensagem nao decifrada</div>'; return ''; }
function loadChatContacts() {
    const params = new URLSearchParams({q: document.getElementById('chat-search').value, chat_type: document.getElementById('chat-filter').value});
    fetch(`/instances/${instanceId}/chat/contacts?${params}`).then(r => r.json()).then(data => {
        const box = document.getElementById('chat-contacts'), items = data.data?.contacts || [];
        box.innerHTML = items.length ? items.map(c => `<button class="chat-contact-item ${c.jid === document.getElementById('chat-selected-jid').value ? 'active' : ''}" onclick="selectChat('${escapeChat(c.jid)}', '${escapeChat(c.chat_type)}', this)"><span class="chat-avatar">${c.chat_type === 'group' ? '<i class="fas fa-users"></i>' : c.chat_type === 'newsletter' ? '<i class="fas fa-bullhorn"></i>' : '<i class="fas fa-user"></i>'}</span><span class="chat-contact-copy"><strong>${escapeChat(c.name || c.jid)}</strong><small>${escapeChat(c.last_message || c.jid)}</small></span><time>${escapeChat(c.last_at || '')}</time></button>`).join('') : '<div class="text-center text-muted py-5 px-3">Nenhuma conversa nesta aba.</div>';
        if (!document.getElementById('chat-selected-jid').value && items[0]) selectChat(items[0].jid, items[0].chat_type, box.querySelector('.chat-contact-item'));
    });
}
function selectChat(jid, type, button) {
    document.querySelectorAll('.chat-contact-item').forEach(item => item.classList.remove('active')); if (button) button.classList.add('active');
    document.getElementById('chat-selected-jid').value = jid; document.getElementById('chat-to').value = jid; document.getElementById('chat-type').value = ['user', 'group', 'newsletter'].includes(type) ? type : 'user'; document.getElementById('chat-conversation-header').innerHTML = `<span class="chat-avatar"><i class="fas ${type === 'group' ? 'fa-users' : type === 'newsletter' ? 'fa-bullhorn' : 'fa-user'}"></i></span><div><strong>${escapeChat(jid)}</strong><small>${escapeChat(type)}</small></div>`; fetch(`/instances/${instanceId}/chat/read`, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({jid})}).catch(() => {}); loadInstanceChat();
}
function loadInstanceChat() {
    const jid = document.getElementById('chat-selected-jid').value; if (!jid) return;
    const params = new URLSearchParams({q: '', chat_type: document.getElementById('chat-filter').value, jid, limit: '100'});
    fetch(`/instances/${instanceId}/chat?${params}`).then(r => r.json()).then(data => {
        const box = document.getElementById('chat-messages'); const items = data.data?.messages || [];
        box.innerHTML = items.length ? items.map(m => `<div class="chat-message-row ${m.direction === 'outbound' ? 'outbound' : 'inbound'}"><div class="chat-message-meta">${escapeChat(m.direction === 'outbound' ? m.to_jid : m.from_jid)} · ${escapeChat(m.created_at)}</div><div class="chat-bubble"><div class="chat-message-type">${escapeChat(m.message_type)}</div>${renderChatMedia(m)}${renderChatBody(m)}<div class="chat-message-status">${escapeChat(m.status)}</div></div></div>`).join('') : '<div class="text-center text-muted py-5">Nenhuma mensagem encontrada nesta aba.</div>';
        box.scrollTop = box.scrollHeight;
    }).catch(() => { document.getElementById('chat-messages').innerHTML = '<div class="text-center text-danger py-5">Nao foi possivel carregar esta conversa.</div>'; });
}
document.getElementById('chat-form').addEventListener('submit', function (event) {
    event.preventDefault(); const mediaUrl = document.getElementById('chat-media-url').value.trim();
    fetch(`/instances/${instanceId}/chat/send`, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({to:document.getElementById('chat-to').value, chat_type:document.getElementById('chat-type').value, text:document.getElementById('chat-text').value, media_url:mediaUrl || undefined, media_type:document.getElementById('chat-media-type').value})}).then(r=>r.json()).then(data => { if (!data.success) return Swal.fire('Erro', data.error || 'Falha ao enviar', 'error'); document.getElementById('chat-text').value=''; document.getElementById('chat-media-url').value=''; loadInstanceChat(); Swal.fire({toast:true,position:'top-end',icon:'success',title:'Mensagem na fila',showConfirmButton:false,timer:1800}); });
});
document.getElementById('instanceChat').addEventListener('hidden.bs.offcanvas', stopChatRefresh);

function setStatus(status) {
    const el = document.getElementById('instance-status');
    el.innerText = status;
    let cls = 'badge-gray fs-5 px-3 py-2';
    if (status === 'connected') cls = 'badge-green fs-5 px-3 py-2';
    if (status === 'connecting' || status === 'waiting_qr') cls = 'badge-yellow fs-5 px-3 py-2';
    if (status === 'error') cls = 'badge-purple fs-5 px-3 py-2';
    el.className = cls;
    setSendEnabled(status === 'connected');
    setConnectionButtons(status);
}

function setSendEnabled(enabled) {
    ['test-to', 'test-msg', 'test-chat-type'].forEach(id => {
        const field = document.getElementById(id);
        if (field) field.disabled = !enabled;
    });
    const button = document.querySelector('#test-msg-form button');
    if (button) button.disabled = !enabled;
}

function setConnectionButtons(status) {
    const isConnected = status === 'connected';
    const qrButton = document.getElementById('btn-connect');
    const pinButton = document.getElementById('btn-connect-pin');
    const disconnectButton = document.getElementById('btn-disconnect');

    if (qrButton) qrButton.classList.toggle('d-none', isConnected);
    if (pinButton) pinButton.classList.toggle('d-none', isConnected);
    if (disconnectButton) disconnectButton.classList.toggle('d-none', !isConnected);
}

function resetConnectionModal(mode) {
    connectionMode = mode;
    document.getElementById('qr-wrapper').style.display = 'none';
    document.getElementById('pin-wrapper').style.display = 'none';
    document.getElementById('qr-spinner').style.display = 'inline-block';
    document.getElementById('qr-status-text').innerText = mode === 'pin'
        ? 'Gerando PIN Code...'
        : 'Iniciando worker e gerando QR Code...';
    document.getElementById('pairing-code').innerText = '';
}

function promptPinConnection() {
    Swal.fire({
        title: 'Conectar com PIN Code',
        input: 'tel',
        inputLabel: 'Numero com DDI e DDD',
        inputPlaceholder: 'Ex: 5511999999999',
        showCancelButton: true,
        confirmButtonText: 'Gerar PIN',
        cancelButtonText: 'Cancelar',
        inputValidator: (value) => {
            const phone = String(value || '').replace(/\D+/g, '');
            if (phone.length < 10 || phone.length > 15) {
                return 'Informe um numero valido com DDI.';
            }
            return undefined;
        }
    }).then((result) => {
        if (!result.isConfirmed) return;
        connectInstance('pin', result.value);
    });
}

function connectInstance(mode = 'qr', phoneNumber = null) {
    resetConnectionModal(mode);
    const payload = { mode };
    if (phoneNumber) payload.phone_number = phoneNumber;

    document.getElementById('qr-wrapper').style.display = 'none';
    qrModal.show();

    fetch(`/instances/${instanceId}/connect`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                setStatus('connecting');
                const pairingCode = data.data?.pairing_code || data.worker_response?.data?.pairing_code || null;
                if (mode === 'pin' && pairingCode) {
                    document.getElementById('qr-spinner').style.display = 'none';
                    document.getElementById('pin-wrapper').style.display = 'block';
                    document.getElementById('pairing-code').innerText = pairingCode;
                    document.getElementById('qr-status-text').innerText = 'Digite o PIN Code no WhatsApp do numero informado.';
                } else if (mode === 'pin') {
                    document.getElementById('qr-status-text').innerText = 'Conexao iniciada usando a sessao existente.';
                }
                startPolling();
            } else {
                qrModal.hide();
                setStatus('error');
                Swal.fire('Erro', data.error || 'Falha ao iniciar conexao', 'error');
            }
        })
        .catch(() => {
            qrModal.hide();
            setStatus('error');
            Swal.fire('Erro', 'Erro de rede ao chamar o worker', 'error');
        });
}

function disconnectInstance() {
    fetch(`/instances/${instanceId}/disconnect`, { method: 'POST' })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                Swal.fire('Desconectado', 'A instancia foi desconectada.', 'success');
                setStatus('disconnected');
                stopPolling();
            }
        });
}

function startPolling() {
    if (pollInterval) clearInterval(pollInterval);
    pollInterval = setInterval(pollStatus, 3000);
}

function stopPolling() {
    if (pollInterval) clearInterval(pollInterval);
}

function pollStatus() {
    fetch(`/instances/${instanceId}/qr`)
        .then(res => res.json())
        .then(data => {
            if (data.status) setStatus(data.status);
            if(data.status === 'waiting_qr' && data.qr && connectionMode !== 'pin') {
                document.getElementById('qr-status-text').innerText = 'Escaneie o QR Code com o seu WhatsApp';
                document.getElementById('qr-spinner').style.display = 'none';
                document.getElementById('qr-wrapper').style.display = 'block';
                document.getElementById('qr-image').src = data.qr;
            } else if (data.status === 'connected') {
                qrModal.hide();
                stopPolling();
                Swal.fire('Conectado', 'WhatsApp conectado com sucesso.', 'success');
            } else if (data.status === 'error') {
                document.getElementById('qr-spinner').style.display = 'none';
                document.getElementById('qr-status-text').innerText = 'A conexao retornou erro. Veja os logs desta instancia.';
                stopPolling();
            }
        });
}

function copyPairingCode() {
    const code = document.getElementById('pairing-code').innerText;
    if (!code) return;

    if (!navigator.clipboard) {
        Swal.fire('PIN Code', code, 'info');
        return;
    }

    navigator.clipboard.writeText(code)
        .then(() => Swal.fire('Copiado', 'PIN Code copiado.', 'success'))
        .catch(() => Swal.fire('PIN Code', code, 'info'));
}

function sendTestMessage() {
    const to = document.getElementById('test-to').value;
    const msg = document.getElementById('test-msg').value;
    const chatType = document.getElementById('test-chat-type').value;

    if (!to || !msg) {
        Swal.fire('Erro', 'Preencha o destino e a mensagem.', 'error');
        return;
    }

    if ((chatType === 'group' || chatType === 'newsletter') && !to.includes('@')) {
        Swal.fire('Erro', 'Para grupo ou newsletter, informe o JID completo.', 'error');
        return;
    }

    fetch(`/instances/${instanceId}/send-test`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ to: to, message: msg, chat_type: chatType })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Na fila', 'Mensagem adicionada a fila com sucesso.', 'success');
            document.getElementById('test-msg').value = '';
        } else {
            Swal.fire('Erro', data.error || 'Falha ao enviar mensagem', 'error');
        }
    })
    .catch(() => Swal.fire('Erro', 'Erro de conexao', 'error'));
}

function deleteInstance() {
    Swal.fire({
        title: 'Excluir instancia?',
        text: 'Esta acao remove a instancia e os dados vinculados a ela.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Excluir',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#dc3545'
    }).then((result) => {
        if (!result.isConfirmed) return;

        fetch(`/instances/${instanceId}/delete`, { method: 'POST' })
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

function shareInstance(event) {
    event.preventDefault();
    const identity = document.getElementById('share-identity').value.trim();
    if (!identity) return;

    fetch(`/instances/${instanceId}/shares`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ identity })
    })
        .then(res => res.json())
        .then(data => {
            if (!data.success) throw new Error(data.error || 'Nao foi possivel compartilhar.');
            Swal.fire('Compartilhada', `A instancia foi compartilhada com ${data.user.name}.`, 'success')
                .then(() => window.location.reload());
        })
        .catch(error => Swal.fire('Erro', error.message, 'error'));
}

function revokeInstanceShare(userId, name) {
    Swal.fire({
        title: 'Revogar acesso?',
        text: `${name} deixara de acessar esta instancia.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Revogar',
        cancelButtonText: 'Cancelar'
    }).then(result => {
        if (!result.isConfirmed) return;
        fetch(`/instances/${instanceId}/shares/revoke`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: userId })
        })
            .then(res => res.json())
            .then(data => {
                if (!data.success) throw new Error(data.error || 'Nao foi possivel revogar.');
                window.location.reload();
            })
            .catch(error => Swal.fire('Erro', error.message, 'error'));
    });
}
</script>
<?php $scripts = ob_get_clean(); ?>
