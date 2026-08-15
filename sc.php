<?php
session_start();
require_once __DIR__ . '/utils.php';

// 权限检查：站长或写作者可访问
if (!checkRole(ROLE_STATION_ADMIN) && !checkRole(ROLE_AUTHOR)) {
    logUnauthorized('越权尝试访问文档管理');
    header('Location: /?admin_login=1');
    exit;
}
$isStationAdmin = checkRole(ROLE_STATION_ADMIN);
// v2.6.4：写作者进入编辑器后侧边栏提供返回「写作者后台」入口（与站长一致）
// 注意：checkRole 是层级匹配（站长也会命中 author），此处用精确角色匹配，入口各归各
$isAuthor = (($_SESSION['cmt_user']['role'] ?? '') === ROLE_AUTHOR);
$myId = getCurrentUserId();
$myNick = $_SESSION['cmt_user']['nickname'] ?? '';

// 文章归属辅助：写作者仅能管理自己的文章（author_id 匹配；兼容旧文章按作者昵称匹配）
function getArticleMeta($filePath) {
    $raw = @file_get_contents($filePath);
    if ($raw && preg_match('/<!--META(.*?)-->/s', $raw, $m)) {
        $meta = json_decode(trim($m[1]), true);
        if (is_array($meta)) return $meta;
    }
    return [];
}
function canManageArticle($meta) {
    global $isStationAdmin, $myId, $myNick;
    if ($isStationAdmin) return true;
    return ($meta['author_id'] ?? '') === $myId
        || (empty($meta['author_id']) && ($meta['author'] ?? '') === $myNick);
}

$dataDir = './data/articles';
if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);

$editError = '';
$editSuccess = false;

// ====== 统一表单处理（在所有 HTML 输出之前） ======

// 删除文档
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_file'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: sc.php?csrf=1');
        exit;
    }
    $delFile = basename($_POST['delete_file']);
    $delPath = $dataDir . '/' . $delFile;
    if (file_exists($delPath) && strtolower(pathinfo($delFile, PATHINFO_EXTENSION)) === 'md') {
        $realDataPath = realpath($dataDir);
        $realDelPath = realpath($delPath);
        if ($realDelPath !== false && strpos($realDelPath, $realDataPath) === 0) {
            if (canManageArticle(getArticleMeta($delPath))) {
                // v3.3.1：系统文章（更新历史）与隐藏文章受保护——文档管理不可删除（仅在公告侧展示）
                $delMeta = getArticleMeta($delPath);
                if (in_array($delFile, ['更新历史.md'], true) || !empty($delMeta['hidden'])) {
                    logUnauthorized('越权尝试删除系统/隐藏文章: ' . $delFile);
                } else {
                    unlink($delPath);
                }
            } else {
                logUnauthorized('越权尝试删除文章: ' . $delFile);
            }
        }
    }
    header('Location: sc.php?deleted=1');
    exit;
}

