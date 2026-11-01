<?php /* v6.4.0-beta：AI 写作侧边浮层 + 隐私确认（原 sc.php 第 880-946 行；由 sc.php 按存在性 include） */ ?>
<?php if ($aiToolEnabled): ?>
<!-- ===== v5.4.0-beta：AI 写作侧边浮层（选中文本 → 动作 → 流式 → 差异预览 → 插入/替换/撤销） ===== -->
<div class="ai-drawer" id="aiDrawer" style="display:none">
    <div class="ai-drawer-head">
        <span class="ai-drawer-title"><?= t('admin.479') ?></span>
        <button type="button" class="ai-drawer-close" id="aiDrawerClose" aria-label="<?= t('ai.002') ?>">&times;</button>
    </div>
    <div class="ai-drawer-body">
        <div class="ai-group">
            <div class="ai-group-title"><?= t('ai.003') ?></div>
            <div class="ai-field">
                <label class="ai-label"><?= t('ai.004') ?></label>
                <div class="ai-actions" id="aiActionChips"></div>
            </div>
            <div class="ai-field" id="aiStyleWrap" style="display:none">
                <label class="ai-label"><?= t('ai.005') ?></label>
                <select class="ai-select" id="aiStyle"></select>
            </div>
            <div class="ai-field" id="aiLangWrap" style="display:none">
                <label class="ai-label"><?= t('ai.006') ?></label>
                <select class="ai-select" id="aiLang"></select>
            </div>
        </div>
        <div class="ai-group">
            <div class="ai-group-title"><?= t('ai.007') ?></div>
            <div class="ai-field">
                <label class="ai-label"><?= t('ai.008') ?></label>
                <select class="ai-select" id="aiProvider"></select>
                <div class="ai-hint" id="aiProviderHint"></div>
            </div>
            <div class="ai-field">
                <label class="ai-label"><?= t('ai.009') ?></label>
                <div class="ai-src" id="aiSrc"></div>
            </div>
        </div>
        <div class="ai-run-row">
            <button type="button" class="ai-btn ai-btn-primary" id="aiRunBtn"><?= t('ai.010') ?></button>
            <button type="button" class="ai-btn" id="aiCancelBtn" style="display:none"><?= t('ai.011') ?></button>
            <button type="button" class="ai-btn" id="aiRetryBtn" style="display:none"><?= t('ai.012') ?></button>
            <span class="ai-status" id="aiStatus"></span>
        </div>
        <div class="ai-field ai-result" id="aiResultWrap" style="display:none">
            <div class="ai-diff">
                <div class="ai-diff-col"><div class="ai-diff-title"><?= t('ai.013') ?></div><div class="ai-diff-body" id="aiDiffOld"></div></div>
                <div class="ai-diff-col"><div class="ai-diff-title"><?= t('ai.014') ?></div><div class="ai-diff-body" id="aiDiffNew"></div></div>
            </div>
            <div class="ai-apply-row">
                <button type="button" class="ai-btn" id="aiInsertBtn"><?= t('ai.015') ?></button>
                <button type="button" class="ai-btn ai-btn-primary" id="aiReplaceBtn"><?= t('ai.016') ?></button>
                <button type="button" class="ai-btn" id="aiUndoBtn" style="display:none"><?= t('ai.017') ?></button>
            </div>
        </div>
    </div>
</div>
<div class="ai-privacy-mask" id="aiPrivacyMask" style="display:none">
    <div class="ai-privacy-box">
        <h3><?= t('ai.018') ?></h3>
        <p><?= t('ai.019') ?><b><?= t('ai.063') ?></b><?= t('ai.020') ?></p>
        <p><?= t('ai.021') ?></p>
        <p class="ai-privacy-ver"><?= t('ai.022') ?><b id="aiPrivacyVer"><?= htmlspecialchars(aiPrivacyVersion()) ?></b><?= t('ai.023') ?></p>
        <div class="ai-apply-row" style="justify-content:flex-end">
            <button type="button" class="ai-btn" id="aiPrivacyCancel"><?= t('ai.011') ?></button>
            <button type="button" class="ai-btn ai-btn-primary" id="aiPrivacyOk"><?= t('ai.024') ?></button>
        </div>
    </div>
</div>
<script>window.YSM_AI_CFG = {configUrl: <?= json_encode($aiConfigUrl) ?>};</script>
<?php endif; ?>
