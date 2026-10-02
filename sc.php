<?php
require_once __DIR__ . '/utils.php';
secureSessionStart();

// 权限检查：站长或写作者可访问
if (!checkRole(ROLE_STATION_ADMIN) && !checkRole(ROLE_AUTHOR)) {
    logUnauthorized('越权尝试访问文档管理');
    header('Location: /?admin_login=1');
    exit;
}
// v5.0.0 P2：与后台一致的后台会话纵深校验——账号被删/吊销立即失效，后台会话 24h 显式过期
if (!validateBackendUser()) {
    session_unset();
    session_destroy();
    header('Location: /?admin_login=1&expired=1');
    exit;
}
// v4.6.0：后台会话 24 小时显式过期。
// v5.3.1：过期只失效**后台会话标记**并回退首页（可读提示），**绝不清前台登录态**（cmt_user / refresh 保持）；
//         前台登录态不得用于直接进后台——重新进入须重新验证身份。
if (backendSessionExpired()) {
    clearBackendSession();
    header('Location: /?admin_login=1&expired=1');
    exit;
}
$isStationAdmin = checkRole(ROLE_STATION_ADMIN);
// v2.6.4：写作者进入编辑器后侧边栏提供返回「写作者后台」入口（与站长一致）
// 注意：checkRole 是层级匹配（站长也会命中 author），此处用精确角色匹配，入口各归各
$isAuthor = (($_SESSION['cmt_user']['role'] ?? '') === ROLE_AUTHOR);
// v5.4.0-beta：AI 写作入口是否可用（超管不参与创作；需超管已开放对应角色）
$aiToolEnabled = aiRoleAllowed($_SESSION['cmt_user']['role'] ?? '');
$myId = getCurrentUserId();
$myNick = $_SESSION['cmt_user']['nickname'] ?? '';

// 文章归属辅助：写作者仅能管理自己的文章（author_id 匹配；兼容旧文章按作者昵称匹配）
// v5.0.0：readArticleMeta() 已上移至 utils.php 共享（首页服务端分享卡片复用同一 front-matter 解析）
function mayManageArticle($meta) {
    global $isStationAdmin, $myId, $myNick;
    if ($isStationAdmin) return true;
    return ($meta['author_id'] ?? '') === $myId
        || (empty($meta['author_id']) && ($meta['author'] ?? '') === $myNick);
}

$articlesDir = './data/articles';
if (!is_dir($articlesDir)) mkdir($articlesDir, 0755, true);

$saveErr = '';
$saveOk = false;

// ====== 统一表单处理（在所有 HTML 输出之前） ======

// 删除文档
if (isset($_POST['delete_file']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: sc.php?csrf=1');
        exit;
    }
    $victimFile = basename($_POST['delete_file']);
    $victimPath = $articlesDir . '/' . $victimFile;
    if (file_exists($victimPath) && strtolower(pathinfo($victimFile, PATHINFO_EXTENSION)) === 'md') {
        $rootReal = realpath($articlesDir);
        $victimReal = realpath($victimPath);
        if ($victimReal !== false && strpos($victimReal, $rootReal) === 0) {
            if (mayManageArticle(readArticleMeta($victimPath))) {
                // v3.3.1：系统文章（更新历史）与隐藏文章受保护——文档管理不可删除（仅在公告侧展示）
                $victimMeta = readArticleMeta($victimPath);
                if (in_array($victimFile, ['更新历史.md'], true) || !empty($victimMeta['hidden'])) {
                    logUnauthorized('越权尝试删除系统/隐藏文章: ' . $victimFile);
                } else {
                    unlink($victimPath);
                }
            } else {
                logUnauthorized('越权尝试删除文章: ' . $victimFile);
            }
        }
    }
    header('Location: sc.php?deleted=1');
    exit;
}