// ====== v3.3.0：富媒体压缩包批量上传（md + 图片 + 视频 一体导入，独立于纯 MD 上传） ======
// 图片 → data/images/、视频 → data/videos/（均强制合法性校验）、md → data/articles/ 且引用路径自动重写；
// 解析完即删临时 zip，不占服务器空间
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['rich_zip']) && ($_FILES['rich_zip']['error'] ?? -1) === UPLOAD_ERR_OK) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: sc.php?rich_zip=csrf_error');
        exit;
    }
    if ($_FILES['rich_zip']['size'] > 80 * 1024 * 1024) {
        header('Location: sc.php?rich_zip=too_large');
        exit;
    }
    $zip = new ZipArchive();
    if ($zip->open($_FILES['rich_zip']['tmp_name']) !== true) {
        header('Location: sc.php?rich_zip=open_failed');
        exit;
    }
    $imgDir = './data/images';
    $vidDir = './data/videos';
    if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);
    if (!is_dir($vidDir)) mkdir($vidDir, 0755, true);
    $imgExt = ['jpg' => 1, 'jpeg' => 1, 'png' => 1, 'gif' => 1, 'webp' => 1];
    $vidExt = ['mp4' => 1, 'webm' => 1];
    $imgMap = []; // 原 basename => data/images/新名
    $vidMap = []; // 原 basename => data/videos/新名
    $mdList = []; // ['name' => 原相对路径, 'content' => 内容]
    $cnt = ['md' => 0, 'img' => 0, 'vid' => 0];
    $zipSafe = true;
    $errKey = '';
    if ($zip->numFiles > 200) { $zipSafe = false; $errKey = 'too_many'; }
    $totalZipSize = 0;
    for ($i = 0; $zipSafe && $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $totalZipSize += (int)($stat['size'] ?? 0);
        if ($totalZipSize > 80 * 1024 * 1024) { $zipSafe = false; $errKey = 'too_large'; break; }
        // v3.3.1：先按中央目录预检单文件解压大小，超限直接拒绝（避免先解压进内存再检查的内存型 DoS）
        if ((int)($stat['size'] ?? 0) > 40 * 1024 * 1024) { $zipSafe = false; $errKey = 'file_too_large'; break; }
        $entryName = $zip->getNameIndex($i);
        if (substr($entryName, -1) === '/' || strpos(basename($entryName), '.') === 0) continue; // 目录/隐藏文件跳过
        $entryExt = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
        $content = $zip->getFromIndex($i);
        if ($content === false) continue;
        if (strlen($content) > 40 * 1024 * 1024) { $zipSafe = false; $errKey = 'file_too_large'; break; }
        if (in_array($entryExt, ['md', 'txt', 'markdown'], true)) {
            $mdList[] = ['name' => $entryName, 'content' => $content];
        } elseif (isset($imgExt[$entryExt])) {
            // 图片强制校验：内容必须为真实图片（finfo buffer MIME）
            if (!validateImageBuffer($content)) continue; // 伪装/损坏 → 跳过该文件（不中断整体）
            $fname = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $entryExt;
            if (file_put_contents($imgDir . '/' . $fname, $content, LOCK_EX)) {
                $imgMap[basename($entryName)] = 'data/images/' . $fname;
                $cnt['img']++;
            }
        } elseif (isset($vidExt[$entryExt])) {
            // 视频强制校验：容器结构（ftyp box / EBML 魔数）+ MIME
            if (!validateVideoBuffer($content, $entryExt)) continue;
            $fname = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $entryExt;
            if (file_put_contents($vidDir . '/' . $fname, $content, LOCK_EX)) {
                $vidMap[basename($entryName)] = 'data/videos/' . $fname;
                $cnt['vid']++;
            }
        }
        // 其他类型忽略（不导入）
    }
    // md 导入 + 路径重写（第二遍，图片/视频映射已齐）
    if ($zipSafe) {
        $licenseUrlMap = ['CC BY 4.0' => 'https://creativecommons.org/licenses/by/4.0/', 'CC BY-SA 4.0' => 'https://creativecommons.org/licenses/by-sa/4.0/', 'CC BY-NC 4.0' => 'https://creativecommons.org/licenses/by-nc/4.0/', 'CC BY-NC-SA 4.0' => 'https://creativecommons.org/licenses/by-nc-sa/4.0/', 'CC BY-ND 4.0' => 'https://creativecommons.org/licenses/by-nd/4.0/', 'CC BY-NC-ND 4.0' => 'https://creativecommons.org/licenses/by-nc-nd/4.0/', 'CC0 1.0' => 'https://creativecommons.org/publicdomain/zero/1.0/'];
        foreach ($mdList as $md) {
            $baseName = basename($md['name']);
            if (empty($baseName)) continue;
            $safeName = preg_replace('/[^a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]/u', '', pathinfo($baseName, PATHINFO_FILENAME));
            if (empty($safeName)) $safeName = 'doc_' . time() . '_' . $cnt['md'];
            $targetName = $safeName . '.md';
            if (file_exists($dataDir . '/' . $targetName)) {
                $targetName = $safeName . '_' . time() . '.md';
            }
            $content = $md['content'];
            // 路径自动重写：!video[]() 与 ![]() 引用命中本次导入文件则改为站内路径（外链/已是站内路径不动）
            $content = preg_replace_callback('/!video\[([^\]]*)\]\(([^)\s]+)\)/', function ($m) use ($vidMap) {
                if (preg_match('/^(https?:)?\/\//i', $m[2]) || strpos($m[2], 'data/videos/') === 0) return $m[0];
                $b = basename($m[2]);
                if (!isset($vidMap[$b])) return $m[0];
                return substr($m[0], 0, strrpos($m[0], '(') + 1) . $vidMap[$b] . ')';
            }, $content);
            $content = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', function ($m) use ($imgMap) {
                if (preg_match('/^(https?:)?\/\//i', $m[2]) || strpos($m[2], 'data/images/') === 0) return $m[0];
                $b = basename($m[2]);
                if (!isset($imgMap[$b])) return $m[0];
                return substr($m[0], 0, strrpos($m[0], '(') + 1) . $imgMap[$b] . ')';
            }, $content);
            if (!preg_match('/^<!--META/', $content)) {
                $meta = json_encode(['category' => $_POST['category'] ?? '', 'tags' => $_POST['tags'] ?? '', 'excerpt' => $_POST['excerpt'] ?? '', 'author' => $_POST['author'] ?? '', 'author_id' => $myId, 'license' => $_POST['license'] ?? 'CC BY-NC-SA 4.0', 'licenseUrl' => ($licenseUrlMap[$_POST['license'] ?? 'CC BY-NC-SA 4.0'] ?? '')], JSON_UNESCAPED_UNICODE);
                $content = "<!--META" . $meta . "-->\n" . $content;
            }
            if (file_put_contents($dataDir . '/' . $targetName, $content, LOCK_EX)) {
                $cnt['md']++;
            }
        }
    }
    $zip->close();
    @unlink($_FILES['rich_zip']['tmp_name']); // 解析完即删，不占服务器空间
    if ($zipSafe) {
        header('Location: sc.php?rich_zip=ok&md=' . $cnt['md'] . '&img=' . $cnt['img'] . '&vid=' . $cnt['vid']);
    } else {
        header('Location: sc.php?rich_zip=' . ($errKey ?: 'aborted'));
    }
    exit;
}

// 获取文章内容（AJAX）
if (isset($_GET['action']) && $_GET['action'] === 'get_content') {
    header('Content-Type: application/json; charset=utf-8');
    $reqFile = basename($_GET['file'] ?? '');
    if (strtolower(pathinfo($reqFile, PATHINFO_EXTENSION)) !== 'md') {
        echo json_encode(['success' => false]); exit;
    }
    $reqPath = $dataDir . '/' . $reqFile;
    if (!file_exists($reqPath)) { echo json_encode(['success' => false]); exit; }
    // 最小权限：写作者只能读取自己的文章（站长可读全部）
    if (!$isStationAdmin && !canManageArticle(getArticleMeta($reqPath))) {
        logUnauthorized('越权尝试读取文章内容: ' . $reqFile);
        echo json_encode(['success' => false, 'error' => '无权访问']); exit;
    }
    $raw = file_get_contents($reqPath);
    $meta = []; $content = $raw;
    if (preg_match('/<!--META(.*?)-->/s', $raw, $m)) {
        $meta = json_decode(trim($m[1]), true) ?: [];
        $content = preg_replace('/<!--META.*?-->\n?/s', '', $raw);
    }
    $title = '';
    $contentWithoutCode = preg_replace('/```[\s\S]*?```/', '', $content);
    if (preg_match('/^#\s+(.+)/m', $contentWithoutCode, $tm)) $title = $tm[1];
    else $title = preg_replace('/\.md$/i', '', $reqFile);
    // v3.1.11：返回该文章当前是否为公告（编辑弹窗「作为公告」开关初始状态；仅站长可设）
    $isAnn = $isStationAdmin && (bool)db_one('SELECT 1 FROM announcement WHERE article = ? LIMIT 1', [$reqFile]);
    echo json_encode(['success' => true, 'title' => $title, 'content' => $content, 'meta' => $meta, 'is_announce' => $isAnn], JSON_UNESCAPED_UNICODE);
    exit;
}

