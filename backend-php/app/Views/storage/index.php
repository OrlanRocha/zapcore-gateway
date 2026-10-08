<?php
$formatBytes = static function (int $bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2, ',', '.') . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2, ',', '.') . ' KB';
    return $bytes . ' B';
};
$absoluteBytes = (int) ($settings['absolute_bytes'] ?? 1073741824);
$absoluteUnit = $absoluteBytes >= 1073741824 ? 'GB' : 'MB';
$absoluteValue = $absoluteBytes / ($absoluteUnit === 'GB' ? 1073741824 : 1048576);
?>
<section class="storage-page py-4" data-storage-page>
    <header class="storage-heading">
        <div>
            <p class="storage-eyebrow">Administracao</p>
            <h2>Armazenamento e retencao</h2>
            <p>Monitore o volume de midias e controle a limpeza automatica sem remover o historico das mensagens.</p>
        </div>
        <div class="storage-schedule-state <?= !empty($settings['enabled']) ? 'is-active' : '' ?>">
            <i class="fas fa-clock" aria-hidden="true"></i>
            <span><?= !empty($settings['enabled']) ? 'Agendamento ativo' : 'Agendamento pausado' ?></span>
        </div>
    </header>

    <div class="storage-metrics" aria-label="Metricas de armazenamento">
        <div><span>Particao usada</span><strong><?= number_format((float) $metrics['partition_used_percent'], 1, ',', '.') ?>%</strong><small><?= $formatBytes((int) $metrics['partition_used_bytes']) ?> de <?= $formatBytes((int) $metrics['partition_total_bytes']) ?></small></div>
        <div><span>Espaco livre</span><strong><?= $formatBytes((int) $metrics['partition_free_bytes']) ?></strong><small>No volume da aplicacao</small></div>
        <div><span>Midias armazenadas</span><strong><?= $formatBytes((int) $metrics['media_bytes']) ?></strong><small><?= number_format((int) $metrics['media_files'], 0, ',', '.') ?> arquivos</small></div>
        <div><span>Proxima verificacao</span><strong><?= $nextRunAt ? htmlspecialchars($nextRunAt->format('d/m H:i')) : 'Pausada' ?></strong><small>Ultima: <?= !empty($settings['last_run_at']) ? htmlspecialchars(date('d/m/Y H:i', strtotime($settings['last_run_at']))) : 'nunca executada' ?></small></div>
    </div>

    <div class="storage-layout">
        <section class="storage-settings" aria-labelledby="storage-settings-title">
            <div class="storage-section-title">
                <div><h3 id="storage-settings-title">Politica de retencao</h3><p>Arquivos mais antigos sao removidos primeiro. Mensagens permanecem no historico.</p></div>
                <i class="fas fa-sliders-h" aria-hidden="true"></i>
            </div>
            <form id="storage-settings-form">
                <label class="storage-toggle"><input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?>><span></span><strong>Executar limpeza automatica</strong></label>

                <div class="storage-field">
                    <span class="storage-label">Modo de limite</span>
                    <div class="storage-segmented" role="radiogroup">
                        <label><input type="radio" name="mode" value="percent" <?= $settings['mode'] === 'percent' ? 'checked' : '' ?>><span><i class="fas fa-percent"></i> Particao</span></label>
                        <label><input type="radio" name="mode" value="absolute" <?= $settings['mode'] === 'absolute' ? 'checked' : '' ?>><span><i class="fas fa-database"></i> Midias em MB/GB</span></label>
                    </div>
                </div>

                <div class="storage-mode-fields" data-mode="percent">
                    <label>Iniciar ao atingir (%)<input class="form-control" type="number" name="percent_threshold" min="1" max="99" value="<?= (int) ($settings['percent_threshold'] ?? 80) ?>"></label>
                    <label>Limpar ate (%)<input class="form-control" type="number" name="percent_target" min="0" max="98" value="<?= (int) ($settings['percent_target'] ?? 75) ?>"></label>
                </div>
                <div class="storage-mode-fields" data-mode="absolute">
                    <label>Limite de midias<input class="form-control" type="number" name="absolute_value" min="0.01" step="0.01" value="<?= htmlspecialchars(rtrim(rtrim(number_format($absoluteValue, 2, '.', ''), '0'), '.')) ?>"></label>
                    <label>Unidade<select class="form-select" name="absolute_unit"><option value="MB" <?= $absoluteUnit === 'MB' ? 'selected' : '' ?>>MB</option><option value="GB" <?= $absoluteUnit === 'GB' ? 'selected' : '' ?>>GB</option></select></label>
                </div>

                <label class="storage-field">Intervalo<select class="form-select" name="interval_minutes">
                    <?php foreach ([5 => '5 minutos', 15 => '15 minutos', 30 => '30 minutos', 60 => '1 hora', 360 => '6 horas', 1440 => '24 horas'] as $minutes => $label): ?>
                        <option value="<?= $minutes ?>" <?= (int) $settings['interval_minutes'] === $minutes ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select></label>
                <button class="btn btn-dark" type="submit"><i class="fas fa-save"></i> Salvar configuracao</button>
            </form>
        </section>

        <aside class="storage-actions" aria-labelledby="storage-actions-title">
            <div class="storage-section-title"><div><h3 id="storage-actions-title">Execucao manual</h3><p>Confira o impacto antes de remover arquivos.</p></div><i class="fas fa-broom"></i></div>
            <button class="btn btn-outline-dark" type="button" data-storage-action="dry-run"><i class="fas fa-flask"></i> Simular limpeza</button>
            <button class="btn btn-danger" type="button" data-storage-action="cleanup"><i class="fas fa-trash-alt"></i> Limpar agora</button>
            <div class="storage-action-result" data-storage-result hidden></div>
            <dl class="storage-last-status">
                <div><dt>Ultimo estado</dt><dd><?= htmlspecialchars($settings['last_run_status'] ?? 'sem execucao') ?></dd></div>
                <div><dt>Diretorio</dt><dd title="<?= htmlspecialchars($metrics['storage_root']) ?>"><?= htmlspecialchars($metrics['storage_root']) ?></dd></div>
            </dl>
        </aside>
    </div>

    <section class="storage-history" aria-labelledby="storage-history-title">
        <div class="storage-section-title"><div><h3 id="storage-history-title">Historico de execucoes</h3><p>Auditoria das simulacoes, rotinas e limpezas manuais.</p></div><i class="fas fa-history"></i></div>
        <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Inicio</th><th>Origem</th><th>Modo</th><th>Selecionado</th><th>Removido</th><th>Estado</th></tr></thead><tbody>
        <?php if (!$history): ?><tr><td colspan="6" class="text-muted text-center py-4">Nenhuma execucao registrada.</td></tr><?php endif; ?>
        <?php foreach ($history as $run): ?><tr>
            <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($run['started_at']))) ?></td>
            <td><?= htmlspecialchars($run['triggered_by']) ?></td>
            <td><?= htmlspecialchars($run['mode']) ?></td>
            <td><?= (int) $run['files_selected'] ?> / <?= $formatBytes((int) $run['bytes_selected']) ?></td>
            <td><?= (int) $run['files_deleted'] ?> / <?= $formatBytes((int) $run['bytes_deleted']) ?></td>
            <td><span class="storage-status storage-status-<?= htmlspecialchars($run['status']) ?>"><?= htmlspecialchars($run['status']) ?></span></td>
        </tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</section>
