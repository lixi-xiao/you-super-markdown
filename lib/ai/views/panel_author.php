<?php /* v6.4.0-beta：AI 面板（原 author/dashboard.php 第 411-429 行；由 dashboard 按存在性 include） */ ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M12 2l2.4 5.2L20 9l-4 4 1 6-5-2.8L7 19l1-6-4-4 5.6-1.8z"/></svg>
            <?= t('ai.067') ?>
        </div>
        <div class="page-subtitle"><?= t('ai.058') ?></div>
    </div>
    <div id="aiKeyNotice" class="msg" style="display:none"></div>
    <div class="card">
        <div class="card-title"><?= t('ai.059') ?></div>
        <div class="form-hint">
            <?= t('ai.083') ?><br>
            <?= t('ai.060') ?><b><?= t('ai.061') ?></b><?= t('ai.062') ?>
        </div>
    </div>
    <div id="aiKeyManager" data-csrf="<?= htmlspecialchars(generateCsrfToken()) ?>"></div>
    <!-- v5.4.0-beta.4：AI Key 管理请求需带 X-Fp（本页不加载 main.js），先引入公共指纹 -->
    <script src="../js/fp.js?v=<?= @filemtime(YSM_ROOT . '/js/fp.js') ?>"></script>
    <script src="../js/ai-admin.js?v=<?= @filemtime(YSM_ROOT . '/js/ai-admin.js') ?>"></script>