// 上传/保存文档
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_FILES['markdown_file']) || isset($_POST['content']) || isset($_POST['url']) || isset($_POST['update_file']))) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $editError = 'csrf_error';
    } else {
        $title = $_POST['title'] ?? '';
        $category = $_POST['category'] ?? '';
        $tags = $_POST['tags'] ?? '';
        $excerpt = $_POST['excerpt'] ?? '';
        $content = $_POST['content'] ?? '';
        $author = $_POST['author'] ?? '';
        $license = $_POST['license'] ?? 'CC BY-NC-SA 4.0';
        $uploadedFile = $_FILES['markdown_file'] ?? null;
        $url = $_POST['url'] ?? '';
        $updateFile = $_POST['update_file'] ?? '';

        $isZip = false;
        if ($uploadedFile && $uploadedFile['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
            if ($ext === 'zip') {
                $isZip = true;
                $zip = new ZipArchive();
                if ($zip->open($uploadedFile['tmp_name']) === true) {
                    $extractedCount = 0;
                    // 防 zip bomb：限制文件数量与解压总大小
                    $zipSafe = true;
                    if ($zip->numFiles > 200) {
                        $zipSafe = false;
                        $editError = 'ZIP 内文件过多（最多 200 个），已拒绝导入';
                    }
                    $totalZipSize = 0;
                    for ($i = 0; $zipSafe && $i < $zip->numFiles; $i++) {
                        $stat = $zip->statIndex($i);
                        $totalZipSize += (int)($stat['size'] ?? 0);
                        if ($totalZipSize > 50 * 1024 * 1024) {
                            $zipSafe = false;
                            $editError = 'ZIP 解压总大小超限（最大 50MB），已拒绝导入';
                            break;
                        }
                        $entryName = $zip->getNameIndex($i);
                        if (substr($entryName, -1) === '/' || strpos(basename($entryName), '.') === 0) continue;
                        $entryExt = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
                        if (!in_array($entryExt, ['md', 'txt', 'markdown'])) continue;
                        $baseName = basename($entryName);
                        if (empty($baseName)) continue;
                        $safeName = preg_replace('/[^a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]/u', '', pathinfo($baseName, PATHINFO_FILENAME));
                        if (empty($safeName)) $safeName = 'doc_' . time() . '_' . $i;
                        $targetName = $safeName . '.md';
                        if (file_exists($dataDir . '/' . $targetName)) {
                            $targetName = $safeName . '_' . time() . '.md';
                        }
                        $fileContent = $zip->getFromIndex($i);
                        if ($fileContent === false) continue;
                        if (strlen($fileContent) > 10 * 1024 * 1024) {
                            $zipSafe = false;
                            $editError = 'ZIP 内单个文件超过 10MB，已拒绝导入';
                            break;
                        }
                        if (!preg_match('/^<!--META/', $fileContent)) {
                            $licenseUrlMap = ['CC BY 4.0' => 'https://creativecommons.org/licenses/by/4.0/', 'CC BY-SA 4.0' => 'https://creativecommons.org/licenses/by-sa/4.0/', 'CC BY-NC 4.0' => 'https://creativecommons.org/licenses/by-nc/4.0/', 'CC BY-NC-SA 4.0' => 'https://creativecommons.org/licenses/by-nc-sa/4.0/', 'CC BY-ND 4.0' => 'https://creativecommons.org/licenses/by-nd/4.0/', 'CC BY-NC-ND 4.0' => 'https://creativecommons.org/licenses/by-nc-nd/4.0/', 'CC0 1.0' => 'https://creativecommons.org/publicdomain/zero/1.0/'];
                            $meta = json_encode(['category' => $category, 'tags' => $tags, 'excerpt' => $excerpt, 'author' => $author, 'author_id' => $myId, 'license' => $license, 'licenseUrl' => ($licenseUrlMap[$license] ?? '')], JSON_UNESCAPED_UNICODE);
                            $fileContent = "<!--META" . $meta . "-->\n" . $fileContent;
                        }
                        if (file_put_contents($dataDir . '/' . $targetName, $fileContent, LOCK_EX)) {
                            $extractedCount++;
                        }
                    }
                    $zip->close();
                    @unlink($uploadedFile['tmp_name']);
                    if ($zipSafe) {
                        $editSuccess = $extractedCount > 0 ? 'ZIP 解压完成，共导入 ' . $extractedCount . ' 篇文档' : 'ZIP 中未找到可识别的 Markdown 文件';
                        if ($extractedCount === 0) $editError = 'ZIP 中未找到可识别的 Markdown 文件';
                    }
                } else {
                    $editError = '无法打开 ZIP 文件';
                }
            } else {
                $content = file_get_contents($uploadedFile['tmp_name']);
            }
        }

        if (!$isZip && !empty($url) && empty($content)) {
            // v3.0.6：SSRF 安全抓取——一次解析 + pin 解析后 IP 直连（Host/SNI 保留原域名），
            // 消除"先 gethostbyname 校验、后 file_get_contents 二次解析"的 DNS rebinding TOCTOU；内网/未识别默认拒绝
            $fetched = fetchHttpContent($url);
            if ($fetched === false) { $editError = '无法从该链接获取内容'; }
            else { $content = $fetched; }
        }

        if (empty($content) && !$editError) { $editError = '请提供 Markdown 内容'; }

        if (!$editError && !$isZip) {
            if (empty($title)) {
                $contentWithoutCode = preg_replace('/```[\s\S]*?```/', '', $content);
                if (preg_match('/^#\s+(.+)/m', $contentWithoutCode, $m)) { $title = $m[1]; }
                else { $title = '未命名文档'; }
            }
            $licenseUrlMap = ['CC BY 4.0' => 'https://creativecommons.org/licenses/by/4.0/', 'CC BY-SA 4.0' => 'https://creativecommons.org/licenses/by-sa/4.0/', 'CC BY-NC 4.0' => 'https://creativecommons.org/licenses/by-nc/4.0/', 'CC BY-NC-SA 4.0' => 'https://creativecommons.org/licenses/by-nc-sa/4.0/', 'CC BY-ND 4.0' => 'https://creativecommons.org/licenses/by-nd/4.0/', 'CC BY-NC-ND 4.0' => 'https://creativecommons.org/licenses/by-nc-nd/4.0/', 'CC0 1.0' => 'https://creativecommons.org/publicdomain/zero/1.0/'];
            $licenseUrl = $licenseUrlMap[$license] ?? '';
            $meta = json_encode(['category' => $category, 'tags' => $tags, 'excerpt' => $excerpt, 'author' => $author, 'author_id' => $myId, 'license' => $license, 'licenseUrl' => $licenseUrl], JSON_UNESCAPED_UNICODE);
            $fullContent = "<!--META" . $meta . "-->\n" . $content;

            if (!empty($updateFile)) {
                $fn = basename($updateFile);
                $fp = $dataDir . '/' . $fn;
                if (file_exists($fp)) {
                    if (!canManageArticle(getArticleMeta($fp))) {
                        $editError = '无权编辑该文章';
                        logUnauthorized('越权尝试编辑文章: ' . $fn);
                    } elseif (file_put_contents($fp, $fullContent, LOCK_EX)) {
                        $editSuccess = '文档已更新';
                        auditLog('article_update', $fn, '更新文档: ' . $title);
                    } else { $editError = '保存失败'; }
                } else { $editError = '原文件不存在'; }
            } else {
                if ($uploadedFile && $uploadedFile['error'] === UPLOAD_ERR_OK) {
                    $originalName = pathinfo($uploadedFile['name'], PATHINFO_FILENAME);
                    $fn = preg_replace('/[^a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]/u', '', $originalName);
                } else { $fn = ''; }
                if (empty($fn)) { $fn = 'doc_' . time(); }
                $fn .= '.md';
                if (file_put_contents($dataDir . '/' . $fn, $fullContent, LOCK_EX)) {
                    $editSuccess = '文档已创建';
                    auditLog('article_create', $fn, '创建文档: ' . $title);
                } else { $editError = '保存失败'; }
            }
        }

        // v3.1.11：文章发布界面「作为公告」开关（仅站长可设）——
        // 开启 → 该文章在首页公告区展示；关闭 → 移除其公告。系统文章（更新历史）受保护不被误删。
        if ($editSuccess && !$isZip && $isStationAdmin && !empty($fn)) {
            $protectedAnn = ['更新历史.md'];
            if (!in_array($fn, $protectedAnn, true)) {
                $exAnn = db_one('SELECT id FROM announcement WHERE article = ? LIMIT 1', [$fn]);
                if (!empty($_POST['as_announce'])) {
                    if (!$exAnn) {
                        addAnnouncement('manual', $fn, $myId, $title, $excerpt);
                        auditLog('announce_from_article', $fn, '将文章设为公告: ' . $title);
                    } else {
                        updateAnnouncement($exAnn['id'], $fn, $title, $excerpt);
                    }
                } else {
                    if ($exAnn) {
                        deleteAnnouncement($exAnn['id']);
                        auditLog('announce_remove', $fn, '取消文章公告: ' . $title);
                    }
                }
            }
        }
    }
    $qs = $editSuccess ? 'success=1' : 'error=' . urlencode($editError);
    header('Location: sc.php?' . $qs);
    exit;
}