<?php ob_start(); ?>
<script>
(() => {
    const page = document.querySelector('[data-storage-page]');
    if (!page) return;
    const form = document.getElementById('storage-settings-form');
    const result = page.querySelector('[data-storage-result]');
    const syncMode = () => {
        const mode = form.elements.mode.value;
        page.querySelectorAll('[data-mode]').forEach(group => {
            const active = group.dataset.mode === mode;
            group.hidden = !active;
            group.querySelectorAll('input, select').forEach(field => field.disabled = !active);
        });
    };
    form.querySelectorAll('[name="mode"]').forEach(input => input.addEventListener('change', syncMode));
    syncMode();

    const request = async (url, options = {}) => {
        const response = await fetch(url, { method: 'POST', headers: { 'Accept': 'application/json' }, ...options });
        const payload = await response.json();
        if (!response.ok || !payload.success) throw new Error(payload.error || 'Falha na operacao');
        return payload;
    };
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            await request('/storage/settings', { body: new FormData(form) });
            await Swal.fire({ icon: 'success', title: 'Configuracao salva', timer: 1400, showConfirmButton: false });
            location.reload();
        } catch (error) { Swal.fire({ icon: 'error', title: 'Nao foi possivel salvar', text: error.message }); }
        finally { button.disabled = false; }
    });
    page.querySelectorAll('[data-storage-action]').forEach(button => button.addEventListener('click', async () => {
        const action = button.dataset.storageAction;
        if (action === 'cleanup') {
            const confirmation = await Swal.fire({ icon: 'warning', title: 'Remover midias antigas?', text: 'As mensagens permanecerao no historico.', showCancelButton: true, confirmButtonText: 'Limpar agora', cancelButtonText: 'Cancelar', confirmButtonColor: '#c0392b' });
            if (!confirmation.isConfirmed) return;
        }
        button.disabled = true;
        try {
            const payload = await request('/storage/' + action);
            const data = payload.data;
            result.hidden = false;
            result.textContent = `${action === 'dry-run' ? 'Simulacao' : 'Limpeza'}: ${data.files_selected || 0} arquivo(s) selecionado(s), ${data.deletedFiles || data.deleted_files || 0} removido(s).`;
            if (action === 'cleanup') setTimeout(() => location.reload(), 1200);
        } catch (error) { Swal.fire({ icon: 'error', title: 'Falha na retencao', text: error.message }); }
        finally { button.disabled = false; }
    }));
})();
</script>
<?php $scripts = ob_get_clean(); ?>
