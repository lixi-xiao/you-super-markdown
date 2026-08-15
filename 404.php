<?php
http_response_code(404);
require_once __DIR__ . '/utils.php';
$_cfg404 = loadSiteConfig();
$_siteTitle404 = $_cfg404['site_title'] ?? 'You Super Markdown';
$_bgType404 = $_cfg404['bg_type'] ?? 'none';
$_bgImage404 = $_cfg404['bg_image'] ?? '';
$_bgApiUrl404 = $_cfg404['bg_api_url'] ?? '';
$_bgBlur404 = !empty($_cfg404['bg_blur_enabled']) ? '1' : '0';
$_bgBlurLevel404 = intval($_cfg404['bg_blur_level'] ?? 0);
$_bgCardOpacity404 = intval($_cfg404['bg_card_opacity'] ?? 100);
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - 页面未找到 · <?= htmlspecialchars($_siteTitle404) ?></title>
    <!-- v4.0.0：深色模式跟随系统——在 CSS 加载前同步设置 data-theme，避免闪白（与主站一致） -->
    <script>
        (function() {
            try {
                var saved = localStorage.getItem('md-theme');
                var dark = saved ? saved === 'dark'
                    : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
            } catch (e) {}
        })();
    </script>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📝</text></svg>" type="image/svg+xml">
    <!-- v4.1.0：资源使用绝对路径——未知路径（如 /xxx/yyy/）下相对路径会解析到错误位置导致样式加载失败 -->
    <link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>">
</head>
<body data-bg-type="<?= htmlspecialchars($_bgType404) ?>" data-bg-image="<?= htmlspecialchars($_bgImage404) ?>" data-bg-api-url="<?= htmlspecialchars($_bgApiUrl404) ?>" data-bg-blur="<?= htmlspecialchars($_bgBlur404) ?>" data-bg-blur-level="<?= $_bgBlurLevel404 ?>" data-bg-card-opacity="<?= $_bgCardOpacity404 ?>" style="padding-left:0">
<script>
    // v4.1.0：404 页无侧边栏，覆盖 style.css 宽屏 body{padding-left:280px}（否则整页右移 280px 不居中）
    // v4.0.0：背景应用（与 js/main.js applyBg 同一逻辑，404 页不加载 main.js 故内联）
    (function() {
        var body = document.body;
        var bgType = body.dataset.bgType || 'none';
        var bgImage = body.dataset.bgImage || '';
        var bgApiUrl = body.dataset.bgApiUrl || '';
        var bgBlur = body.dataset.bgBlur === '1';
        var bgBlurLevel = parseInt(body.dataset.bgBlurLevel) || 0;
        var bgCardOpacity = body.dataset.bgCardOpacity !== undefined ? parseInt(body.dataset.bgCardOpacity) : 100;
        body.style.setProperty('--bg-card-opacity', (bgCardOpacity / 100));
        if (bgBlur && bgBlurLevel > 0) {
            body.classList.add('bg-blur');
            body.style.setProperty('--bg-blur-level', bgBlurLevel + 'px');
        }
        if (bgType === 'image' && bgImage) {
            body.classList.add('bg-active');
            var bgUrl = (bgImage.indexOf('/') === 0 || /^https?:/i.test(bgImage)) ? bgImage : '/' + bgImage;
            body.style.setProperty('--bg-url', 'url(' + bgUrl + ')');
        } else if (bgType === 'api' && bgApiUrl) {
            body.classList.add('bg-active');
            var apiUrl = (bgApiUrl.indexOf('/') === 0 || /^https?:/i.test(bgApiUrl)) ? bgApiUrl : '/' + bgApiUrl;
            body.style.setProperty('--bg-url', 'url(' + apiUrl + ')');
        }
    })();
</script>
<div class="nf-page">
    <div class="nf-card">
        <div class="nf-code" aria-hidden="true">404</div>
        <div class="nf-icon">
            <svg viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/>
                <path d="M16 16s-1.5-2-4-2-4 2-4 2"/>
                <line x1="9" y1="9" x2="9.01" y2="9"/>
                <line x1="15" y1="9" x2="15.01" y2="9"/>
            </svg>
        </div>
        <h1>页面走丢了</h1>
        <p>你访问的页面不存在或已被移除，请检查链接是否正确。</p>
        <div class="nf-actions">
            <a href="/" class="btn btn-primary">
                <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                返回首页
            </a>
            <button type="button" class="btn btn-outline" onclick="history.length > 1 ? history.back() : location.href='/'">
                <svg viewBox="0 0 24 24"><polyline points="11 17 6 12 11 7"/><line x1="18" y1="12" x2="6" y2="12"/></svg>
                返回上一页
            </button>
        </div>
    </div>
</div>
</body>
</html>