// 登出（POST + CSRF）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        auditLog('logout', getCurrentUserId(), '文档管理登出');
        session_unset();
        session_destroy();
    }
    header('Location: /');
    exit;
}

// ====== 页面数据准备 ======

$files = glob($dataDir . '/*.md');
$fileList = [];
$pinnedNames = getPinnedList();
if ($files) {
    usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
    foreach ($files as $file) {
        $filename = basename($file);
        if (strpos($filename, '.') === 0) continue;
        $listMeta = getArticleMeta($file);
        // v3.3.1：系统文章（更新历史）与隐藏文章不在文档管理列表显示（仅在公告侧展示，防误删）
        if (in_array($filename, ['更新历史.md'], true) || !empty($listMeta['hidden'])) continue;
        // 写作者仅显示自己的文章（最小权限）
        if (!$isStationAdmin && !canManageArticle($listMeta)) continue;
        $content = file_get_contents($file);
        $displayName = preg_replace('/\.md$/i', '', $filename);
        if (preg_match('/^#\s+(.+)/m', $content, $m)) { $displayName = $m[1]; }
        $isPinned = in_array($filename, $pinnedNames);
        $fileList[] = ['name' => $filename, 'displayName' => $displayName, 'pinned' => $isPinned];
    }
}
usort($fileList, function($a, $b) {
    if ($a['pinned'] && !$b['pinned']) return -1;
    if (!$a['pinned'] && $b['pinned']) return 1;
    return 0;
});
$showSuccess = isset($_GET['success']);
$showError = $_GET['error'] ?? '';
$showDeleted = isset($_GET['deleted']);
// v3.3.0：富媒体压缩包导入结果（md/图片/视频 计数）
$richZipOk = isset($_GET['rich_zip']) && $_GET['rich_zip'] === 'ok';
$richZipMd = (int)($_GET['md'] ?? 0);
$richZipImg = (int)($_GET['img'] ?? 0);
$richZipVid = (int)($_GET['vid'] ?? 0);
$richZipErr = isset($_GET['rich_zip']) && $_GET['rich_zip'] !== 'ok' ? $_GET['rich_zip'] : '';
$richZipErrMsg = [
    'csrf_error' => 'CSRF 校验失败，请刷新后重试',
    'too_large' => '压缩包超过 80MB 上限，已拒绝',
    'open_failed' => '无法打开 ZIP 文件',
    'too_many' => 'ZIP 内文件过多（最多 200 个），已拒绝导入',
    'file_too_large' => 'ZIP 内单个文件超过 40MB，已拒绝导入',
    'aborted' => '导入中止（解压超限）',
][$richZipErr] ?? '';
$siteTitle = loadSiteConfig()['site_title'] ?? 'You Markdown';
?>
<!DOCTYPE html>
<html lang="zh-CN" data-admin="station">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>文档管理 - <?= htmlspecialchars($siteTitle) ?></title>
<meta name="csrf-token" content="<?= htmlspecialchars(generateCsrfToken()) ?>">
<link rel="stylesheet" href="css/admin.css?v=<?= @filemtime(__DIR__ . '/css/admin.css') ?>">
</head>
<body>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        <div class="sidebar-title"><span>文档</span>管理</div>
    </div>
    <nav class="sidebar-nav">
        <a href="sc.php" class="sidebar-link active">
            <svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            文章列表
        </a>
        <?php if ($isStationAdmin): ?>
        <a href="station/dashboard.php" class="sidebar-link">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            站长后台
        </a>
        <?php endif; ?>
        <?php if ($isAuthor): ?>
        <a href="author/dashboard.php" class="sidebar-link">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            写作者后台
        </a>
        <?php endif; ?>
        <a href="index.php" class="sidebar-link">
            <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            返回首页
        </a>
        <a href="#" onclick="logoutSubmit(event)" class="sidebar-link danger">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            退出登录
        </a>
    </nav>