// ====== v3.3.0：富媒体压缩包批量上传（md + 图片 + 视频 一体导入，独立于纯 MD 上传） ======
// 图片 → data/images/、视频 → data/videos/（均强制合法性校验）、md → data/articles/ 且引用路径自动重写；
// 解析完即删临时 zip，不占服务器空间
if (isset($_FILES['rich_zip']) && ($_FILES['rich_zip']['error'] ?? -1) === UPLOAD_ERR_OK && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: sc.php?rich_zip=csrf_error');
        exit;
    }
    if ($_FILES['rich_zip']['size'] > 80 * 1024 * 1024) {
        header('Location: sc.php?rich_zip=too_large');
        exit;
    }
    $archive = new ZipArchive();
    if ($archive->open($_FILES['rich_zip']['tmp_name']) !== true) {
        header('Location: sc.php?rich_zip=open_failed');
        exit;
    }
    $imageDir = './data/images';
    $videoDir = './data/videos';
    if (!is_dir($imageDir)) mkdir($imageDir, 0755, true);
    if (!is_dir($videoDir)) mkdir($videoDir, 0755, true);
    $imageExts = ['jpg' => 1, 'jpeg' => 1, 'png' => 1, 'gif' => 1, 'webp' => 1];
    $videoExts = ['mp4' => 1, 'webm' => 1];
    $imageMap = []; // 原 basename => data/images/新名
    $videoMap = []; // 原 basename => data/videos/新名
    $docList = []; // ['name' => 原相对路径, 'content' => 内容]
    $tally = ['md' => 0, 'img' => 0, 'vid' => 0];
    // v4.1.6：富媒体包发布方式——立即发布（默认）/ 存草稿 / 定时发布；显式选择非「立即发布」时覆盖导入 md 的发布状态
    $pubStatusRich = $_POST['publish_status'] ?? 'published';
    if (!in_array($pubStatusRich, ['published', 'draft', 'scheduled'], true)) $pubStatusRich = 'published';
    $pubAtRich = trim($_POST['publish_at'] ?? '');
    if ($pubStatusRich === 'scheduled' && $pubAtRich === '') $pubStatusRich = 'published'; // 定时未填时间 → 立即发布（与编辑器一致）
    $pubMetaRich = [];
    if ($pubStatusRich !== 'published') {
        $pubMetaRich['status'] = $pubStatusRich;
        if ($pubStatusRich === 'scheduled') $pubMetaRich['publish_at'] = str_replace('T', ' ', $pubAtRich);
    }
    $archiveOk = true;
    $failKey = '';
    if ($archive->numFiles > 200) { $archiveOk = false; $failKey = 'too_many'; }
    $archiveBytes = 0;
    for ($idx = 0; $archiveOk && $idx < $archive->numFiles; $idx++) {
        $entryStat = $archive->statIndex($idx);
        $archiveBytes += (int)($entryStat['size'] ?? 0);
        if ($archiveBytes > 80 * 1024 * 1024) { $archiveOk = false; $failKey = 'too_large'; break; }
        // v3.3.1：先按中央目录预检单文件解压大小，超限直接拒绝（避免先解压进内存再检查的内存型 DoS）
        if ((int)($entryStat['size'] ?? 0) > 40 * 1024 * 1024) { $archiveOk = false; $failKey = 'file_too_large'; break; }
        $entryPath = $archive->getNameIndex($idx);
        if (substr($entryPath, -1) === '/' || strpos(basename($entryPath), '.') === 0) continue; // 目录/隐藏文件跳过
        $entryExtLower = strtolower(pathinfo($entryPath, PATHINFO_EXTENSION));
        $text = $archive->getFromIndex($idx);
        if ($text === false) continue;
        if (strlen($text) > 40 * 1024 * 1024) { $archiveOk = false; $failKey = 'file_too_large'; break; }
        if (in_array($entryExtLower, ['md', 'txt', 'markdown'], true)) {
            $docList[] = ['name' => $entryPath, 'content' => $text];
        } elseif (isset($imageExts[$entryExtLower])) {
            // 图片强制校验：内容必须为真实图片（finfo buffer MIME）
            if (!validateImageBuffer($text)) continue; // 伪装/损坏 → 跳过该文件（不中断整体）
            $storedName = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $entryExtLower;
            if (file_put_contents($imageDir . '/' . $storedName, $text, LOCK_EX)) {
                $imageMap[basename($entryPath)] = 'data/images/' . $storedName;
                $tally['img']++;
            }
        } elseif (isset($videoExts[$entryExtLower])) {
            // 视频强制校验：容器结构（ftyp box / EBML 魔数）+ MIME
            if (!validateVideoBuffer($text, $entryExtLower)) continue;
            $storedName = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $entryExtLower;
            if (file_put_contents($videoDir . '/' . $storedName, $text, LOCK_EX)) {
                $videoMap[basename($entryPath)] = 'data/videos/' . $storedName;
                $tally['vid']++;
            }
        }
        // 其他类型忽略（不导入）
    }
    // md 导入 + 路径重写（第二遍，图片/视频映射已齐）
    if ($archiveOk) {
        $licenseUrls = ['CC BY 4.0' => 'https://creativecommons.org/licenses/by/4.0/', 'CC BY-SA 4.0' => 'https://creativecommons.org/licenses/by-sa/4.0/', 'CC BY-NC 4.0' => 'https://creativecommons.org/licenses/by-nc/4.0/', 'CC BY-NC-SA 4.0' => 'https://creativecommons.org/licenses/by-nc-sa/4.0/', 'CC BY-ND 4.0' => 'https://creativecommons.org/licenses/by-nd/4.0/', 'CC BY-NC-ND 4.0' => 'https://creativecommons.org/licenses/by-nc-nd/4.0/', 'CC0 1.0' => 'https://creativecommons.org/publicdomain/zero/1.0/'];
        foreach ($docList as $doc) {
            $leafName = basename($doc['name']);
            if (empty($leafName)) continue;
            $slugName = preg_replace('/[^a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]/u', '', pathinfo($leafName, PATHINFO_FILENAME));
            if (empty($slugName)) $slugName = 'doc_' . time() . '_' . $tally['md'];
            $destName = $slugName . '.md';
            if (file_exists($articlesDir . '/' . $destName)) {
                $destName = $slugName . '_' . time() . '.md';
            }
            $text = $doc['content'];
            // 路径自动重写：!video[]() 与 ![]() 引用命中本次导入文件则改为站内路径（外链/已是站内路径不动）
            $text = preg_replace_callback('/!video\[([^\]]*)\]\(([^)\s]+)\)/', function ($hit) use ($videoMap) {
                if (preg_match('/^(https?:)?\/\//i', $hit[2]) || strpos($hit[2], 'data/videos/') === 0) return $hit[0];
                $leaf = basename($hit[2]);
                if (!isset($videoMap[$leaf])) return $hit[0];
                return substr($hit[0], 0, strrpos($hit[0], '(') + 1) . $videoMap[$leaf] . ')';
            }, $text);
            $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', function ($hit) use ($imageMap) {
                if (preg_match('/^(https?:)?\/\//i', $hit[2]) || strpos($hit[2], 'data/images/') === 0) return $hit[0];
                $leaf = basename($hit[2]);
                if (!isset($imageMap[$leaf])) return $hit[0];
                return substr($hit[0], 0, strrpos($hit[0], '(') + 1) . $imageMap[$leaf] . ')';
            }, $text);
            if (!preg_match('/^<!--META/', $text)) {
                $frontMatter = ['category' => $_POST['category'] ?? '', 'tags' => $_POST['tags'] ?? '', 'excerpt' => $_POST['excerpt'] ?? '', 'author' => $_POST['author'] ?? '', 'author_id' => $myId, 'license' => $_POST['license'] ?? 'CC BY-NC-SA 4.0', 'licenseUrl' => ($licenseUrls[$_POST['license'] ?? 'CC BY-NC-SA 4.0'] ?? '')];
                // v4.1.6：表单显式选择非「立即发布」时写入发布状态（默认立即发布，保持原行为）
                if ($pubMetaRich) {
                    $frontMatter = array_merge($frontMatter, $pubMetaRich);
                    if ($pubStatusRich === 'draft') unset($frontMatter['publish_at']);
                }
                $text = "<!--META" . json_encode($frontMatter, JSON_UNESCAPED_UNICODE) . "-->\n" . $text;
            } elseif ($pubMetaRich && preg_match('/^<!--META(.*?)-->/s', $text, $hit)) {
                // v4.1.6：md 自带 META 时，表单显式选择非「立即发布」则覆盖其发布状态（重建 META 头）
                $frontMatter = json_decode(trim($hit[1]), true);
                if (!is_array($frontMatter)) $frontMatter = [];
                $frontMatter = array_merge($frontMatter, $pubMetaRich);
                if ($pubStatusRich === 'draft') unset($frontMatter['publish_at']);
                $text = preg_replace('/^<!--META.*?-->/s', "<!--META" . json_encode($frontMatter, JSON_UNESCAPED_UNICODE) . "-->", $text, 1);
            }
            if (file_put_contents($articlesDir . '/' . $destName, $text, LOCK_EX)) {
                $tally['md']++;
            }
        }
    }
    $archive->close();
    @unlink($_FILES['rich_zip']['tmp_name']); // 解析完即删，不占服务器空间
    if ($tally['md'] > 0) triggerArticleBackup(); // v3.3.5：上传含 md → 触发守护进程立即备份
    if ($archiveOk) {
        header('Location: sc.php?rich_zip=ok&md=' . $tally['md'] . '&img=' . $tally['img'] . '&vid=' . $tally['vid']);
    } else {
        header('Location: sc.php?rich_zip=' . ($failKey ?: 'aborted'));
    }
    exit;
}

