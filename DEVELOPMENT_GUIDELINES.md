# You Super Markdown 开发规范

> 本规范面向所有参与 You Super Markdown 开发的团队成员，明确**代码提交、文件组织、更新管理、安全开发**等统一标准。违反规范可能导致 CI 失败或更新包无法发布，请严格遵守。

---

## 一、代码提交规范（Git）

### 1.1 提交信息格式

所有提交信息使用 **Conventional Commits** 风格：

```
<type>(<scope>): <subject>
```

| 字段 | 说明 | 示例 |
|------|------|------|
| `type` | 提交类型（见下表） | `fix(update): 修复增量包识别` |
| `scope` | 影响范围（可选） | `admin` / `api` / `guard` / `update` / `config` / `docs` |
| `subject` | 一句话描述，**50 字符以内**，中文 | 描述"做了什么 + 为什么" |

**type 取值**：

| type | 用途 |
|------|------|
| `feat` | 新功能 |
| `fix` | 缺陷修复 |
| `docs` | 文档变更 |
| `refactor` | 重构（不改变行为） |
| `perf` | 性能优化 |
| `security` | 安全加固 |
| `chore` | 构建/工具/杂务 |
| `revert` | 回滚提交 |

**示例**：
```
feat(hfish): 新增超管后台蜜罐日志查看页签
fix(update): 修复上传包后 package_path 丢失导致更新失败
security(csrf): trigger_update 改用非消费型 token
chore: 初始化主库 v2.3.0 源码
```

### 1.2 提交前检查

1. **不得提交敏感信息**：密码、密钥、`.pem`/`.key`、`data/` 用户数据、`.pw.txt` 等一律不提交（已由 `.gitignore` 排除）
2. **不得提交构建产物**：`*.tar.gz`、`*.zip`、`staging/`、`inc-stage/`、`v221-base/` 等
3. **提交前自测**：`php -l` 语法检查 PHP 文件；`bash -n` 检查 Shell 脚本；Python 文件至少 `python3 -m py_compile`
4. **一次提交只做一件事**：不要将无关改动混入同一提交
5. **提交前确认无调试残留**：禁止提交 `debug*.php`、`test*.php`、`*.log` 等临时/调试文件

### 1.3 分支策略

| 分支 | 用途 |
|------|------|
| `master` | 主分支，唯一可发布版本，受保护，禁止直接推送 |
| `dev` | 开发集成分支，功能先合入此处 |
| `feature/*` | 功能分支，如 `feature/hfish-sync` |
| `fix/*` | 修复分支，如 `fix/apply-update` |

流程：`feature/*` → `dev` → 评审 → `master`。

### 1.4 版本标记（Tag）

每次发布打 tag：`v<主>.<次>.<修订>`，如 `v2.3.0`。Tag 信息与更新包版本号、`app-config.json` 中的 `version` 必须**三者一致**。

---

## 二、文件组织规范

### 2.1 目录结构（只增不删，保持稳定）

```
you-markdown/
├── index.php              # 网站首页 / 文章阅读
├── api.php                # REST API 接口
├── utils.php              # 核心工具（角色、JWT、日志、CSRF、更新）
├── sc.php                 # Markdown 编辑器
├── 404.php                # 404 页面
├── music.php              # 音乐播放路由
├── admin/                 # 高级管理员后台（OTP 入口）
│   ├── entry.php
│   └── dashboard.php
├── station/               # 站长后台
│   └── dashboard.php
├── author/                # 写作者后台
│   └── dashboard.php
├── css/  js/  fonts/      # 静态资源
├── music/                 # 音乐平台接口
├── data/                  # 数据目录（Nginx 隔离，不入库）
├── ym-guard.py            # 文件守护进程（Python）
├── ym-hfish-sync.py       # 蜜罐同步脚本（Python）
├── ym-install.sh          # 一键安装脚本
├── ym-admin               # CLI 管理工具
├── app-config.json        # 全局配置（版本号、仓库、蜜罐）
└── nginx-site.conf        # Nginx 配置模板
```

### 2.2 新增文件要求

1. **先找归属目录**：PHP 业务放对应模块目录；工具脚本放根目录（`ym-` 前缀）；静态资源放对应资源目录
2. **命名规范**：PHP 文件小写下划线（如 `dashboard.php`）；Python/Shell 工具统一 `ym-` 前缀
3. **禁止**：在根目录随意堆放调试文件（`debug*`、`test*`、`entry_fixed*` 等），一经发现删除
4. **新增敏感文件**（含用户数据/密钥）必须确认被 `.gitignore` 覆盖

### 2.3 版本号统一

版本号定义在 **`app-config.json` 的 `version` 字段**（唯一事实来源），`utils.php` 通过 `appConfig('version')` 读取，禁止硬编码版本号到业务代码。

---

## 三、更新管理规范

### 3.1 更新包统一存放

**所有更新包只存放于 `f:\测试白盒\update-package`**，禁止在其他目录（如各源码目录内）创建 `update-package` 或类似目录。

### 3.2 更新包命名

| 类型 | 命名规则 | 示例 |
|------|---------|------|
| 全量包 | `you-super-markdown-v<版本>-full.tar.gz` | `you-super-markdown-v2.3.0-full.tar.gz` |
| 增量包 | `you-super-markdown-v<from>-to-v<to>-inc.tar.gz` | `you-super-markdown-v2.2.1-to-v2.3.0-inc.tar.gz` |