</div>

<div class="main">
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            文档管理
        </div>
        <div class="page-subtitle">撰写、上传和管理文章</div>
    </div>

    <?php if ($showSuccess): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= htmlspecialchars($editSuccess ?: '操作成功') ?></div><?php endif; ?>
    <?php if ($showError): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= htmlspecialchars($showError) ?></div><?php endif; ?>
    <?php if ($showDeleted): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>文档已删除</div><?php endif; ?>
    <?php if ($richZipOk): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>富媒体压缩包导入完成：文档 <?= $richZipMd ?> 篇、图片 <?= $richZipImg ?> 张、视频 <?= $richZipVid ?> 个（zip 已自动删除）</div><?php endif; ?>
    <?php if ($richZipErrMsg): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= htmlspecialchars($richZipErrMsg) ?></div><?php endif; ?>

    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            上传新文档
        </div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <div class="method-tabs" id="methodTabs">
                <button type="button" class="method-tab active" data-method="file">文件上传</button>
                <button type="button" class="method-tab" data-method="url">链接抓取</button>
                <button type="button" class="method-tab" data-method="paste">粘贴内容</button>
                <button type="button" class="method-tab" data-method="rich">富媒体压缩包</button>
            </div>
            <div class="method-panel active" data-panel="file">
                <div class="form-group">
                    <div class="upload-zone" id="uploadZone">
                        <div class="upload-zone-icon"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></div>
                        <div class="upload-zone-text">拖拽文件到此处，或点击选择</div>
                        <div class="upload-zone-hint">支持 .md / .txt / .markdown / .zip（ZIP 自动解压）</div>
                        <input type="file" name="markdown_file" accept=".md,.txt,.markdown,.zip" id="fileInput" class="upload-zone-input">
                    </div>
                    <div class="file-info" id="fileInfo" style="display:none">
                        <span class="file-info-name" id="fileInfoName"></span>
                        <button type="button" class="file-info-remove" id="fileRemoveBtn">&times;</button>
                    </div>
                </div>
            </div>
            <div class="method-panel" data-panel="url">
                <div class="form-group">
                    <label class="form-label">文档链接</label>
                    <input class="form-input" type="url" name="url" placeholder="https://example.com/doc.md">
                </div>
            </div>
            <div class="method-panel" data-panel="paste">
                <div class="form-group">
                    <label class="form-label">Markdown 内容</label>
                    <textarea class="form-input" name="content" id="contentArea" style="min-height:160px;font-family:monospace" placeholder="在此粘贴 Markdown 内容..."></textarea>
                    <p class="form-hint" id="charCount"></p>
                </div>
            </div>
            <div class="method-panel" data-panel="rich">
                <div class="form-group">
                    <p class="form-hint" style="margin-top:0">一个 zip 内可同时含 <strong>Markdown 文档 + 图片（jpg/png/gif/webp）+ 视频（mp4/webm）</strong>：文档存为文章、图片/视频解压到站内媒体目录，md 里的引用路径自动改写，主页即可正常显示图片与播放视频；压缩包解析完自动删除。上限：包 ≤80MB、单文件 ≤40MB、≤200 个文件。</p>
                    <div class="upload-zone" id="richZipZone">
                        <div class="upload-zone-icon"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></div>
                        <div class="upload-zone-text">拖拽压缩包到此处，或点击选择</div>
                        <div class="upload-zone-hint">支持 .zip（内含 .md + 图片 + 视频）</div>
                        <input type="file" id="richZipInput" accept=".zip" class="upload-zone-input">
                    </div>
                    <div class="file-info" id="richZipInfo" style="display:none">
                        <span class="file-info-name" id="richZipInfoName"></span>
                        <button type="button" class="file-info-remove" id="richZipRemoveBtn">&times;</button>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">作者</label>
                        <input class="form-input" id="richAuthor" placeholder="作者名称">
                    </div>
                    <div class="form-group">
                        <label class="form-label">许可证书</label>
                        <select class="form-select" id="richLicense">
                            <option value="CC BY-NC-SA 4.0" selected>CC BY-NC-SA 4.0</option>
                            <option value="CC BY 4.0">CC BY 4.0</option>
                            <option value="CC BY-SA 4.0">CC BY-SA 4.0</option>
                            <option value="CC BY-NC 4.0">CC BY-NC 4.0</option>
                            <option value="CC BY-ND 4.0">CC BY-ND 4.0</option>
                            <option value="CC BY-NC-ND 4.0">CC BY-NC-ND 4.0</option>
                            <option value="CC0 1.0">CC0 1.0</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">分类</label>
                        <input class="form-input" id="richCategory" placeholder="例如：技术、随笔">
                    </div>
                    <div class="form-group">
                        <label class="form-label">标签（逗号分隔）</label>
                        <input class="form-input" id="richTags" placeholder="PHP, Markdown">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">预览摘要（留空自动生成）</label>
                    <textarea class="form-input" id="richExcerpt" style="min-height:56px" placeholder="可选"></textarea>
                </div>
                <div class="form-group" id="richProgressWrap" style="display:none">
                    <div style="display:flex;justify-content:space-between;font-size:0.85em;color:var(--text-muted);margin-bottom:6px"><span id="richProgressText">上传中 0%</span><span id="richProgressSize"></span></div>
                    <div style="height:8px;background:#eef1f6;border-radius:999px;overflow:hidden"><div id="richProgressBar" style="height:100%;width:0%;background:linear-gradient(90deg,#2563eb,#06b6d4);border-radius:999px;transition:width .2s"></div></div>
                </div>
                <button type="button" class="btn btn-primary" id="richSubmitBtn">导入富媒体压缩包</button>
                <span class="form-hint" id="richStatusHint" style="margin-left:10px"></span>
            </div>
            <div class="form-group">
                <label class="form-label">标题（留空则自动提取）</label>
                <input class="form-input" name="title" placeholder="文档标题">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">作者</label>
                    <input class="form-input" name="author" placeholder="作者名称">
                </div>
                <div class="form-group">
                    <label class="form-label">许可证书</label>
                    <select class="form-select" name="license">
                        <option value="CC BY-NC-SA 4.0" selected>CC BY-NC-SA 4.0</option>
                        <option value="CC BY 4.0">CC BY 4.0</option>
                        <option value="CC BY-SA 4.0">CC BY-SA 4.0</option>
                        <option value="CC BY-NC 4.0">CC BY-NC 4.0</option>
                        <option value="CC BY-ND 4.0">CC BY-ND 4.0</option>
                        <option value="CC BY-NC-ND 4.0">CC BY-NC-ND 4.0</option>
                        <option value="CC0 1.0">CC0 1.0</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">分类</label>
                    <input class="form-input" name="category" placeholder="例如：技术、随笔">
                </div>
                <div class="form-group">
                    <label class="form-label">标签（逗号分隔）</label>
                    <input class="form-input" name="tags" placeholder="PHP, Markdown">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">预览摘要（留空自动生成）</label>
                <textarea class="form-input" name="excerpt" style="min-height:56px" placeholder="可选"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">上传文档</button>
        </form>
    </div>

    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            已有文章（<?= count($fileList) ?> 篇）
        </div>
        <?php if (empty($fileList)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <p>暂无文档</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <tr><th>标题</th><th>置顶</th><th>查看</th><th>编辑</th><th>删除</th></tr>
            <?php foreach ($fileList as $f): ?>
            <tr>
                <td style="font-weight:500"><?= htmlspecialchars($f['displayName']) ?></td>
                <td>
                    <button class="btn-link pin-btn <?= $f['pinned'] ? 'pinned' : '' ?>" data-name="<?= htmlspecialchars($f['name']) ?>" data-pinned="<?= $f['pinned'] ? '1' : '0' ?>"><?= $f['pinned'] ? '已置顶' : '置顶' ?></button>
                </td>
                <td><a class="btn-link" href="index.php?file=<?= urlencode($f['name']) ?>" target="_blank">查看</a></td>
                <td><button class="btn-link" onclick="openEditModal('<?= htmlspecialchars($f['name']) ?>')">编辑</button></td>
                <td><button class="btn-link danger" onclick="confirmDelete('<?= htmlspecialchars($f['name']) ?>', '<?= htmlspecialchars($f['displayName']) ?>')">删除</button></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 删除确认弹窗 -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box" style="max-width:380px">
        <div class="modal-head">
            <div class="modal-title">确认删除</div>
            <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
        </div>
        <div class="modal-body">
            <p id="deleteModalText" style="font-size:0.92em;color:var(--text-secondary);margin-bottom:18px">确定要删除这篇文章吗？此操作不可撤销。</p>
            <div class="modal-actions">
                <button class="btn btn-outline" onclick="closeModal('deleteModal')">取消</button>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                    <input type="hidden" name="delete_file" id="deleteConfirmFile">
                    <button type="submit" class="btn btn-danger">删除</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- 编辑弹窗 -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box" style="max-width:720px;max-height:90vh;overflow-y:auto">
        <div class="modal-head">
            <div class="modal-title">编辑文档 <span id="editFileName" style="font-weight:400;color:var(--text-muted);font-size:0.82em"></span></div>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                <input type="hidden" name="update_file" id="editUpdateFile" value="">
                <div class="form-group">
                    <label class="form-label">Markdown 内容</label>
                    <div class="editor-toolbar">
                        <button type="button" class="btn btn-sm btn-outline" id="btnInsertImage">插入图片</button>
                        <input type="file" id="imageUploadInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none">
                        <span class="form-hint" id="imageUploadHint" style="margin-left:10px"></span>
                        <button type="button" class="btn btn-sm btn-outline" id="btnInsertVideo" style="margin-left:8px">插入视频</button>
                        <input type="file" id="videoUploadInput" accept="video/mp4,video/webm" style="display:none">
                        <span class="form-hint" id="videoUploadHint" style="margin-left:10px"></span>
                    </div>
                    <textarea class="form-input" name="content" id="editContent" style="min-height:200px;font-family:monospace" placeholder="Markdown 内容..."></textarea>
                    <p class="form-hint" id="editCharCount"></p>
                </div>
                <div class="form-group">
                    <label class="form-label">标题（留空则自动提取）</label>
                    <input class="form-input" name="title" id="editTitle" placeholder="文档标题">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">作者</label>
                        <input class="form-input" name="author" id="editAuthor" placeholder="作者">
                    </div>
                    <div class="form-group">
                        <label class="form-label">许可证书</label>
                        <select class="form-select" name="license" id="editLicense">
                            <option value="CC BY-NC-SA 4.0">CC BY-NC-SA 4.0</option>
                            <option value="CC BY 4.0">CC BY 4.0</option>
                            <option value="CC BY-SA 4.0">CC BY-SA 4.0</option>
                            <option value="CC BY-NC 4.0">CC BY-NC 4.0</option>
                            <option value="CC BY-ND 4.0">CC BY-ND 4.0</option>
                            <option value="CC BY-NC-ND 4.0">CC BY-NC-ND 4.0</option>
                            <option value="CC0 1.0">CC0 1.0</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">分类</label>
                        <input class="form-input" name="category" id="editCategory" placeholder="分类">
                    </div>
                    <div class="form-group">
                        <label class="form-label">标签（逗号分隔）</label>
                        <input class="form-input" name="tags" id="editTags" placeholder="标签">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">预览摘要</label>
                    <textarea class="form-input" name="excerpt" id="editExcerpt" style="min-height:56px" placeholder="可选"></textarea>
                </div>
                <?php if ($isStationAdmin): ?>
                <div class="form-group" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--surface-2,var(--bg));border:1px solid var(--border);border-radius:10px">
                    <input type="checkbox" name="as_announce" id="editAsAnnounce" value="1" style="width:17px;height:17px;accent-color:var(--accent)">
                    <div>
                        <label for="editAsAnnounce" style="font-weight:600;cursor:pointer">同时作为公告展示</label>
                        <div class="form-hint" style="margin:0">开启后该文章在首页公告区展示，且不再出现在文章列表</div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">保存修改</button>
                    <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">取消</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