// 获取文章内容（AJAX）
if (isset($_GET['action']) && $_GET['action'] === 'get_content') {
    header('Content-Type: application/json; charset=utf-8');
    $askFile = basename($_GET['file'] ?? '');
    if (strtolower(pathinfo($askFile, PATHINFO_EXTENSION)) !== 'md') {
        echo json_encode(['success' => false]); exit;
    }
    $askPath = $articlesDir . '/' . $askFile;
    if (!file_exists($askPath)) { echo json_encode(['success' => false]); exit; }
    // 最小权限：写作者只能读取自己的文章（站长可读全部）
    if (!$isStationAdmin && !mayManageArticle(readArticleMeta($askPath))) {
        logUnauthorized('越权尝试读取文章内容: ' . $askFile);
        echo json_encode(['success' => false, 'error' => '无权访问']); exit;
    }
    $blob = file_get_contents($askPath);
    $frontMatter = []; $text = $blob;
    if (preg_match('/<!--META(.*?)-->/s', $blob, $hit)) {
        $frontMatter = json_decode(trim($hit[1]), true) ?: [];
        $text = preg_replace('/<!--META.*?-->\n?/s', '', $blob);
    }
    $docTitle = '';
    $titleProbe = preg_replace('/```[\s\S]*?```/', '', $text);
    if (preg_match('/^#\s+(.+)/m', $titleProbe, $titleHit)) $docTitle = $titleHit[1];
    else $docTitle = preg_replace('/\.md$/i', '', $askFile);
    // v3.1.11：返回该文章当前是否为公告（编辑弹窗「作为公告」开关初始状态；仅站长可设）
    $isPinnedAnn = $isStationAdmin && (bool)db_one('SELECT 1 FROM announcement WHERE article = ? LIMIT 1', [$askFile]);
    echo json_encode(['success' => true, 'title' => $docTitle, 'content' => $text, 'meta' => $frontMatter, 'is_announce' => $isPinnedAnn], JSON_UNESCAPED_UNICODE);
    exit;
}

// 上传/保存文档
if ((isset($_FILES['markdown_file']) || isset($_POST['content']) || isset($_POST['url']) || isset($_POST['update_file'])) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $saveErr = 'csrf_error';
    } else {
        $docTitle = $_POST['title'] ?? '';
        $cat = $_POST['category'] ?? '';
        $tagStr = $_POST['tags'] ?? '';
        $digest = $_POST['excerpt'] ?? '';
        $text = $_POST['content'] ?? '';
        $writer = $_POST['author'] ?? '';
        $licenseName = $_POST['license'] ?? 'CC BY-NC-SA 4.0';
        $uploadedFile = $_FILES['markdown_file'] ?? null;
        $fetchUrl = $_POST['url'] ?? '';
        $editTarget = $_POST['update_file'] ?? '';

        $isArchive = false;
        if ($uploadedFile && $uploadedFile['error'] === UPLOAD_ERR_OK) {
            $fileExt = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
            if ($fileExt === 'zip') {
                $isArchive = true;
                $archive = new ZipArchive();
                if ($archive->open($uploadedFile['tmp_name']) === true) {
                    $importedCount = 0;
                    // 防 zip bomb：限制文件数量与解压总大小
                    $archiveOk = true;
                    if ($archive->numFiles > 200) {
                        $archiveOk = false;
                        $saveErr = 'ZIP 内文件过多（最多 200 个），已拒绝导入';
                    }
                    $archiveBytes = 0;
                    for ($idx = 0; $archiveOk && $idx < $archive->numFiles; $idx++) {
                        $entryStat = $archive->statIndex($idx);
                        $archiveBytes += (int)($entryStat['size'] ?? 0);
                        if ($archiveBytes > 50 * 1024 * 1024) {
                            $archiveOk = false;
                            $saveErr = 'ZIP 解压总大小超限（最大 50MB），已拒绝导入';
                            break;
                        }
                        $entryPath = $archive->getNameIndex($idx);
                        if (substr($entryPath, -1) === '/' || strpos(basename($entryPath), '.') === 0) continue;
                        $entryExtLower = strtolower(pathinfo($entryPath, PATHINFO_EXTENSION));
                        if (!in_array($entryExtLower, ['md', 'txt', 'markdown'])) continue;
                        $leafName = basename($entryPath);
                        if (empty($leafName)) continue;
                        $slugName = preg_replace('/[^a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]/u', '', pathinfo($leafName, PATHINFO_FILENAME));
                        if (empty($slugName)) $slugName = 'doc_' . time() . '_' . $idx;
                        $destName = $slugName . '.md';
                        if (file_exists($articlesDir . '/' . $destName)) {
                            $destName = $slugName . '_' . time() . '.md';
                        }
                        $docBody = $archive->getFromIndex($idx);
                        if ($docBody === false) continue;
                        if (strlen($docBody) > 10 * 1024 * 1024) {
                            $archiveOk = false;
                            $saveErr = 'ZIP 内单个文件超过 10MB，已拒绝导入';
                            break;
                        }
                        if (!preg_match('/^<!--META/', $docBody)) {
                            $licenseUrls = ['CC BY 4.0' => 'https://creativecommons.org/licenses/by/4.0/', 'CC BY-SA 4.0' => 'https://creativecommons.org/licenses/by-sa/4.0/', 'CC BY-NC 4.0' => 'https://creativecommons.org/licenses/by-nc/4.0/', 'CC BY-NC-SA 4.0' => 'https://creativecommons.org/licenses/by-nc-sa/4.0/', 'CC BY-ND 4.0' => 'https://creativecommons.org/licenses/by-nd/4.0/', 'CC BY-NC-ND 4.0' => 'https://creativecommons.org/licenses/by-nc-nd/4.0/', 'CC0 1.0' => 'https://creativecommons.org/publicdomain/zero/1.0/'];
                            $metaJsonStr = json_encode(['category' => $cat, 'tags' => $tagStr, 'excerpt' => $digest, 'author' => $writer, 'author_id' => $myId, 'license' => $licenseName, 'licenseUrl' => ($licenseUrls[$licenseName] ?? '')], JSON_UNESCAPED_UNICODE);
                            $docBody = "<!--META" . $metaJsonStr . "-->\n" . $docBody;
                        }
                        if (file_put_contents($articlesDir . '/' . $destName, $docBody, LOCK_EX)) {
                            $importedCount++;
                        }
                    }
                    $archive->close();
                    @unlink($uploadedFile['tmp_name']);
                    if ($archiveOk) {
                        $saveOk = $importedCount > 0 ? 'ZIP 解压完成，共导入 ' . $importedCount . ' 篇文档' : 'ZIP 中未找到可识别的 Markdown 文件';
                        if ($importedCount === 0) $saveErr = 'ZIP 中未找到可识别的 Markdown 文件';
                    }
                } else {
                    $saveErr = '无法打开 ZIP 文件';
                }
            } else {
                $text = file_get_contents($uploadedFile['tmp_name']);
            }
        }

        if (!$isArchive && !empty($fetchUrl) && empty($text)) {
            // v3.0.6：SSRF 安全抓取——一次解析 + pin 解析后 IP 直连（Host/SNI 保留原域名），
            // 消除"先 gethostbyname 校验、后 file_get_contents 二次解析"的 DNS rebinding TOCTOU；内网/未识别默认拒绝
            $grabbed = fetchHttpContent($fetchUrl);
            if ($grabbed === false) { $saveErr = '无法从该链接获取内容'; }
            else { $text = $grabbed; }
        }

        if (empty($text) && !$saveErr) { $saveErr = '请提供 Markdown 内容'; }

        if (!$saveErr && !$isArchive) {
            if (empty($docTitle)) {
                $titleProbe = preg_replace('/```[\s\S]*?```/', '', $text);
                if (preg_match('/^#\s+(.+)/m', $titleProbe, $hit)) { $docTitle = $hit[1]; }
                else { $docTitle = '未命名文档'; }
            }
            $licenseUrls = ['CC BY 4.0' => 'https://creativecommons.org/licenses/by/4.0/', 'CC BY-SA 4.0' => 'https://creativecommons.org/licenses/by-sa/4.0/', 'CC BY-NC 4.0' => 'https://creativecommons.org/licenses/by-nc/4.0/', 'CC BY-NC-SA 4.0' => 'https://creativecommons.org/licenses/by-nc-sa/4.0/', 'CC BY-ND 4.0' => 'https://creativecommons.org/licenses/by-nd/4.0/', 'CC BY-NC-ND 4.0' => 'https://creativecommons.org/licenses/by-nc-nd/4.0/', 'CC0 1.0' => 'https://creativecommons.org/publicdomain/zero/1.0/'];
            $licenseLink = $licenseUrls[$licenseName] ?? '';
            // v4.0.0：发布状态——published 立即发布 / draft 存草稿 / scheduled 定时发布（publish_at 格式 Y-m-d\TH:i）
            $pubStatus = $_POST['publish_status'] ?? 'published';
            if (!in_array($pubStatus, ['published', 'draft', 'scheduled'], true)) $pubStatus = 'published';
            $pubAt = trim($_POST['publish_at'] ?? '');
            if ($pubStatus === 'scheduled' && $pubAt === '') $pubStatus = 'published';
            // 定时时间转 "Y-m-d H:i" 存 META（list/read 均按此格式比较）
            $pubAtStored = '';
            if ($pubAt !== '') {
                $pubAtStored = str_replace('T', ' ', $pubAt);
            }
            $frontMatter = ['category' => $cat, 'tags' => $tagStr, 'excerpt' => $digest, 'author' => $writer, 'author_id' => $myId, 'license' => $licenseName, 'licenseUrl' => $licenseLink];
            if ($pubStatus !== 'published') {
                $frontMatter['status'] = $pubStatus;
                if ($pubStatus === 'scheduled' && $pubAtStored !== '') $frontMatter['publish_at'] = $pubAtStored;
            } else {
                unset($frontMatter['status'], $frontMatter['publish_at']);
            }
            $metaJsonStr = json_encode($frontMatter, JSON_UNESCAPED_UNICODE);
            $finalBody = "<!--META" . $metaJsonStr . "-->\n" . $text;

            if (!empty($editTarget)) {
                $destLeaf = basename($editTarget);
                $destPath = $articlesDir . '/' . $destLeaf;
                if (file_exists($destPath)) {
                    if (!mayManageArticle(readArticleMeta($destPath))) {
                        $saveErr = '无权编辑该文章';
                        logUnauthorized('越权尝试编辑文章: ' . $destLeaf);
                    } elseif (file_put_contents($destPath, $finalBody, LOCK_EX)) {
                        $saveOk = '文档已更新';
                        auditLog('article_update', $destLeaf, '更新文档: ' . $docTitle);
                    } else { $saveErr = '保存失败'; }
                } else { $saveErr = '原文件不存在'; }
            } else {
                if ($uploadedFile && $uploadedFile['error'] === UPLOAD_ERR_OK) {
                    $origLeaf = pathinfo($uploadedFile['name'], PATHINFO_FILENAME);
                    $destLeaf = preg_replace('/[^a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]/u', '', $origLeaf);
                } else { $destLeaf = ''; }
                if (empty($destLeaf)) { $destLeaf = 'doc_' . time(); }
                $destLeaf .= '.md';
                if (file_put_contents($articlesDir . '/' . $destLeaf, $finalBody, LOCK_EX)) {
                    $saveOk = '文档已创建';
                    auditLog('article_create', $destLeaf, '创建文档: ' . $docTitle);
                } else { $saveErr = '保存失败'; }
            }
        }

        // v3.1.11：文章发布界面「作为公告」开关（仅站长可设）——
        // 开启 → 该文章在首页公告区展示；关闭 → 移除其公告。系统文章（更新历史）受保护不被误删。
        if ($saveOk && !$isArchive && $isStationAdmin && !empty($destLeaf)) {
            $lockedAnn = ['更新历史.md'];
            if (!in_array($destLeaf, $lockedAnn, true)) {
                $existingAnn = db_one('SELECT id FROM announcement WHERE article = ? LIMIT 1', [$destLeaf]);
                if (!empty($_POST['as_announce'])) {
                    if (!$existingAnn) {
                        addAnnouncement('manual', $destLeaf, $myId, $docTitle, $digest);
                        auditLog('announce_from_article', $destLeaf, '将文章设为公告: ' . $docTitle);
                    } else {
                        updateAnnouncement($existingAnn['id'], $destLeaf, $docTitle, $digest);
                    }
                } else {
                    if ($existingAnn) {
                        deleteAnnouncement($existingAnn['id']);
                        auditLog('announce_remove', $destLeaf, '取消文章公告: ' . $docTitle);
                    }
                }
            }
        }
    }
    // v3.3.5：纯 MD 上传/新建/编辑成功 → 触发守护进程立即备份（富媒体 zip 已在上面单独触发）
    if ($saveOk) triggerArticleBackup();
    $queryStr = $saveOk ? 'success=1' : 'error=' . urlencode($saveErr);
    header('Location: sc.php?' . $queryStr);
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

