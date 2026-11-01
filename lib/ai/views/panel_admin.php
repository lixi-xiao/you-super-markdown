<?php /* v6.4.0-beta：AI 面板（原 admin/dashboard.php 第 3598-3745 行；由 dashboard 按存在性 include） */ ?>
    <?php
    // v5.4.0-beta：AI 写作（超管只管"站"的层面）
    //   总开关 + 可分别开关"站长/写作者可用" + 维护服务商预设白名单；超管不接触任何人的 Key。
    $aiBuiltin = aiBuiltinProviders();
    $aiEnabledIds = aiEnabledProviderIds();
    $aiCandidates = array_values(array_filter($users, fn($u) => in_array($u['role'] ?? '', [ROLE_STATION_ADMIN, ROLE_AUTHOR], true)));
    // v5.4.3-beta.2：模型与长度清单（超管维护；字段缺失回落内置预设）
    $aiCatalog = aiModelsConfig();
    ?>
    <div class="ai-admin">
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M12 2l2.4 5.2L20 9l-4 4 1 6-5-2.8L7 19l1-6-4-4 5.6-1.8z"/></svg>
            <?= t('ai.067') ?>
        </div>
        <div class="page-subtitle"><?= t('ai.025') ?></div>
    </div>
    <?php if ($msg === 'ai_cleared'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= t('ai.026') ?></div><?php endif; ?>
    <?php if ($msg === 'ai_models_saved'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= t('ai.027') ?></div><?php endif; ?>
    <?php if ($msg === 'challenge_failed'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= t('ai.066') ?></div><?php endif; ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M12 2l2.4 5.2L20 9l-4 4 1 6-5-2.8L7 19l1-6-4-4 5.6-1.8z"/></svg>
            <?= t('ai.084') ?>
        </div>
        <div class="form-hint" style="margin-bottom:12px">
            <?= t('ai.085') ?>
            <?= t('ai.086') ?>
        </div>
        <form method="post" class="need-challenge">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="save_ai_config" value="1">
            <input type="hidden" name="challenge_code">
            <div class="ai-toggle-card is-primary">
                <input type="checkbox" name="ai_enabled" id="aiEnabled" value="1" <?= !empty($config['ai_enabled']) ? 'checked' : '' ?>>
                <div>
                    <label for="aiEnabled" class="ai-toggle-label"><?= t('ai.028') ?></label>
                    <div class="form-hint" style="margin:0"><?= t('ai.029') ?></div>
                </div>
            </div>
            <div class="ai-toggle-card">
                <input type="checkbox" name="ai_role_station_admin" id="aiRoleSt" value="1" <?= !empty($config['ai_role_station_admin']) ? 'checked' : '' ?>>
                <div>
                    <label for="aiRoleSt" class="ai-toggle-label"><?= t('ai.030') ?></label>
                    <div class="form-hint" style="margin:0"><?= t('ai.031') ?></div>
                </div>
            </div>
            <div class="ai-toggle-card">
                <input type="checkbox" name="ai_role_author" id="aiRoleAuthor" value="1" <?= !empty($config['ai_role_author']) ? 'checked' : '' ?>>
                <div>
                    <label for="aiRoleAuthor" class="ai-toggle-label"><?= t('ai.032') ?></label>
                    <div class="form-hint" style="margin:0"><?= t('ai.033') ?></div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label"><?= t('ai.034') ?></label>
                <div class="table-wrap ai-provider-table">
                <table>
                    <tr><th style="width:60px"><?= t('ai.035') ?></th><th><?= t('ai.008') ?></th><th><?= t('ai.037') ?></th></tr>
                    <?php foreach ($aiBuiltin as $pid => $p): ?>
                    <tr>
                        <td><input type="checkbox" name="ai_providers[]" value="<?= htmlspecialchars($pid) ?>" <?= in_array($pid, $aiEnabledIds, true) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--accent)"></td>
                        <td><?= htmlspecialchars($p['label']) ?></td>
                        <td><code style="font-size:0.85em"><?= htmlspecialchars($p['base_url']) ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                </div>
                <div class="form-hint"><?= t('ai.038') ?></div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:12px">
                <button type="submit" class="btn btn-primary"><?= t('ai.039') ?></button>
            </div>
        </form>
    </div>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M12 2l2.4 5.2L20 9l-4 4 1 6-5-2.8L7 19l1-6-4-4 5.6-1.8z"/></svg>
            <?= t('ai.087') ?>
        </div>
        <div class="form-hint" style="margin-bottom:12px">
            <?= t('ai.040') ?><b><?= t('ai.064') ?></b>）。<br>
            <?= t('ai.088') ?>
        </div>
        <form method="post" class="need-challenge" id="aiModelsForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="save_ai_models" value="1">
            <input type="hidden" name="challenge_code">
            <?php foreach ($aiBuiltin as $pid => $p): $rows = isset($aiCatalog[$pid]) ? array_values($aiCatalog[$pid]) : []; ?>
            <div class="ai-model-provider">
                <div class="ai-model-provider-head">
                    <span class="ai-model-provider-name"><?= htmlspecialchars($p['label']) ?></span>
                    <button type="button" class="btn btn-sm btn-outline" onclick="aiModelAddRow('<?= htmlspecialchars($pid) ?>')"><?= t('ai.065') ?></button>
                </div>
                <div class="table-wrap">
                <table class="ai-model-table">
                    <thead><tr><th><?= t('ai.042') ?></th><th><?= t('ai.043') ?></th><th><?= t('ai.044') ?></th><th><?= t('ai.045') ?></th><th><?= t('ai.046') ?></th><th><?= t('ai.047') ?></th><th style="width:70px"></th></tr></thead>
                    <tbody data-provider="<?= htmlspecialchars($pid) ?>">
                    <?php foreach ($rows as $ri => $m): ?>
                    <tr>
                        <td><input class="form-input" name="ai_model_catalog[<?= htmlspecialchars($pid) ?>][<?= (int)$ri ?>][id]" value="<?= htmlspecialchars($m['id']) ?>" placeholder="<?= t('ai.048') ?>"></td>
                        <td><input class="form-input" name="ai_model_catalog[<?= htmlspecialchars($pid) ?>][<?= (int)$ri ?>][label]" value="<?= htmlspecialchars($m['label']) ?>" placeholder="<?= t('ai.043') ?>"></td>
                        <td><input class="form-input" type="number" min="0" name="ai_model_catalog[<?= htmlspecialchars($pid) ?>][<?= (int)$ri ?>][max_input_chars]" value="<?= (int)$m['max_input_chars'] ?>"></td>
                        <td><input class="form-input" type="number" min="0" name="ai_model_catalog[<?= htmlspecialchars($pid) ?>][<?= (int)$ri ?>][max_out_tokens]" value="<?= (int)$m['max_out_tokens'] ?>"></td>
                        <td><input class="form-input" type="number" min="0" name="ai_model_catalog[<?= htmlspecialchars($pid) ?>][<?= (int)$ri ?>][timeout]" value="<?= (int)$m['timeout'] ?>"></td>
                        <td><input class="form-input" type="number" min="0" name="ai_model_catalog[<?= htmlspecialchars($pid) ?>][<?= (int)$ri ?>][timeout_stream]" value="<?= (int)$m['timeout_stream'] ?>"></td>
                        <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove()"><?= t('ai.049') ?></button></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php endforeach; ?>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:12px">
                <button type="submit" class="btn btn-primary"><?= t('ai.050') ?></button>
            </div>
        </form>
    </div>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            <?= t('ai.089') ?>
        </div>
        <div class="form-hint" style="margin-bottom:12px">
            <?= t('ai.051') ?><b><?= t('ai.052') ?></b><?= t('ai.053') ?>
        </div>
        <form method="post" class="need-challenge" data-confirm="<?= t('ai.054') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="ai_clear_user" id="aiClearUser" value="">
            <input type="hidden" name="challenge_code">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label"><?= t('ai.055') ?></label>
                    <select class="form-select" onchange="document.getElementById('aiClearUser').value=this.value">
                        <option value=""><?= t('ai.056') ?></option>
                        <?php foreach ($aiCandidates as $cu): ?>
                        <option value="<?= htmlspecialchars($cu['id']) ?>"><?= htmlspecialchars(($cu['nickname'] ?? '') . '（' . ($cu['account'] ?? '') . '，' . ($cu['role'] ?? '') . '）') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:12px">
                <button type="submit" class="btn btn-danger"><?= t('ai.057') ?></button>
            </div>
        </form>
    </div>
    </div><!-- /ai-admin -->