document.querySelectorAll('.modal-overlay').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === m) m.classList.remove('active'); });
});

function confirmDelete(fn, dn) {
    document.getElementById('deleteModalText').textContent = '确定要删除「' + dn + '」吗？此操作不可撤销。';
    document.getElementById('deleteConfirmFile').value = fn;
    document.getElementById('deleteModal').classList.add('active');
}

// 方法切换
document.querySelectorAll('.method-tab').forEach(function(b) {
    b.addEventListener('click', function() {
        document.querySelectorAll('.method-tab').forEach(function(x) { x.classList.remove('active'); });
        document.querySelectorAll('.method-panel').forEach(function(x) { x.classList.remove('active'); });
        b.classList.add('active');
        document.querySelector('.method-panel[data-panel="' + b.dataset.method + '"]').classList.add('active');
    });
});

// 文件上传
(function() {
    var dz = document.getElementById('uploadZone'), fi = document.getElementById('fileInput');
    var info = document.getElementById('fileInfo'), nm = document.getElementById('fileInfoName');
    var rb = document.getElementById('fileRemoveBtn');
    if (!dz) return;
    function show(f) { nm.textContent = f.name + ' (' + (f.size/1024).toFixed(1) + ' KB)'; info.style.display = 'flex'; dz.style.display = 'none'; }
    function clearF() { fi.value = ''; info.style.display = 'none'; dz.style.display = 'block'; }
    fi.addEventListener('change', function() { if (this.files.length) show(this.files[0]); });
    if (rb) rb.addEventListener('click', clearF);
    ['dragenter','dragover'].forEach(function(e) { dz.addEventListener(e, function(ev) { ev.preventDefault(); dz.classList.add('dragover'); }); });
    ['dragleave','drop'].forEach(function(e) { dz.addEventListener(e, function(ev) { ev.preventDefault(); dz.classList.remove('dragover'); }); });
    dz.addEventListener('drop', function(e) {
        var f = e.dataTransfer.files;
        if (f.length && f[0].name.match(/\.(md|txt|markdown|zip)$/i)) { fi.files = f; show(f[0]); }
    });
})();