$mdFiles = glob($articlesDir . '/*.md');
$docItems = [];
$pinKeys = getPinnedList();
if ($mdFiles) {
    usort($mdFiles, function($a, $b) { return filemtime($b) - filemtime($a); });
    foreach ($mdFiles as $entryFile) {
        $leafFile = basename($entryFile);
        if (strpos($leafFile, '.') === 0) continue;
        $metaBox = readArticleMeta($entryFile);
        // v3.3.1：系统文章（更新历史）与隐藏文章不在文档管理列表显示（仅在公告侧展示，防误删）
        if (in_array($leafFile, ['更新历史.md'], true) || !empty($metaBox['hidden'])) continue;
        // 写作者仅显示自己的文章（最小权限）
        if (!$isStationAdmin && !mayManageArticle($metaBox)) continue;
        $rawText = file_get_contents($entryFile);
        $displayName = preg_replace('/\.md$/i', '', $leafFile);
        if (preg_match('/^#\s+(.+)/m', $rawText, $hit)) { $displayName = $hit[1]; }
        $pinnedFlag = in_array($leafFile, $pinKeys);
        $docItems[] = ['name' => $leafFile, 'displayName' => $displayName, 'pinned' => $pinnedFlag, 'meta' => $metaBox];
    }
}
usort($docItems, function($x, $y) {
    if ($x['pinned'] && !$y['pinned']) return -1;
    if (!$x['pinned'] && $y['pinned']) return 1;
    return 0;
});
$flagSaved = isset($_GET['success']);
$errText = $_GET['error'] ?? '';
$flagDeleted = isset($_GET['deleted']);
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
$siteTitle = loadSiteConfig()['site_title'] ?? 'You Super Markdown';
?>
<!DOCTYPE html>
<html lang="zh-CN" data-admin="station">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>文档管理 - <?= htmlspecialchars($siteTitle) ?></title>
<meta name="csrf-token" content="<?= htmlspecialchars(generateCsrfToken()) ?>">
<!-- v5.4.0-beta：AI 浮层组件样式（tw.min.css 先于 admin.css 加载，仅追加 .ai-* 组件类，不改既有选择器） -->
<link rel="stylesheet" href="css/tw.min.css?v=<?= @filemtime(__DIR__ . '/css/tw.min.css') ?>">
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
        <a href="#" onclick="bindLogoutSubmit(event)" class="sidebar-link danger">
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

    <?php if ($flagSaved): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= htmlspecialchars($saveOk ?: '操作成功') ?></div><?php endif; ?>
    <?php if ($errText): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= htmlspecialchars($errText) ?></div><?php endif; ?>
    <?php if ($flagDeleted): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>文档已删除</div><?php endif; ?>
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
                <!-- v4.1.6：富媒体包发布方式——立即发布 / 存草稿 / 定时发布（默认立即发布，与编辑器一致） -->
                <div class="form-group">
                    <label class="form-label">发布方式</label>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                        <select class="form-select" id="richPublishStatus" onchange="var r=document.getElementById('richPublishAtRow'); if(r) r.style.display=this.value==='scheduled'?'flex':'none'">
                            <option value="published">立即发布</option>
                            <option value="draft">存为草稿</option>
                            <option value="scheduled">定时发布</option>
                        </select>
                        <span class="form-hint">导入的多篇文档统一按此方式发布；默认立即发布</span>
                    </div>
                </div>
                <div class="form-group" id="richPublishAtRow" style="display:none">
                    <label class="form-label">发布时间</label>
                    <input type="datetime-local" class="form-input" id="richPublishAt">
                </div>
                <div class="form-group" id="richProgressWrap" style="display:none">
                    <div style="display:flex;justify-content:space-between;font-size:0.85em;color:var(--text-muted);margin-bottom:6px"><span id="richProgressText">上传中 0%</span><span id="richProgressSize"></span></div>
                    <div style="height:8px;background:#eef1f6;border-radius:999px;overflow:hidden"><div id="richProgressBar" style="height:100%;width:0%;background:linear-gradient(90deg,#2563eb,#06b6d4);border-radius:999px;transition:width .2s"></div></div>
                </div>
                <button type="button" class="btn btn-primary" id="richSubmitBtn">导入富媒体压缩包</button>
                <span class="form-hint" id="richStatusHint" style="margin-left:10px"></span>
            </div>
            <!-- v3.3.1-fix：纯文档（文件/链接/粘贴）通用字段区——富媒体 tab 激活时由 JS 隐藏，避免与富媒体自己的字段重复 -->
            <div id="docCommonFields">
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
            <!-- v4.0.0：发布状态（立即发布 / 存草稿 / 定时发布） -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">发布方式</label>
                    <select class="form-select" name="publish_status" onchange="this.form.querySelector('.publish-at-row').style.display = this.value==='scheduled' ? 'flex' : 'none'">
                        <option value="published" selected>立即发布</option>
                        <option value="draft">存为草稿（不公开）</option>
                        <option value="scheduled">定时发布</option>
                    </select>
                </div>
                <div class="form-group publish-at-row" style="display:none">
                    <label class="form-label">发布时间</label>
                    <input type="datetime-local" class="form-input" name="publish_at">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">上传文档</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            已有文章（<?= count($docItems) ?> 篇）
        </div>
        <?php if (empty($docItems)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <p>暂无文档</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <tr><th>标题</th><th>状态</th><th>置顶</th><th>查看</th><th>编辑</th><th>删除</th></tr>
            <?php foreach ($docItems as $f): ?>
            <?php
            // v4.0.0：列表状态标记（草稿 / 定时 / 已发布）
            $fStatus = $f['meta']['status'] ?? 'published';
            $fStatusLabel = '已发布';
            $fStatusBadge = '';
            if ($fStatus === 'draft') { $fStatusLabel = '草稿'; $fStatusBadge = ' style="color:#f59e0b"'; }
            elseif ($fStatus === 'scheduled') { $fStatusLabel = '定时'; $fStatusBadge = ' style="color:#3b82f6"'; }
            ?>
            <tr>
                <td style="font-weight:500"><?= htmlspecialchars($f['displayName']) ?><?php if ($fStatus !== 'published'): ?> <span class="status-badge"<?= $fStatusBadge ?>><?= $fStatusLabel ?></span><?php endif; ?></td>
                <td><?= $fStatusLabel ?></td>
                <td>
                    <button class="btn-link pin-btn <?= $f['pinned'] ? 'pinned' : '' ?>" data-name="<?= htmlspecialchars($f['name']) ?>" data-pinned="<?= $f['pinned'] ? '1' : '0' ?>"><?= $f['pinned'] ? '已置顶' : '置顶' ?></button>
                </td>
                <td><a class="btn-link" href="index.php?file=<?= urlencode($f['name']) ?>" target="_blank">查看</a></td>
                <td><button class="btn-link edit-article-btn" data-name="<?= htmlspecialchars($f['name']) ?>">编辑</button></td>
                <td><button class="btn-link danger delete-article-btn" data-name="<?= htmlspecialchars($f['name']) ?>" data-display="<?= htmlspecialchars($f['displayName']) ?>">删除</button></td>
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
            <button class="modal-close" onclick="closeModalById('deleteModal')">&times;</button>
        </div>
        <div class="modal-body">
            <p id="deleteModalText" style="font-size:0.92em;color:var(--text-secondary);margin-bottom:18px">确定要删除这篇文章吗？此操作不可撤销。</p>
            <div class="modal-actions">
                <button class="btn btn-outline" onclick="closeModalById('deleteModal')">取消</button>
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
            <button class="modal-close" onclick="closeModalById('editModal')" aria-label="关闭">&times;</button>
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
                        <?php if ($aiToolEnabled): ?>
                        <button type="button" class="btn btn-sm btn-outline" id="btnAiWrite" style="margin-left:8px">AI 写作</button>
                        <span class="form-hint" style="margin-left:8px">在下方选中文本后点此打开</span>
                        <?php endif; ?>
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
                <!-- v4.0.0：编辑时发布状态（立即发布 / 存草稿 / 定时发布） -->
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">发布方式</label>
                        <select class="form-select" name="publish_status" id="editPublishStatus" onchange="var r=this.closest('form').querySelector('.edit-publish-at-row');if(r)r.style.display=this.value==='scheduled'?'flex':'none'">
                            <option value="published">立即发布</option>
                            <option value="draft">存为草稿（不公开）</option>
                            <option value="scheduled">定时发布</option>
                        </select>
                    </div>
                    <div class="form-group edit-publish-at-row" style="display:none">
                        <label class="form-label">发布时间</label>
                        <input type="datetime-local" class="form-input" name="publish_at" id="editPublishAt">
                    </div>
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
                    <button type="button" class="btn btn-outline" onclick="closeModalById('editModal')">取消</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($aiToolEnabled): ?>
<!-- ===== v5.4.0-beta：AI 写作侧边浮层（选中文本 → 动作 → 流式 → 差异预览 → 插入/替换/撤销） ===== -->
<div class="ai-drawer" id="aiDrawer" style="display:none">
    <div class="ai-drawer-head">
        <span class="ai-drawer-title">AI 写作</span>
        <button type="button" class="ai-drawer-close" id="aiDrawerClose" aria-label="关闭">&times;</button>
    </div>
    <div class="ai-drawer-body">
        <div class="ai-group">
            <div class="ai-group-title">处理方式</div>
            <div class="ai-field">
                <label class="ai-label">动作</label>
                <div class="ai-actions" id="aiActionChips"></div>
            </div>
            <div class="ai-field" id="aiStyleWrap" style="display:none">
                <label class="ai-label">目标风格</label>
                <select class="ai-select" id="aiStyle"></select>
            </div>
            <div class="ai-field" id="aiLangWrap" style="display:none">
                <label class="ai-label">目标语言</label>
                <select class="ai-select" id="aiLang"></select>
            </div>
        </div>
        <div class="ai-group">
            <div class="ai-group-title">服务商与原文</div>
            <div class="ai-field">
                <label class="ai-label">服务商</label>
                <select class="ai-select" id="aiProvider"></select>
                <div class="ai-hint" id="aiProviderHint"></div>
            </div>
            <div class="ai-field">
                <label class="ai-label">选中文本</label>
                <div class="ai-src" id="aiSrc"></div>
            </div>
        </div>
        <div class="ai-run-row">
            <button type="button" class="ai-btn ai-btn-primary" id="aiRunBtn">开始生成</button>
            <span class="ai-status" id="aiStatus"></span>
        </div>
        <div class="ai-field ai-result" id="aiResultWrap" style="display:none">
            <div class="ai-diff">
                <div class="ai-diff-col"><div class="ai-diff-title">原文</div><div class="ai-diff-body" id="aiDiffOld"></div></div>
                <div class="ai-diff-col"><div class="ai-diff-title">结果</div><div class="ai-diff-body" id="aiDiffNew"></div></div>
            </div>
            <div class="ai-apply-row">
                <button type="button" class="ai-btn" id="aiInsertBtn">插入</button>
                <button type="button" class="ai-btn ai-btn-primary" id="aiReplaceBtn">替换</button>
                <button type="button" class="ai-btn" id="aiUndoBtn" style="display:none">撤销</button>
            </div>
        </div>
    </div>
</div>
<div class="ai-privacy-mask" id="aiPrivacyMask" style="display:none">
    <div class="ai-privacy-box">
        <h3>隐私提示</h3>
        <p>你选中的内容将发送至<b>你选择的第三方服务商</b>（AI 服务商）进行处理。请勿处理包含个人隐私或敏感信息的文本。</p>
        <p>额度由你的账号自担；本站仅做代理转发，不记录正文。</p>
        <div class="ai-apply-row" style="justify-content:flex-end">
            <button type="button" class="ai-btn" id="aiPrivacyCancel">取消</button>
            <button type="button" class="ai-btn ai-btn-primary" id="aiPrivacyOk">我已知晓，继续</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function closeModalById(id) { document.getElementById(id).classList.remove('active'); }
document.querySelectorAll('.modal-overlay').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === m) m.classList.remove('active'); });
});