### 3.3 全量包要求

1. 根目录必须含 `version.json`（`{"version":"x.y.z"}`）
2. 必须含核心文件 `index.php` / `utils.php`
3. **排除 `data/`**（用户数据不打包）
4. 打包前确认：版本号已统一、无调试文件、无临时目录

### 3.4 增量包要求

1. 仅包含**变更文件** + `version.json`
2. `version.json` 必须为：`{"version":"<to>","type":"incremental","from":"<from>"}`
3. 应用方式为**覆盖式合并**（不删除目标多余文件）
4. 生成增量包前，用 MD5 对比确认变更文件清单准确（`git diff` 可作为依据）

### 3.5 更新流程

```
后台触发（上传包/检查更新）
  → SSH 生成挑战码（sudo ym-admin challenge，300 秒有效）
  → 后台输入挑战码（触发 trigger_update，写入更新请求）
  → SSH 执行 sudo ym-admin apply-update（备份→停守护→解锁母本→应用→锁定→重启→审计）
  → 后台轮询状态确认成功
```

**注意**：
- 触发更新前必须上传更新包，`package_path` 随请求传递（路径以 `/tmp/ym-update-packages/` 开头）
- 请求文件 `/tmp/ym-update-request.json` 创建后须 `chmod 0666`（容器环境 root 权限受限）
- `apply-update` 写请求文件前先 `chmod 666`

### 3.6 版本升级步骤

1. 修改 `app-config.json` 版本号
2. 同步更新 `README.md` 标题、`ym-guard.py`、`ym-install.sh`、`css/admin.css` 中的版本注释
3. 功能/修复代码就绪后，按 3.3/3.4 制作更新包到 `f:\测试白盒\update-package`
4. 更新 `f:\测试白盒\update-package\version.json`
5. 打 git tag 并推送

---

## 四、安全开发规范（强制）

1. **角色鉴权**：每个请求先读角色再判断操作权限；禁止绕过 `checkRole()`/`validateJWT()`
2. **CSRF**：POST 表单/AJAX 必须校验 token；一次会话内可多次操作时用 `checkCsrfToken()`（非消费型），单次高敏操作可用 `verifyCsrfToken()`（消费型），两者不得混用导致冲突
3. **挑战码机制**：服务器挑战应答码 300 秒有效、单次使用；敏感操作（更新、用户管理、守护控制）必须验证
4. **日志**：所有操作写审计日志（哈希链）；禁止绕过 `auditLog()`
5. **数据隔离**：`data/*.json` 禁止公网直连（Nginx deny）；不在日志/报错中输出完整敏感路径
6. **输入校验**：所有用户输入 `htmlspecialchars()` 转义；上传路径必须白名单校验（如 `/tmp/ym-update-packages/` 前缀）
7. **文件写入**：`file_put_contents` 一律使用 `LOCK_EX`
8. **密钥**：密钥不落盘/不入库；生产密码等敏感值通过 SSH/环境注入
9. **守护进程**：核心文件被篡改会从母本恢复，本地源码与服务器必须保持同步

---

## 五、代码风格

| 语言 | 规范 |
|------|------|
| PHP | PSR-12 兼容；`<?php` 起始；函数下划线命名；严格比较 `===` |
| Python | PEP8；`snake_case`；模块顶部 docstring 注明版本号 |
| Shell | `set -euo pipefail`（部署脚本）；变量加引号；`2>/dev/null || true` 处理非关键错误 |
| SQL | 仅参数化查询，禁止字符串拼接 |

注释：复杂逻辑必须注释"为什么"；中文注释；禁止大段冗余注释。

---

## 六、测试规范

1. **部署前**：执行 `sudo bash ym-install.sh` 或 SSH 手动部署脚本验证安装
2. **功能测试**：验证三大后台（超管/站长/写作者）核心操作、蜜罐页签、挑战码流程
3. **更新测试**：全量包与增量包各执行一次完整更新流程，确认版本号、文件完整性、`data/` 保留
4. **攻击测试**：验证五层防御（OTP 动态入口、角色分权、审计哈希链、文件守护、数据隔离）
5. **回归**：修改后确认旧功能不受影响（登录、评论、文章、背景）

---

## 七、禁止事项

- ❌ 修改 `data/` 下生产数据直接提交到 git
- ❌ 在源码目录创建 `update-package/`、`staging/`、`inc-stage/` 等打包目录
- ❌ 提交包含密码/密钥/日志的文件
- ❌ 绕过挑战码/CSRF/权限校验执行敏感操作
- ❌ 直接修改服务器文件而不经过更新流程（调试除外，且必须事后通过更新包同步）
- ❌ 删除或重命名 `utils.php` 中的核心函数（`checkRole`/`checkCsrfToken`/`auditLog`/`appConfig` 等）

---

## 八、工作区结构说明

```
f:\测试白盒\
├── test_development\        # 主库（v2.3.0，Git 管理，唯一开发源）
├── test_development copy\   # 备份副本（与主库同步，不直接开发）
└── update-package\          # 更新包统一存放目录（唯一）
```

- 开发只改主库 `test_development`
- 更新包只产出到 `update-package`
- 备份副本仅用于对照，改动后须同步回主库