// v3.3.0：富媒体压缩包（md + 图片 + 视频）上传交互
(function() {
    var dz = document.getElementById('richZipZone'), fi = document.getElementById('richZipInput');
    var info = document.getElementById('richZipInfo'), nm = document.getElementById('richZipInfoName');
    var rb = document.getElementById('richZipRemoveBtn');
    if (!dz || !fi) return;
    function show(f) { nm.textContent = f.name + ' (' + (f.size/1024/1024).toFixed(2) + ' MB)'; info.style.display = 'flex'; dz.style.display = 'none'; }
    function clearF() { fi.value = ''; info.style.display = 'none'; dz.style.display = 'block'; }
    fi.addEventListener('change', function() { if (this.files.length) show(this.files[0]); });
    if (rb) rb.addEventListener('click', clearF);
    ['dragenter','dragover'].forEach(function(e) { dz.addEventListener(e, function(ev) { ev.preventDefault(); dz.classList.add('dragover'); }); });
    ['dragleave','drop'].forEach(function(e) { dz.addEventListener(e, function(ev) { ev.preventDefault(); dz.classList.remove('dragover'); }); });
    dz.addEventListener('drop', function(e) {
        var f = e.dataTransfer.files;
        if (f.length && f[0].name.match(/\.zip$/i)) { fi.files = f; show(f[0]); }
    });
})();

// v3.3.1：富媒体压缩包 XHR 异步上传（真实进度条，避免大包干等）
(function() {
    var btn = document.getElementById('richSubmitBtn');
    var input = document.getElementById('richZipInput');
    var pbar = document.getElementById('richProgressBar');
    var ptext = document.getElementById('richProgressText');
    var psize = document.getElementById('richProgressSize');
    var pwrap = document.getElementById('richProgressWrap');
    var hint = document.getElementById('richStatusHint');
    if (!btn || !input) return;
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content
        || (document.querySelector('input[name="csrf_token"]') || {}).value || '';
    btn.addEventListener('click', function() {
        if (!input.files.length) { hint.textContent = '请先选择 zip 压缩包'; return; }
        var file = input.files[0];
        if (!/\.zip$/i.test(file.name)) { hint.textContent = '仅支持 .zip 文件'; return; }
        if (file.size > 80 * 1024 * 1024) { hint.textContent = '压缩包超过 80MB 上限'; return; }
        if (!window.confirm('确认导入该富媒体压缩包？')) return;
        var fd = new FormData();
        fd.append('rich_zip', file);
        fd.append('csrf_token', csrf);
        fd.append('author', (document.getElementById('richAuthor') || {}).value || '');
        fd.append('license', (document.getElementById('richLicense') || {}).value || 'CC BY-NC-SA 4.0');
        fd.append('category', (document.getElementById('richCategory') || {}).value || '');
        fd.append('tags', (document.getElementById('richTags') || {}).value || '');
        fd.append('excerpt', (document.getElementById('richExcerpt') || {}).value || '');
        btn.disabled = true;
        hint.textContent = '';
        pwrap.style.display = 'block';
        ptext.textContent = '上传中 0%';
        psize.textContent = (file.size / 1048576).toFixed(2) + ' MB';
        pbar.style.width = '0%';
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'sc.php', true);
        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                var pct = Math.round(e.loaded / e.total * 100);
                pbar.style.width = pct + '%';
                ptext.textContent = '上传中 ' + pct + '%';
                psize.textContent = (e.loaded / 1048576).toFixed(2) + ' / ' + (e.total / 1048576).toFixed(2) + ' MB';
            }
        };
        xhr.onload = function() {
            if (xhr.status >= 200 && xhr.status < 400) {
                // 服务端 302 已由 XHR 跟随，最终 URL 带 rich_zip 结果参数 → 刷新显示结果
                location.href = xhr.responseURL || 'sc.php';
            } else {
                ptext.textContent = '上传失败（HTTP ' + xhr.status + '）';
                pbar.style.width = '0%';
                btn.disabled = false;
                hint.textContent = '请重试或检查服务器上传限制';
            }
        };
        xhr.onerror = function() {
            ptext.textContent = '网络错误，上传失败';
            btn.disabled = false;
        };
        xhr.send(fd);
    });
})();