function openDeleteConfirmModal(fn, dn) {
    document.getElementById('deleteModalText').textContent = '确定要删除「' + dn + '」吗？此操作不可撤销。';
    document.getElementById('deleteConfirmFile').value = fn;
    document.getElementById('deleteModal').classList.add('active');
}

// 方法切换（v3.3.1-fix：富媒体 tab 激活时隐藏纯文档通用字段区，避免字段重复）
document.querySelectorAll('.method-tab').forEach(function(b) {
    b.addEventListener('click', function() {
        document.querySelectorAll('.method-tab').forEach(function(x) { x.classList.remove('active'); });
        document.querySelectorAll('.method-panel').forEach(function(x) { x.classList.remove('active'); });
        b.classList.add('active');
        document.querySelector('.method-panel[data-panel="' + b.dataset.method + '"]').classList.add('active');
        var common = document.getElementById('docCommonFields');
        if (common) common.style.display = (b.dataset.method === 'rich') ? 'none' : '';
    });
});

// v5.0.0：文件选择器公共辅助（文档上传 / 富媒体 zip 两处交互共用，去重）
function ysmShowPickedFile(f, nm, info, dz, sizeText) { nm.textContent = f.name + ' (' + sizeText + ')'; info.style.display = 'flex'; dz.style.display = 'none'; }
function ysmResetFilePicker(fi, info, dz) { fi.value = ''; info.style.display = 'none'; dz.style.display = 'block'; }

