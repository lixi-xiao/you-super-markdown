/* You Super Markdown 5.1.0 — 外壳增强脚本（P3 自研交互层）
 * ---------------------------------------------------------------------------
 * 定位：纯增量。只提供 main.js 未实现的「外壳级」轻量增强；
 *       不重写 / 不替换既有交互，不绑定 main.js 已占用的 ID，不调用或改写 main.js 的任何函数。
 *
 * 与 main.js 的边界（避免重叠）：
 *   - main.js 的 window scroll 处理器负责：#topBar 的 .hidden、阅读进度条、
 *     移动端浮动按钮 .buttons-hidden、scrollToTop 按钮显隐（仅切换上述既有类/样式）。
 *   - 本文件只切换一个全新类 is-scrolled（#topBar），语义互不干扰、无共享状态。
 *   - main.js 的 document keydown 处理器处理 Escape / 方向键 / "/" / "?" / "s" 等；
 *     本文件只在 Tab 键上给 <html> 加 ys-kbd-nav，键位不重叠。
 *   - 本文件不读取/写入 main.js 的任何变量，也不修改既有 DOM 结构。
 */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
  }

  ready(function () {
    /* 1) 顶栏滚动边界：滚动离开顶部时给 #topBar 加 is-scrolled（阴影/边界），
     *    仅切换新类名，不改动 main.js 的 .hidden 逻辑（两者可同时存在）。 */
    var topBar = document.getElementById('topBar');
    if (topBar) {
      var ticking = false;
      var syncTopBar = function () {
        ticking = false;
        var y = window.pageYOffset || document.documentElement.scrollTop || 0;
        topBar.classList.toggle('is-scrolled', y > 4);
      };
      window.addEventListener('scroll', function () {
        if (!ticking) {
          ticking = true;
          (window.requestAnimationFrame || function (cb) { setTimeout(cb, 16); })(syncTopBar);
        }
      }, { passive: true });
      syncTopBar();
    }

    /* 2) 键盘焦点环辅助类：仅当用户用 Tab 导航时给 <html> 加 ys-kbd-nav，
     *    指针操作时移除，避免鼠标点击也显示焦点环（配合 :focus-visible 使用）。 */
    var root = document.documentElement;
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Tab') root.classList.add('ys-kbd-nav');
    });
    document.addEventListener('pointerdown', function () {
      if (root.classList.contains('ys-kbd-nav')) root.classList.remove('ys-kbd-nav');
    }, true);
  });
})();