// 字数统计
(function() {
    var ta = document.getElementById('contentArea'), ct = document.getElementById('charCount');
    if (!ta || !ct) return;
    function u() { var l = ta.value.replace(/\s/g,'').length; ct.textContent = l > 0 ? l + ' 字' : ''; }
    ta.addEventListener('input', u);
})();

// 编辑弹窗
function openEditModal(fn) {
    document.getElementById('editFileName').textContent = fn;
    document.getElementById('editUpdateFile').value = fn;
    document.getElementById('editContent').value = '加载中...';
    document.getElementById('editTitle').value = '';
    document.getElementById('editAuthor').value = '';
    document.getElementById('editCategory').value = '';
    document.getElementById('editTags').value = '';
    document.getElementById('editExcerpt').value = '';
    document.getElementById('editLicense').value = 'CC BY-NC-SA 4.0';
    document.getElementById('editCharCount').textContent = '';
    document.getElementById('imageUploadHint').textContent = '';
    var annChk = document.getElementById('editAsAnnounce');
    if (annChk) annChk.checked = false;
    document.getElementById('editModal').classList.add('active');
    fetch('sc.php?action=get_content&file=' + encodeURIComponent(fn))
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) return;
            document.getElementById('editContent').value = d.content;
            document.getElementById('editTitle').value = d.title;
            if (d.meta) {
                document.getElementById('editAuthor').value = d.meta.author || '';
                document.getElementById('editCategory').value = d.meta.category || '';
                document.getElementById('editTags').value = d.meta.tags || '';
                document.getElementById('editExcerpt').value = d.meta.excerpt || '';
                if (d.meta.license) document.getElementById('editLicense').value = d.meta.license;
            }
            if (annChk && d.is_announce) annChk.checked = true;
            var l = d.content.replace(/\s/g,'').length;
            document.getElementById('editCharCount').textContent = l > 0 ? l + ' 字' : '';
        });
}
document.getElementById('editContent')?.addEventListener('input', function() {
    var l = this.value.replace(/\s/g,'').length;
    document.getElementById('editCharCount').textContent = l > 0 ? l + ' 字' : '';
});

// v3.1.6：文章图片上传（站长/写作者；上传成功后以 Markdown 语法插入光标处）
(function() {
    var btn = document.getElementById('btnInsertImage');
    var input = document.getElementById('imageUploadInput');
    var hint = document.getElementById('imageUploadHint');
    if (!btn || !input) return;
    btn.addEventListener('click', function() { input.click(); });
    input.addEventListener('change', function() {
        if (!this.files.length) return;
        var file = this.files[0];
        var csrf = (document.querySelector('#editModal input[name=csrf_token]') || {}).value || '';
        hint.textContent = '上传中...';
        var fd = new FormData();
        fd.append('image', file);
        fetch('api.php?action=article_image_upload', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf },
            body: fd
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (!d.success) { hint.textContent = '上传失败：' + (d.error || '未知错误'); return; }
            hint.textContent = '已上传 ✓';
            var ta = document.getElementById('editContent');
            var ins = '\n![图片](' + d.url + ')\n';
            var start = ta.selectionStart, end = ta.selectionEnd;
            ta.value = ta.value.slice(0, start) + ins + ta.value.slice(end);
            ta.selectionStart = ta.selectionEnd = start + ins.length;
            ta.focus();
            var l = ta.value.replace(/\s/g,'').length;
            document.getElementById('editCharCount').textContent = l > 0 ? l + ' 字' : '';
        }).catch(function() { hint.textContent = '网络错误，上传失败'; });
        this.value = '';
    });
})();

// v3.3.0：文章视频上传（站长/写作者；上传成功后以 !video[]() 语法插入光标处；≤20MB，服务端强制合法性校验）
(function() {
    var btn = document.getElementById('btnInsertVideo');
    var input = document.getElementById('videoUploadInput');
    var hint = document.getElementById('videoUploadHint');
    if (!btn || !input) return;
    btn.addEventListener('click', function() { input.click(); });
    input.addEventListener('change', function() {
        if (!this.files.length) return;
        var file = this.files[0];
        if (file.size > 20 * 1024 * 1024) { hint.textContent = '视频需 ≤20MB，请先压缩后再传'; this.value = ''; return; }
        var csrf = (document.querySelector('#editModal input[name=csrf_token]') || {}).value || '';
        hint.textContent = '上传中...';
        var fd = new FormData();
        fd.append('video', file);
        fetch('api.php?action=article_video_upload', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf },
            body: fd
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (!d.success) { hint.textContent = '上传失败：' + (d.error || '未知错误'); return; }
            hint.textContent = '已上传 ✓';
            var ta = document.getElementById('editContent');
            var ins = '\n!video[视频](' + d.url + ')\n';
            var start = ta.selectionStart, end = ta.selectionEnd;
            ta.value = ta.value.slice(0, start) + ins + ta.value.slice(end);
            ta.selectionStart = ta.selectionEnd = start + ins.length;
            ta.focus();
            var l = ta.value.replace(/\s/g,'').length;
            document.getElementById('editCharCount').textContent = l > 0 ? l + ' 字' : '';
        }).catch(function() { hint.textContent = '网络错误，上传失败'; });
        this.value = '';
    });
})();

// 支持 ?edit=<文件名>：从写作者/站长后台直接进入编辑弹窗（v2.5.1）
(function() {
    var p = new URLSearchParams(window.location.search);
    var ef = p.get('edit');
    if (ef) openEditModal(ef);
})();

// 置顶/取消置顶（index.php 的 POST 需要 CSRF token）
var scCsrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
// 登出走 POST + CSRF
function logoutSubmit(e) {
    e.preventDefault();
    var fd = new FormData();
    fd.append('logout', '1');
    fd.append('csrf_token', scCsrfToken);
    fetch('sc.php', { method: 'POST', body: fd }).then(function() { location.href = '/'; });
}
document.querySelectorAll('.pin-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var name = this.dataset.name;
        var isPinned = this.dataset.pinned === '1';
        var action = isPinned ? 'unpin' : 'pin';
        var btnEl = this;
        fetch('index.php?action=' + action, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': scCsrfToken},
            body: 'file=' + encodeURIComponent(name)
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.success) location.reload();
        });
    });
});
</script>
</body>
</html>