// 文件上传
(function() {
    var dz = document.getElementById('uploadZone'), fi = document.getElementById('fileInput');
    var info = document.getElementById('fileInfo'), nm = document.getElementById('fileInfoName');
    var rb = document.getElementById('fileRemoveBtn');
    if (!dz) return;
    fi.addEventListener('change', function() { if (this.files.length) ysmShowPickedFile(this.files[0], nm, info, dz, (this.files[0].size/1024).toFixed(1) + ' KB'); });
    if (rb) rb.addEventListener('click', function() { ysmResetFilePicker(fi, info, dz); });
    ['dragenter','dragover'].forEach(function(e) { dz.addEventListener(e, function(ev) { ev.preventDefault(); dz.classList.add('dragover'); }); });
    ['dragleave','drop'].forEach(function(e) { dz.addEventListener(e, function(ev) { ev.preventDefault(); dz.classList.remove('dragover'); }); });
    dz.addEventListener('drop', function(e) {
        var f = e.dataTransfer.files;
        if (f.length && f[0].name.match(/\.(md|txt|markdown|zip)$/i)) { fi.files = f; ysmShowPickedFile(f[0], nm, info, dz, (f[0].size/1024).toFixed(1) + ' KB'); }
    });
})();

// v3.3.0：富媒体压缩包（md + 图片 + 视频）上传交互
(function() {
    var dz = document.getElementById('richZipZone'), fi = document.getElementById('richZipInput');
    var info = document.getElementById('richZipInfo'), nm = document.getElementById('richZipInfoName');
    var rb = document.getElementById('richZipRemoveBtn');
    if (!dz || !fi) return;
    fi.addEventListener('change', function() { if (this.files.length) ysmShowPickedFile(this.files[0], nm, info, dz, (this.files[0].size/1024/1024).toFixed(2) + ' MB'); });
    if (rb) rb.addEventListener('click', function() { ysmResetFilePicker(fi, info, dz); });
    ['dragenter','dragover'].forEach(function(e) { dz.addEventListener(e, function(ev) { ev.preventDefault(); dz.classList.add('dragover'); }); });
    ['dragleave','drop'].forEach(function(e) { dz.addEventListener(e, function(ev) { ev.preventDefault(); dz.classList.remove('dragover'); }); });
    dz.addEventListener('drop', function(e) {
        var f = e.dataTransfer.files;
        if (f.length && f[0].name.match(/\.zip$/i)) { fi.files = f; ysmShowPickedFile(f[0], nm, info, dz, (f[0].size/1024/1024).toFixed(2) + ' MB'); }
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
        // v4.1.6：发布方式（立即/草稿/定时）+ 定时时间
        fd.append('publish_status', (document.getElementById('richPublishStatus') || {}).value || 'published');
        fd.append('publish_at', (document.getElementById('richPublishAt') || {}).value || '');
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
    function updateEditorCharCount() { var l = ta.value.replace(/\s/g,'').length; ct.textContent = l > 0 ? l + ' 字' : ''; }
    ta.addEventListener('input', updateEditorCharCount);
})();

// 编辑弹窗
function openArticleEditor(fn) {
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
                // v4.0.0：回显发布状态与定时时间
                var st = d.meta.status || 'published';
                var stSel = document.getElementById('editPublishStatus');
                if (stSel) stSel.value = st;
                var paInput = document.getElementById('editPublishAt');
                var paRow = stSel ? stSel.closest('form').querySelector('.edit-publish-at-row') : null;
                if (st === 'scheduled' && d.meta.publish_at) {
                    if (paInput) paInput.value = String(d.meta.publish_at).replace(' ', 'T');
                }
                if (paRow) paRow.style.display = st === 'scheduled' ? 'flex' : 'none';
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
    if (ef) openArticleEditor(ef);
})();

// 置顶/取消置顶（index.php 的 POST 需要 CSRF token）
var scCsrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
// 登出走 POST + CSRF
function bindLogoutSubmit(e) {
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
// v5.0.0 P1-1：编辑/删除按钮改用 data-* 传值，不再把文章标题/文件名拼进内联 onclick（防存储型 XSS）
document.querySelectorAll('.edit-article-btn').forEach(function(btn) {
    btn.addEventListener('click', function() { openArticleEditor(this.dataset.name); });
});
document.querySelectorAll('.delete-article-btn').forEach(function(btn) {
    btn.addEventListener('click', function() { openDeleteConfirmModal(this.dataset.name, this.dataset.display); });
});
</script>
<?php if ($aiToolEnabled): ?>
<script>
// ============================================================================
// v5.4.0-beta：AI 写作侧边浮层（选中文本 → 动作 → 流式 SSE → 差异预览 → 插入/替换/撤销）
//   Key 永不触达前端；接口只回传"已配置(****末四位)/未配置"。
// ============================================================================
(function() {
    var $ = function(id) { return document.getElementById(id); };
    var drawer = $('aiDrawer');
    var ta = $('editContent');
    if (!drawer || !ta) return;
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var cfg = null, captured = '', result = '', lastSel = { start: 0, end: 0 };
    var undoValue = null, busy = false, finalized = false, curAction = 'polish';

    function api(action, method, body) {
        return fetch('api.php?action=' + action, {
            method: method || 'GET',
            headers: method === 'POST' ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf } : {},
            body: body ? JSON.stringify(body) : undefined
        }).then(function(r) { return r.json().catch(function() { return { success: false, error: 'HTTP ' + r.status }; }); });
    }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>]/g, function(c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); }
    // v5.4.0-beta.2：状态反馈分色（成功/失败/进行中），失败透出后端可读原因
    function setStatus(t, tone) {
        var el = $('aiStatus');
        if (!el) return;
        el.textContent = t || '';
        el.className = 'ai-status' + (tone ? ' is-' + tone : '');
    }
    // 统一读取后端可读原因：env_invalid 优先（提示重新登录），其次 error / message
    function reasonOf(d, fallback) {
        d = d || {};
        if (d.env_invalid) return '登录环境已变化，请重新登录';
        return d.message || d.error || fallback;
    }

    function tokenize(s) {
        var out = [], re = /[A-Za-z0-9]+|[\u4e00-\u9fff]|[^\s]|\s+/g, m;
        while ((m = re.exec(s))) out.push(m[0]);
        return out;
    }
    function diffHtml(oldS, newS) {
        var a = tokenize(oldS), b = tokenize(newS);
        if (a.length * b.length > 400000) {
            return { old: esc(oldS), 'new': esc(newS) };
        }
        var n = a.length, m = b.length, i, j, dp = [];
        for (i = 0; i <= n; i++) { dp.push(new Array(m + 1)); for (j = 0; j <= m; j++) dp[i][j] = 0; }
        for (i = n - 1; i >= 0; i--) for (j = m - 1; j >= 0; j--) {
            dp[i][j] = a[i] === b[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
        }
        var oh = '', nh = ''; i = 0; j = 0;
        while (i < n && j < m) {
            if (a[i] === b[j]) { oh += esc(a[i]); nh += esc(b[j]); i++; j++; }
            else if (dp[i + 1][j] >= dp[i][j + 1]) { oh += '<span class="ai-del">' + esc(a[i]) + '</span>'; i++; }
            else { nh += '<span class="ai-add">' + esc(b[j]) + '</span>'; j++; }
        }
        while (i < n) { oh += '<span class="ai-del">' + esc(a[i]) + '</span>'; i++; }
        while (j < m) { nh += '<span class="ai-add">' + esc(b[j]) + '</span>'; j++; }
        return { old: oh, 'new': nh };
    }

    function refreshProviderHint() {
        var pid = $('aiProvider').value, k = null, i;
        for (i = 0; i < (cfg.keys || []).length; i++) if (cfg.keys[i].provider === pid) k = cfg.keys[i];
        var hint = $('aiProviderHint');
        if (k && k.configured) hint.textContent = '已配置 ' + k.hint + ' · 模型 ' + (k.model || '未填');
        else hint.textContent = '未配置：请先在后台「AI 写作」中配置该服务商的 Key';
    }
    function syncActionFields() {
        $('aiStyleWrap').style.display = (curAction === 'style') ? '' : 'none';
        $('aiLangWrap').style.display = (curAction === 'translate') ? '' : 'none';
    }

    function buildForm() {
        var chips = '', i;
        for (var k in cfg.actions) {
            chips += '<button type="button" class="ai-chip' + (k === curAction ? ' active' : '') + '" data-action="' + k + '">' + esc(cfg.actions[k]) + '</button>';
        }
        $('aiActionChips').innerHTML = chips;
        var so = '';
        cfg.styles.forEach(function(s) { so += '<option value="' + esc(s) + '">' + esc(s) + '</option>'; });
        $('aiStyle').innerHTML = so;
        var lo = '';
        cfg.langs.forEach(function(s) { lo += '<option value="' + esc(s) + '">' + esc(s) + '</option>'; });
        $('aiLang').innerHTML = lo;
        var po = '';
        cfg.providers.forEach(function(p) { po += '<option value="' + esc(p.id) + '">' + esc(p.label) + '</option>'; });
        $('aiProvider').innerHTML = po;
        if (cfg.default_provider) $('aiProvider').value = cfg.default_provider;
        refreshProviderHint();
        syncActionFields();
    }

    $('aiActionChips').addEventListener('click', function(e) {
        var t = e.target.closest ? e.target.closest('.ai-chip') : null;
        if (!t) return;
        curAction = t.getAttribute('data-action');
        var all = this.querySelectorAll('.ai-chip');
        for (var i = 0; i < all.length; i++) all[i].classList.remove('active');
        t.classList.add('active');
        syncActionFields();
    });
    $('aiProvider').addEventListener('change', refreshProviderHint);

    function openDrawer() {
        var s = ta.selectionStart, e = ta.selectionEnd, sel = ta.value.slice(s, e);
        if (!sel.trim()) { alert('请先在编辑框中选中要处理的文本'); return; }
        captured = sel; lastSel = { start: s, end: e };
        $('aiSrc').textContent = sel;
        $('aiResultWrap').style.display = 'none';
        $('aiUndoBtn').style.display = 'none';
        result = ''; finalized = false; undoValue = null; setStatus('');
        drawer.style.display = 'flex';
        hideFab();
        if (!cfg.privacy_ack) $('aiPrivacyMask').style.display = 'flex';
    }
    function closeDrawer() { drawer.style.display = 'none'; hideFab(); }

    $('aiPrivacyOk').addEventListener('click', function() {
        $('aiPrivacyMask').style.display = 'none';
        api('ai_privacy_ack', 'POST', {}).then(function(d) { if (d && d.success) cfg.privacy_ack = true; });
    });
    $('aiPrivacyCancel').addEventListener('click', function() { $('aiPrivacyMask').style.display = 'none'; });

    function finalize() {
        finalized = true;
        var dv = diffHtml(captured, result);
        $('aiDiffOld').innerHTML = dv.old;
        $('aiDiffNew').innerHTML = dv['new'];
        setStatus('完成（' + result.length + ' 字）：可插入 / 替换', 'ok');
    }

    function run() {
        if (busy) return;
        if (!cfg.privacy_ack) { $('aiPrivacyMask').style.display = 'flex'; setStatus('请先确认隐私提示', 'err'); return; }
        var pid = $('aiProvider').value, k = null, i;
        for (i = 0; i < (cfg.keys || []).length; i++) if (cfg.keys[i].provider === pid) k = cfg.keys[i];
        if (!k || !k.configured) { setStatus('该服务商尚未配置 Key，请先到后台「AI 写作」配置', 'err'); return; }
        var style = $('aiStyle').value, lang = $('aiLang').value;
        busy = true; $('aiRunBtn').disabled = true; setStatus('生成中…', 'busy');
        result = ''; finalized = false;
        $('aiResultWrap').style.display = 'block';
        $('aiDiffOld').innerHTML = esc(captured);
        $('aiDiffNew').innerHTML = '';
        var err = '';
        fetch('api.php?action=ai_run', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ action: curAction, provider: pid, text: captured, style: style, lang: lang, stream: true })
        }).then(function(resp) {
            var ct = resp.headers.get('content-type') || '';
            if (ct.indexOf('application/json') >= 0) {
                return resp.json().then(function(d) { err = reasonOf(d, '调用失败'); });
            }
            if (!resp.body || !resp.body.getReader) { err = '当前浏览器不支持流式读取'; return; }
            var reader = resp.body.getReader(), dec = new TextDecoder(), buf = '';
            function handleBlock(block) {
                block.split('\n').forEach(function(line) {
                    if (line.indexOf('data:') !== 0) return;
                    var ev; try { ev = JSON.parse(line.slice(5).trim()); } catch (x) { return; }
                    if (ev.event === 'delta' && ev.text) {
                        result += ev.text;
                        $('aiDiffNew').textContent = result;
                        $('aiDiffNew').scrollTop = $('aiDiffNew').scrollHeight;
                    } else if (ev.event === 'error') { err = ev.message || '调用失败'; }
                });
            }
            function pump() {
                return reader.read().then(function(r) {
                    if (r.done || err) return;
                    buf += dec.decode(r.value, { stream: true });
                    var idx;
                    while ((idx = buf.indexOf('\n\n')) >= 0) { handleBlock(buf.slice(0, idx)); buf = buf.slice(idx + 2); }
                    return pump();
                });
            }
            return pump();
        }).then(function() {
            busy = false; $('aiRunBtn').disabled = false;
            if (err) { setStatus(err, 'err'); return; }
            if (result) finalize(); else setStatus('未获得结果', 'err');
        }).catch(function(e) {
            busy = false; $('aiRunBtn').disabled = false; setStatus((e && e.message) || '网络错误', 'err');
        });
    }

    function applyText(mode) {
        if (!result) return;
        undoValue = ta.value;
        var v = ta.value;
        if (mode === 'replace') {
            ta.value = v.slice(0, lastSel.start) + result + v.slice(lastSel.end);
            lastSel = { start: lastSel.start, end: lastSel.start + result.length };
        } else {
            ta.value = v.slice(0, lastSel.start) + result + v.slice(lastSel.start);
            lastSel = { start: lastSel.start, end: lastSel.start + result.length };
        }
        ta.dispatchEvent(new Event('input', { bubbles: true }));
        ta.focus();
        ta.selectionStart = ta.selectionEnd = lastSel.end;
        $('aiUndoBtn').style.display = '';
        setStatus(mode === 'replace' ? '已替换' : '已插入', 'ok');
    }

    $('aiRunBtn').addEventListener('click', run);
    $('aiInsertBtn').addEventListener('click', function() { applyText('insert'); });
    $('aiReplaceBtn').addEventListener('click', function() { applyText('replace'); });
    $('aiUndoBtn').addEventListener('click', function() {
        if (undoValue === null) return;
        ta.value = undoValue; undoValue = null;
        ta.dispatchEvent(new Event('input', { bubbles: true }));
        $('aiUndoBtn').style.display = 'none';
        setStatus('已撤销', 'ok');
    });
    $('aiDrawerClose').addEventListener('click', closeDrawer);
    var btn = $('btnAiWrite');
    if (btn) btn.addEventListener('click', openDrawer);

    // 选中文本后在编辑框右上角浮出入口（侧边浮层触发）
    var fab = document.createElement('div');
    fab.className = 'ai-fab';
    fab.textContent = 'AI 写作';
    fab.style.display = 'none';
    document.body.appendChild(fab);
    fab.addEventListener('mousedown', function(e) { e.preventDefault(); openDrawer(); });
    function hideFab() { fab.style.display = 'none'; }
    function maybeFab() {
        if (drawer.style.display === 'flex') return;
        var sel = ta.value.slice(ta.selectionStart, ta.selectionEnd);
        if (!sel.trim()) { hideFab(); return; }
        var r = ta.getBoundingClientRect();
        var left = Math.min(window.innerWidth - 90, Math.max(8, r.right - 96));
        var top = Math.max(8, r.top + 6);
        fab.style.left = left + 'px';
        fab.style.top = top + 'px';
        fab.style.display = '';
    }
    ta.addEventListener('select', maybeFab);
    ta.addEventListener('mouseup', maybeFab);
    ta.addEventListener('keyup', function(e) { if (e.shiftKey || e.key === 'Shift') maybeFab(); });
    window.addEventListener('scroll', hideFab, true);

    // 载入配置：决定动作/风格/语言/服务商与状态
    api('ai_config', 'GET').then(function(d) {
        if (!d || !d.success || !d.allowed) { if (btn) btn.disabled = true; return; }
        cfg = d; buildForm();
    }).catch(function() {});
})();
</script>
<?php endif; ?>
</body>
</html>