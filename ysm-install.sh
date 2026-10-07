#!/bin/bash
# ================================================================
# You Super Markdown 一键安装脚本（版本读取自 app-config.json）
# 功能：部署源码 + 配置 Nginx + 守护进程 + 防火墙 + SSL + CLI 工具
# 使用：sudo bash ysm-install.sh
# ================================================================
set -e

# 版本号：唯一事实来源为 app-config.json（代码禁止硬编码版本；v2.10.2 起用 grep 读取，
# 不依赖 php——全新服务器 php 未装时 php 命令不存在会导致 APP_VER 显示 v0.0.0）
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_VER=$(grep -oP '"version"\s*:\s*"\K[0-9.]+' "$SCRIPT_DIR/app-config.json" 2>/dev/null | head -1)
if [ -z "$APP_VER" ]; then APP_VER="0.0.0"; fi

# 解析命令行参数（支持全自动/半自动，小白也可全部回车走默认）
INSTALL_HFISH=true
AUTO_YES=false
GEN_SIGNING_KEY=false
DOMAIN_ARG=""
WEB_ROOT_ARG=""
EMAIL_ARG=""
HFISH_PASSWORD_ARG=""
HFISH_PANEL_PORT_ARG=""
HFISH_NODE_PORT_ARG=""
for arg in "$@"; do
    case $arg in
        --skip-hfish)
            INSTALL_HFISH=false
            ;;
        --yes|-y)
            # v4.7.3：无人值守模式已移除（安装必须交互 + SMTP 双向验证，不通过终止）
            warn "--yes 已不再支持：安装必须交互完成（SMTP 双向验证需人工回填确认码），继续按交互模式执行"
            AUTO_YES=false
            ;;
        --domain=*)
            DOMAIN_ARG="${arg#*=}"
            ;;
        --web-root=*)
            WEB_ROOT_ARG="${arg#*=}"
            ;;
        --email=*)
            EMAIL_ARG="${arg#*=}"
            ;;
        --hfish-password=*)
            HFISH_PASSWORD_ARG="${arg#*=}"
            ;;
        --hfish-port-panel=*)
            HFISH_PANEL_PORT_ARG="${arg#*=}"
            ;;
        --hfish-port-node=*)
            HFISH_NODE_PORT_ARG="${arg#*=}"
            ;;
        --help|-h)
            echo "用法: sudo bash ysm-install.sh [选项]"
            echo ""
            echo "选项（可与环境变量互换，参数优先）:"
            echo "  --domain=域名       站点域名（等价环境变量 YSM_DOMAIN）"
            echo "  --web-root=路径     Web 根目录（默认 /var/www/you-super-markdown，等价 YSM_WEB_ROOT）"
            echo "  --email=邮箱        管理员邮箱（必填：告警收件人 + 超管设备验证通道，等价 YSM_ADMIN_EMAIL）"
            echo "  --skip-hfish       跳过 Hfish 蜜罐安装"
            echo "  --gen-signing-key  生成自定义更新签名信任根（交互式，含四次确认；默认使用安装包内官方公钥）"
            echo "  --hfish-password=密 蜜獾账户密码（留空自动生成强密码，等价 YSM_HFISH_PASSWORD）"
            echo "  --hfish-port-panel=端口  蜜獾管理面板端口（默认 4433，自动检测占用，等价 YSM_HFISH_PANEL_PORT）"
            echo "  --hfish-port-node=端口   蜜獾节点通信端口（默认 4434，等价 YSM_HFISH_NODE_PORT）"
            echo "  --help, -h         显示此帮助信息"
            echo ""
            echo "示例:"
            echo "  sudo bash ysm-install.sh                              # 交互式（小白默认流程）"
            echo "  sudo bash ysm-install.sh --yes --domain=blog.example.com   # 全自动"
            echo "  YSM_DOMAIN=x.example.com sudo bash ysm-install.sh      # 环境变量方式"
            exit 0
            ;;
    esac
done

# 端口占用检测辅助：被占用则自动 +1 直到空闲
pick_free_port() {
    local port="$1"
    while ss -tln 2>/dev/null | awk '{print $4}' | grep -q ":${port}$"; do
        warn "端口 $port 已被占用，自动改用 $((port + 1))"
        port=$((port + 1))
    done
    echo "$port"
}

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

log()  { echo -e "${GREEN}[+]${NC} $1"; }
warn() { echo -e "${YELLOW}[!]${NC} $1"; }
err()  { echo -e "${RED}[x]${NC} $1"; exit 1; }
info() { echo -e "${CYAN}[*]${NC} $1"; }

# ================================================================
# 0. 前置检查
# ================================================================
echo ""
echo "============================================"
echo "  You Super Markdown v${APP_VER} 安装脚本"
echo "  纵深防御方案 — 五层防线一键部署"
echo "============================================"
echo ""

if [ "$EUID" -ne 0 ]; then
    err "请使用 root 权限运行此脚本 (sudo bash ysm-install.sh)"
fi

# 检测系统
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$ID
else
    OS="unknown"
fi

log "检测到系统: $OS"

# 安装依赖
log "安装系统依赖..."
case $OS in
    ubuntu|debian)
        apt-get update -qq 2>&1 | tail -3 || true
        # dpkg 锁等待（v2.10.2 公网部署实测：系统刚启动时 unattended-upgrades 可能占用 dpkg 锁，
        # apt-get 立即失败 + set -e 静默退出——此处轮询等待锁释放）
        if command -v fuser >/dev/null 2>&1; then
            for _i in 1 2 3 4 5 6; do
                if fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 || fuser /var/lib/dpkg/lock >/dev/null 2>&1; then
                    warn "dpkg 锁被占用（可能为 unattended-upgrades），等待 10 秒后重试..."
                    sleep 10
                else
                    break
                fi
            done
        fi
        # 检测已安装的 PHP 版本；未安装则按可用版本探测（Ubuntu 24.04=noble 默认 8.3，
        # 禁止回退写死 8.4——php8.4-fpm 在该发行版不存在会导致依赖安装失败，见踩坑 #30）
        PHP_VER=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null || true)
        if [ -z "$PHP_VER" ]; then
            for _v in 8.4 8.3 8.2 8.1; do
                if apt-cache policy "php${_v}-fpm" 2>/dev/null | grep -q "Candidate: [0-9]"; then
                    PHP_VER="$_v"; break
                fi
            done
        fi
        [ -z "$PHP_VER" ] && PHP_VER="8.3"
        log "检测到 PHP 版本: $PHP_VER"
        # 依赖安装：失败时显示错误（v2.10.2 修复：原 > /dev/null 2>&1 + set -e 使失败静默终止，无法定位）
        # v4.6.0：补装 ffmpeg（背景音乐转码依赖，与更新通道 deps 声明一致）
        if ! apt-get install -y nginx "php${PHP_VER}-fpm" "php${PHP_VER}-cli" "php${PHP_VER}-zip" "php${PHP_VER}-mbstring" "php${PHP_VER}-curl" "php${PHP_VER}-sqlite3" "php${PHP_VER}-gd" ffmpeg certbot python3 python3-pip ufw 2>&1 | tail -15; then
            warn "标准包名安装失败（php${PHP_VER} 可能不在当前源），尝试通用包名..."
            if ! apt-get install -y nginx php-fpm php-cli php-zip php-mbstring php-curl php-sqlite3 php-gd ffmpeg certbot python3 python3-pip ufw 2>&1 | tail -15; then
                warn "依赖安装失败！请检查 apt 源/网络后重新运行本脚本；上方输出为具体错误"
            fi
        fi
        ;;
    centos|rhel|fedora)
        yum install -y -q nginx php php-fpm php-zip php-mbstring php-curl php-pdo php-sqlite3 php-gd ffmpeg certbot python3 python3-pip > /dev/null 2>&1
        ;;
    *)
        warn "未识别的系统，请手动安装: nginx, php8.x, python3, certbot"
        ;;
esac

# 安装 Python 依赖
pip3 install inotify 2>/dev/null || warn "inotify 安装失败，将使用轮询模式"

# 配置 PHP 时区与系统一致（v2.8.0：PHP 默认 UTC 会导致邮件/日志时间戳慢 8 小时）
configure_php_timezone() {
    local tz
    tz=$(cat /etc/timezone 2>/dev/null || timedatectl 2>/dev/null | awk -F': ' '/Time zone/{print $2}' | awk '{print $1}' || true)
    tz=${tz:-Asia/Shanghai}
    for ini in /etc/php/*/cli/php.ini /etc/php/*/fpm/php.ini; do
        [ -f "$ini" ] || continue
        if grep -q '^date.timezone' "$ini"; then
            sed -i "s|^date.timezone.*|date.timezone = $tz|" "$ini"
        elif grep -q '^;date.timezone' "$ini"; then
            sed -i "s|^;date.timezone.*|date.timezone = $tz|" "$ini"
        else
            echo "date.timezone = $tz" >> "$ini"
        fi
    done
    local fpm_svc="php-fpm"
    if [ -n "${PHP_VER:-}" ]; then fpm_svc="php${PHP_VER}-fpm"; fi
    systemctl restart "$fpm_svc" > /dev/null 2>&1 || systemctl restart php-fpm > /dev/null 2>&1 || true
    log "PHP 时区已配置: $tz（CLI/FPM，邮件与日志时间戳）"

    # v3.3.1：PHP 上传上限（富媒体压缩包批量上传 ≤80MB / 视频 ≤20MB / 背景图等）
    for ini in /etc/php/*/cli/php.ini /etc/php/*/fpm/php.ini; do
        [ -f "$ini" ] || continue
        sed -i "s|^upload_max_filesize.*|upload_max_filesize = 100M|" "$ini"
        sed -i "s|^;upload_max_filesize.*|upload_max_filesize = 100M|" "$ini"
        sed -i "s|^post_max_size.*|post_max_size = 105M|" "$ini"
        sed -i "s|^;post_max_size.*|post_max_size = 105M|" "$ini"
        if ! grep -q '^upload_max_filesize' "$ini"; then echo "upload_max_filesize = 100M" >> "$ini"; fi
        if ! grep -q '^post_max_size' "$ini"; then echo "post_max_size = 105M" >> "$ini"; fi
    done
    systemctl restart "$fpm_svc" > /dev/null 2>&1 || systemctl restart php-fpm > /dev/null 2>&1 || true
    log "PHP 上传上限已配置: upload_max_filesize=100M / post_max_size=105M"
}
configure_php_timezone

# ================================================================
# 1. 参数收集
# ================================================================
echo ""
log "请提供以下部署信息（直接回车使用默认值；全自动模式 --yes 跳过本环节）"

# 域名：--domain / YSM_DOMAIN > 交互输入（必填）
DOMAIN="${DOMAIN_ARG:-${YSM_DOMAIN:-}}"
if [ -z "$DOMAIN" ]; then
    if [ "$AUTO_YES" = true ]; then
        err "全自动模式需提供域名: --domain=你的域名 (或环境变量 YSM_DOMAIN)"
    fi
    read -p "  域名 (必填，如 youmarkdown.example.com): " DOMAIN
    if [ -z "$DOMAIN" ]; then
        err "域名不能为空"
    fi
fi

# Web 根目录：--web-root / YSM_WEB_ROOT > 交互默认 /var/www/you-super-markdown
WEB_ROOT="${WEB_ROOT_ARG:-${YSM_WEB_ROOT:-}}"
if [ -z "$WEB_ROOT" ]; then
    if [ "$AUTO_YES" = true ]; then
        WEB_ROOT="/var/www/you-super-markdown"
    else
        read -p "  Web 根目录 (默认 /var/www/you-super-markdown): " WEB_ROOT
        WEB_ROOT=${WEB_ROOT:-/var/www/you-super-markdown}
    fi
fi

# v4.7.3：管理员邮箱——必填 + 格式校验（该邮箱 = 告警收件人 + 超管设备二次验证码发送目标；超管无独立绑定邮箱）
ADMIN_EMAIL="${EMAIL_ARG:-${YSM_ADMIN_EMAIL:-}}"
valid_email() {
    case "$1" in
        *@*.*) return 0 ;;
        *) return 1 ;;
    esac
}
if [ -z "$ADMIN_EMAIL" ] && [ "$AUTO_YES" != true ]; then
    while :; do
        read -r -p "  管理员邮箱 (必填: 告警收件人 + 超管设备二次验证通道): " ADMIN_EMAIL
        ADMIN_EMAIL=$(printf '%s' "$ADMIN_EMAIL" | tr -d '[:space:]')
        if valid_email "$ADMIN_EMAIL"; then
            break
        fi
        warn "邮箱格式不正确或不能为空，请重新输入（如 admin@example.com）"
    done
fi
if ! valid_email "$ADMIN_EMAIL"; then
    # v5.3.0：管理员邮箱改为强制必填（缺失/留空直接阻断安装）——它是告警/更新通知收件人，也是超管设备二次验证通道
    err "管理员邮箱必填（告警/更新通知收件人 + 超管设备二次验证通道）：请交互输入合法邮箱，或以 --email=admin@example.com（或环境变量 YSM_ADMIN_EMAIL）提供后重试"
fi

# v2.9.0：注册验证模式（正式版默认启用 / 测试版默认禁用，后台「注册验证」可随时切换）
VERIFY_MODE="${VERIFY_MODE_ARG:-production}"
if [ "$AUTO_YES" != true ] && [ -z "$VERIFY_MODE_ARG" ]; then
    echo ""
    read -p "  注册验证模式 (production=正式版默认启用验证 / test=测试版默认禁用, 默认 production): " VERIFY_MODE
    [ -z "$VERIFY_MODE" ] && VERIFY_MODE="production"
fi
if [ "$VERIFY_MODE" != "test" ]; then VERIFY_MODE="production"; fi
if [ "$VERIFY_MODE" = "production" ]; then
    # v2.11.0：滑块人机验证已彻底移除（VERIFY_CAPTCHA_FLAG 删除），仅邮箱验证 + 双重确认
    VERIFY_EMAIL_FLAG=true; VERIFY_DUAL_FLAG=true
else
    VERIFY_EMAIL_FLAG=false; VERIFY_DUAL_FLAG=false
fi

echo ""
info "部署参数确认："
echo "  域名:       $DOMAIN"
echo "  Web 根目录: $WEB_ROOT"
echo "  管理员邮箱: ${ADMIN_EMAIL:-未设置}"
echo "  Hfish 蜜罐: $([ "$INSTALL_HFISH" = true ] && echo '安装' || echo '跳过（--skip-hfish）')"
echo "  注册验证:   $([ "$VERIFY_MODE" = production ] && echo '正式版（默认启用邮箱验证码/滑块/双重确认）' || echo '测试版（默认禁用，可在超管后台开启）')"
echo ""
if [ "$AUTO_YES" != true ]; then
    read -p "确认继续? (y/n): " confirm
    if [ "$confirm" != "y" ]; then
        err "已取消"
    fi
fi

# ================================================================
# 2. 部署项目文件
# ================================================================
log "部署项目文件到 $WEB_ROOT ..."

# 获取脚本所在目录（项目源码目录）
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# 创建 Web 根目录
mkdir -p "$WEB_ROOT"

# 拷贝源码（排除不需要的文件；自身 ysm-install.sh 不部署进 Web 根，
# 纵深防御：即使 nginx 配置失误，攻击者也无法通过 Web 下载安装脚本源码）
rsync -av --exclude='恢复.zip' --exclude='test_*.py' --exclude='__pycache__' \
    --exclude='ysm-install.sh' "$SCRIPT_DIR/" "$WEB_ROOT/" > /dev/null 2>&1 || \
    cp -r "$SCRIPT_DIR/"* "$WEB_ROOT/" 2>/dev/null

# 设置权限
chown -R root:www-data "$WEB_ROOT"
find "$WEB_ROOT" -type d -exec chmod 755 {} \;
find "$WEB_ROOT" -type f -exec chmod 644 {} \;
chmod 755 "$WEB_ROOT/admin" "$WEB_ROOT/station" "$WEB_ROOT/author" 2>/dev/null || true

# data 目录可写（775：www-data 组可写，供 CLI 只读命令无 sudo 读取 SQLite/WAL）
if [ -d "$WEB_ROOT/data" ]; then
    chown -R www-data:www-data "$WEB_ROOT/data"
    chmod 775 "$WEB_ROOT/data"
fi

# 创建必要的子目录
# v3.3.12：data/cache/thumbs 为缩略图缓存目录（img.php 写入，需 www-data 可写）
mkdir -p "$WEB_ROOT/data/articles" "$WEB_ROOT/data/bg" "$WEB_ROOT/data/avatars" "$WEB_ROOT/data/cache/thumbs"
chown -R www-data:www-data "$WEB_ROOT/data"

# 将调用本脚本的管理员加入 www-data 组（CLI 只读命令无需 sudo 即可读取 SQLite）
if [ -n "${SUDO_USER:-}" ] && [ "$SUDO_USER" != "root" ]; then
    usermod -aG www-data "$SUDO_USER" 2>/dev/null || warn "无法将 $SUDO_USER 加入 www-data 组，只读 CLI 命令请使用 sudo"
fi

log "文件部署完成"

# 写入站点域名到 app-config.json（供 ysm-admin 生成管理入口 URL；v2.10.2 起禁止 ysm-admin 硬编码域名）
# v5.0.0：域名经 base64 环境变量传入，避免直接拼进 php -r 代码字符串（防引号/特殊字符注入）
DOMAIN_B64=$(printf '%s' "$DOMAIN" | base64 -w0 2>/dev/null || printf '%s' "$DOMAIN" | base64)
DOMAIN_B64="$DOMAIN_B64" php -r "
    \$p = '$WEB_ROOT/app-config.json';
    if (file_exists(\$p)) {
        \$c = json_decode(file_get_contents(\$p), true) ?: [];
        \$c['site_url'] = base64_decode(getenv('DOMAIN_B64'));
        file_put_contents(\$p, json_encode(\$c, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX);
    }
" 2>/dev/null
log "站点域名已写入 app-config.json: $DOMAIN"

# ================================================================
# 3. 初始化管理员
# ================================================================
log "初始化高级管理员账号..."

SUPER_PASSWORD=$(openssl rand -base64 12 | tr -d '=+/')
SUPER_PASSWORD_HASH=$(php -r "echo password_hash('$SUPER_PASSWORD', PASSWORD_DEFAULT);")
SUPER_ID=$(openssl rand -hex 8)
SUPER_ACCOUNT="admin_$(openssl rand -hex 4)"

# 生成 JWT 密钥
mkdir -p "$WEB_ROOT/data"
JWT_SECRET=$(openssl rand -hex 32)
echo "$JWT_SECRET" > "$WEB_ROOT/data/.jwt_secret"
chmod 600 "$WEB_ROOT/data/.jwt_secret"
chown www-data:www-data "$WEB_ROOT/data/.jwt_secret"

# 初始化管理员与站点配置（v5.0.0 起直接写 SQLite；schema 由 db.php db_init_schema() 建立，
# 不再落盘 JSON 种子文件、也不再依赖一次性 JSON→SQLite 迁移脚本）
SUPER_ID="$SUPER_ID" SUPER_ACCOUNT="$SUPER_ACCOUNT" SUPER_PASSWORD_HASH="$SUPER_PASSWORD_HASH" \
ADMIN_EMAIL="$ADMIN_EMAIL" VERIFY_EMAIL_FLAG="$VERIFY_EMAIL_FLAG" VERIFY_DUAL_FLAG="$VERIFY_DUAL_FLAG" \
php -r "
    require '$WEB_ROOT/utils.php';
    replaceAllUsers([[
        'id' => getenv('SUPER_ID'),
        'account' => getenv('SUPER_ACCOUNT'),
        'nickname' => '高级管理员',
        'password' => getenv('SUPER_PASSWORD_HASH'),
        'avatar' => '',
        'signature' => '高级管理员',
        'role' => 'super_admin',
        'created' => date('Y-m-d H:i:s'),
    ]]);
    \$cfg = loadSiteConfig();
    \$cfg['site_title'] = 'You Super Markdown';
    \$cfg['registration_enabled'] = true;
    \$cfg['guest_comments_enabled'] = false;
    \$cfg['admin_email'] = getenv('ADMIN_EMAIL');
    \$cfg['update_channel'] = 'stable';
    \$cfg['auto_ban'] = true;
    \$cfg['auto_ban_unauthorized'] = true;
    \$cfg['max_login_fails'] = 10;
    \$cfg['station_path'] = 'station';
    \$cfg['author_path'] = 'author';
    \$cfg['hide_default_paths'] = true;
    \$cfg['email_verify_enabled'] = getenv('VERIFY_EMAIL_FLAG') === 'true';
    \$cfg['author_dual_verify_enabled'] = getenv('VERIFY_DUAL_FLAG') === 'true';
    \$cfg['verify_code_ttl'] = 300;
    \$cfg['confirm_link_ttl'] = 86400;
    \$cfg['resend_cooldown'] = 60;
    saveSiteConfig(\$cfg);
" 2>/dev/null || warn "管理员初始化写入 SQLite 失败，请检查 data/ 目录权限"

log "高级管理员账号已创建（凭据不展示，进后台请用上方 OTP 入口或 ysm-admin login）"

# ================================================================
# 4. 生成 OTP 入口
# ================================================================
log "生成 OTP 动态入口..."

ENTRY_TOKEN=$(openssl rand -base64 9 | tr -d '=+/' | cut -c1-12)
OTP=$(openssl rand -base64 9 | tr -d '=+/' | cut -c1-12)
OTP_HASH=$(php -r "echo password_hash('$OTP', PASSWORD_DEFAULT);")
ENTRY_EXPIRES=$(( $(date +%s) + 600 ))

ENTRY_TOKEN="$ENTRY_TOKEN" OTP_HASH="$OTP_HASH" ENTRY_EXPIRES="$ENTRY_EXPIRES" \
php -r "
    require '$WEB_ROOT/utils.php';
    addEntry(getenv('ENTRY_TOKEN'), getenv('OTP_HASH'), (int)getenv('ENTRY_EXPIRES'));
" 2>/dev/null || warn "OTP 入口写入 SQLite 失败，请稍后用 sudo ysm-admin login 重新生成"

# 确保 PHP-FPM（www-data）可写 SQLite 及 WAL/SHM 文件；data 目录组可写供 CLI 只读命令无 sudo 读取
chown -R www-data:www-data "$WEB_ROOT/data"
chmod 775 "$WEB_ROOT/data" 2>/dev/null || true
chmod 660 "$WEB_ROOT/data/ysm.db" 2>/dev/null || true

# ================================================================
# 5. 配置 Nginx
# ================================================================
log "配置 Nginx..."

NGINX_CONF="/etc/nginx/sites-available/$DOMAIN"
# 第一阶段：先写 80-only 配置（含 ACME 放行 + 其余 301 跳 https），供 certbot webroot 挑战；
# 证书就绪后（本函数下方）再追加 443 server —— 避免 443 引用尚不存在的证书导致 nginx -t 失败、挑战无人响应
cat > "$NGINX_CONF" << EOF
server {
    listen 80;
    server_name $DOMAIN;
    root $WEB_ROOT;
    index index.php index.html;

    # ACME 验证放行（certbot webroot 挑战/续期走 80）
    location ^~ /.well-known/ {
        allow all;
    }

    location / {
        return 301 https://\$server_name\$request_uri;
    }
}
EOF

# 启用站点
ln -sf "$NGINX_CONF" "/etc/nginx/sites-enabled/$DOMAIN" 2>/dev/null || \
    ln -sf "$NGINX_CONF" "/etc/nginx/conf.d/$DOMAIN.conf" 2>/dev/null

# Nginx 语法检查 + 启动（首次部署必须 enable --now；仅 reload 对未运行服务无效，会导致 certbot 挑战无响应）
if nginx -t 2>/dev/null; then
    systemctl enable --now nginx > /dev/null 2>&1 || true
    systemctl reload nginx > /dev/null 2>&1 || true
    log "Nginx 已启动（HTTP 模式，等待 SSL 证书）"
else
    warn "Nginx 配置有误，请手动检查"
fi

# ================================================================
# 6. SSL 证书（公网首次部署前提：域名 DNS 已解析到本机公网 IP，云安全组放行 80/tcp）
# ================================================================
CERT_OK=false
log "申请 SSL 证书..."
if [ -n "$ADMIN_EMAIL" ]; then
    certbot certonly --webroot -w "$WEB_ROOT" -d "$DOMAIN" --email "$ADMIN_EMAIL" --agree-tos --non-interactive > /dev/null 2>&1 && CERT_OK=true || true
else
    certbot certonly --webroot -w "$WEB_ROOT" -d "$DOMAIN" --agree-tos --non-interactive --register-unsafely-without-email > /dev/null 2>&1 && CERT_OK=true || true
fi

# 证书就绪后追加 443 server（完整安全配置）并 reload
if [ "$CERT_OK" = true ] || [ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
    CERT_OK=true
    cat >> "$NGINX_CONF" << EOF
server {
    listen 443 ssl http2;
    server_name $DOMAIN;

    ssl_certificate     /etc/letsencrypt/live/$DOMAIN/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$DOMAIN/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256;
    ssl_prefer_server_ciphers off;

    # v5.4.1：关闭 nginx 版本号暴露
    server_tokens off;

    root $WEB_ROOT;
    index index.php index.html;

    # 安全响应头
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # v3.3.1：上传体积上限 100MB（富媒体压缩包批量上传 ≤80MB / 视频 ≤20MB / 背景图等）
    client_max_body_size 100m;

    # 禁止访问数据目录
    location ~ ^/data/.*\.json\$ {
        deny all;
        return 403;
    }

    # 禁止访问 SQLite 数据库（含 WAL/SHM 文件）
    location ~ ^/data/ysm\.db(-wal|-shm)?\$ {
        deny all;
        return 403;
    }

    # v3.1.6：放行文章图片目录（data/ 其余内容仍禁止；仅图片可公开访问）
    # v3.3.2：补强缓存——随机文件名不可变，30 天强缓存 + open_file_cache，减少重复下载
    location ^~ /data/images/ {
        allow all;
        expires 30d;
        add_header Cache-Control "public, immutable";
        open_file_cache max=1000 inactive=60s;
        open_file_cache_valid 60s;
    }

    # v3.3.0：放行文章视频目录（data/videos/ 仅视频可公开访问）
    # v3.3.2：启用 ngx_http_mp4_module 伪流媒体（拖动 seek 更流畅）+ 强缓存头
    location ^~ /data/videos/ {
        allow all;
        add_header Accept-Ranges bytes always;
        expires 30d;
        add_header Cache-Control "public, immutable";
        open_file_cache max=1000 inactive=60s;
        open_file_cache_valid 60s;
        mp4;  # 需 nginx 编译 --with-http_mp4_module（官方包默认包含）；未编译会报错请删除此行
    }

    # v5.4.0：禁止直读字体资源目录（字体仅经受控端点提供；不随 /data/*.json、/data/*.ttf 静态规则暴露）
    location ^~ /data/fonts/ {
        deny all;
        return 403;
    }

    # v5.4.1：禁止直读文章源文件目录（防绕过发布状态过滤读取草稿/定时文章）
    location ^~ /data/articles/ {
        deny all;
        return 403;
    }

    # v5.4.1：音乐平台内部处理器禁止直读（前台仅经 music.php 调用）
    location ^~ /music/ {
        deny all;
        return 403;
    }

    # v5.4.1：信息暴露面收敛（更新元数据 / 项目文档不提供直读）
    location = /version.json {
        deny all;
        return 403;
    }
    location = /README.md {
        deny all;
        return 403;
    }
    location = /HELP.md {
        deny all;
        return 403;
    }

    # 禁止访问脚本/配置/备份等敏感文件（安装脚本、守护进程、蜜罐同步等源码不得外泄）
    location ~* \.(sh|py|conf|bak|sql|log)\$ {
        deny all;
        return 403;
    }

    # 禁止访问 CLI/安装/迁移/调试文件（无后缀脚本显式封禁）
    location ~ ^/(ysm-admin|ysm-install\.sh|ysm-guard\.py|ysm-hfish-sync\.py|ysm-migrate|_hfish_bridge\.php|test\.php|debug\.php|entry_debug\.php|entry_fixed\.php)\$ {
        deny all;
        return 403;
    }

    # 禁止下载应用配置（泄露 repo/hfish 信息）
    location = /app-config.json {
        deny all;
        return 403;
    }

    # ACME 验证放行（certbot webroot 续期）
    location ^~ /.well-known/ {
        allow all;
    }

    # 禁止访问隐藏文件
    location ~ /\\. {
        deny all;
        return 403;
    }

    # OTP 动态入口
    location /admin/entry/ {
        try_files \$uri /admin/entry.php?\$args;
    }

    # 自定义入口路径 fallback（支持站长/写作者自定义路径）
    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    # PHP 处理（动态检测 PHP 版本）
    location ~ \\.php\$ {
        fastcgi_pass unix:/var/run/php/php${PHP_VER}-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    # 静态文件缓存
    location ~* \\.(css|js|jpg|jpeg|png|gif|ico|svg|woff2?|ttf|eot)\$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
}
EOF
    if nginx -t 2>/dev/null; then
        systemctl reload nginx > /dev/null 2>&1 || true
        log "Nginx HTTPS 配置完成"
    else
        warn "Nginx HTTPS 配置有误，请手动检查"
    fi
else
    warn "SSL 证书申请失败：请确认域名 DNS 已解析到本机公网 IP、安全组放行 80/443；稍后执行 certbot --nginx -d $DOMAIN 补证书后重启 nginx"
fi

# ================================================================
# 信任根部署（更新签名公钥 update_signing_public.pem）
#   默认：部署安装包内官方公钥；
#   仅当显式传入 --gen-signing-key 时，进入「生成信任根」交互流程。
# 四次确认：①人工执行官方安装包 ②必须带 --gen-signing-key 才出现生成界面
#          ③展示私钥前确认「已准备好」 ④生成后回填公钥指纹校验通过才继续。
# 私钥：AES-256 口令加密 → 仅展示一次 → 立即 shred 删净；绝不写入日志/备份/命令历史。
# 注意：不提供「事后更换信任根」命令——如需更换只能重装并显式带 --gen-signing-key。
# ================================================================
setup_trust_root() {
    local pub_target="/opt/you-super-markdown/update_signing_public.pem"

    # ② 未显式带 --gen-signing-key：使用安装包内官方公钥
    if [ "$GEN_SIGNING_KEY" != true ]; then
        if [ -f "$SCRIPT_DIR/update_signing_public.pem" ]; then
            install -m 644 "$SCRIPT_DIR/update_signing_public.pem" "$pub_target"
            log "已部署官方更新签名公钥: $pub_target"
        else
            warn "安装包未包含官方公钥（update_signing_public.pem）——更新通道失败封闭（未部署公钥一律拒绝更新）"
            warn "如需自行签发并更新，请带 --gen-signing-key 重新安装以生成信任根"
        fi
        return 0
    fi

    # ① 安装包层面：生成信任根必须在交互式终端人工执行
    echo ""
    warn "============ 生成信任根（更新签名密钥对）============"
    warn "即将生成一对新的更新签名密钥，并以你的公钥替换服务器信任根。"
    warn "私钥仅用于你今后自行签发更新包，请务必备份到离线安全位置。"
    echo ""
    if [ ! -t 0 ]; then
        err "生成信任根必须人工在交互式终端执行（检测到非交互输入），已中止安装"
    fi
    read -r -p "  确认这是人工执行官方安装包、且已完整阅读上述说明？输入 yes 继续: " _ack
    if [ "$_ack" != "yes" ]; then
        err "未确认，已中止安装（未生成信任根）"
    fi

    local tmpdir priv pub fpr fpr_in fpr_norm
    tmpdir=$(mktemp -d)
    chmod 700 "$tmpdir"
    priv="$tmpdir/update_signing_private.pem"
    pub="$tmpdir/update_signing_public.pem"

    # 生成 RSA-3072 私钥（AES-256 口令加密；口令由 openssl 交互提示，不经命令行 → 不落入日志/命令历史）
    log "生成 RSA-3072 私钥（AES-256 口令加密，openssl 将提示设置口令）..."
    if ! openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -aes256 -out "$priv"; then
        rm -rf "$tmpdir"
        err "私钥生成失败，已中止安装"
    fi
    chmod 600 "$priv"

    # 导出公钥 + 计算 SHA256 指纹（先展示公钥供用户保存与核对）
    if ! openssl pkey -in "$priv" -pubout -out "$pub"; then
        shred -u "$priv" 2>/dev/null || rm -f "$priv"
        rm -rf "$tmpdir"
        err "公钥导出失败，已中止安装"
    fi
    fpr=$(openssl pkey -pubin -in "$pub" -outform DER 2>/dev/null | openssl dgst -sha256 2>/dev/null | awk '{print $NF}')

    echo ""
    echo "============================================"
    echo "  新信任根 · 公钥（请抄写/保存）"
    echo "============================================"
    cat "$pub"
    echo "--------------------------------------------"
    echo "  公钥 SHA256 指纹: $fpr"
    echo "============================================"
    echo ""

    # ④ 回填公钥指纹校验；不通过 → 中止安装、不替换信任根
    read -r -p "  请回填上面的公钥 SHA256 指纹以确认（可含冒号，不区分大小写）: " fpr_in
    fpr_in=$(printf '%s' "$fpr_in" | tr -d ':\r\n' | tr 'A-F' 'a-f')
    fpr_norm=$(printf '%s' "$fpr" | tr -d ':\r\n' | tr 'A-F' 'a-f')
    if [ -z "$fpr_in" ] || [ "$fpr_in" != "$fpr_norm" ]; then
        shred -u "$priv" 2>/dev/null || rm -f "$priv"
        rm -rf "$tmpdir"
        err "公钥指纹校验不通过，已中止安装（未替换信任根）"
    fi
    log "公钥指纹校验通过"

    # ③ 展示私钥前，确认用户「已准备好」
    echo ""
    warn "下一步将『仅显示一次』你的私钥（口令加密 PEM）。"
    warn "请确保已可用于离线保存（纸质抄写 / 加密移动介质）；该步骤后私钥将立即彻底销毁、无法找回。"
    read -r -p "  已准备好接收私钥？输入 ready 继续: " _ready
    if [ "$_ready" != "ready" ]; then
        shred -u "$priv" 2>/dev/null || rm -f "$priv"
        rm -rf "$tmpdir"
        err "未确认已准备好，已中止安装（未替换信任根）"
    fi

    echo ""
    echo "================ 私钥（仅此一次显示）================"
    cat "$priv"
    echo "===================================================="
    echo ""

    # 替换信任根：以用户公钥覆盖服务器公钥
    install -m 644 "$pub" "$pub_target"
    log "已用你的公钥替换服务器信任根: $pub_target"

    # 私钥立即 shred 删净（不留任何临时副本）
    shred -u "$priv" 2>/dev/null || rm -f "$priv"
    rm -rf "$tmpdir"
    echo "  （私钥已彻底销毁；请立即核对上方内容并离线妥善保管）"

    # 审计日志：仅记录公钥指纹，绝不记录私钥
    php -r "require_once '$WEB_ROOT/utils.php'; auditLog('trust_root_replaced', 'update', '安装时以 --gen-signing-key 生成并以用户公钥替换更新签名信任根（SHA256 指纹: $fpr）');" 2>/dev/null || true
    return 0
}

# ================================================================
# 7. 部署守护进程
# ================================================================
log "部署守护进程..."

# 创建母本目录
INSTALL_BASE="/opt/you-super-markdown/install-base"
mkdir -p "$INSTALL_BASE"
rsync -av --exclude='data/' --exclude='*.json' "$WEB_ROOT/" "$INSTALL_BASE/" > /dev/null 2>&1
chown -R root:root "$INSTALL_BASE"
chmod -R 755 "$INSTALL_BASE"
find "$INSTALL_BASE" -type f -exec chmod 644 {} \;

# chattr +i 锁定母本
chattr -R +i "$INSTALL_BASE" 2>/dev/null || warn "chattr 不可用，母本未锁定（建议安装 e2fsprogs）"

# 创建日志镜像目录（chattr +i 锁定，防 PHP 权限/未知 bug 篡改审计镜像）
mkdir -p /opt/you-super-markdown/logs
# v5.4.8：镜像目录 owner 必须是 root（www-data 仅可读、不可写）——防 www-data 在背书解锁窗口替换镜像
chown root:www-data /opt/you-super-markdown/logs
chmod 750 /opt/you-super-markdown/logs

# v5.0.0 P1-4：更新请求共享目录（root 与 www-data 共享、其他用户不可写）——
# 更新请求文件从世界可写的 /tmp 迁至此处（目录 root:www-data 0770，文件 0660）
mkdir -p /opt/you-super-markdown/run
chown root:www-data /opt/you-super-markdown/run
chmod 770 /opt/you-super-markdown/run

# 创建自动备份目录（数据库 30 分钟备份 / 文章每日备份）并 chattr +i 锁定
# 备份目录与母本同理念：root 锁定，守护进程写入时临时解锁→重锁，PHP 权限不可篡改
mkdir -p /opt/you-super-markdown/backups/db /opt/you-super-markdown/backups/articles
chown root:www-data /opt/you-super-markdown/backups /opt/you-super-markdown/backups/db /opt/you-super-markdown/backups/articles
chmod 775 /opt/you-super-markdown/backups /opt/you-super-markdown/backups/db /opt/you-super-markdown/backups/articles
chattr -R +i /opt/you-super-markdown/backups/db /opt/you-super-markdown/backups/articles 2>/dev/null || warn "chattr 不可用，备份目录未锁定（建议安装 e2fsprogs）"
chattr +i /opt/you-super-markdown/logs 2>/dev/null || warn "chattr 不可用，日志镜像目录未锁定（建议安装 e2fsprogs）"

# v5.4.8：审计恢复通道（Web 发起 → root 执行）。systemd path unit 监听恢复请求文件，
#   触发一次性 root 服务执行 `ysm-admin audit-recover --from-request`；Web 不需要任何 sudo 权限。
cat > /etc/systemd/system/ysm-audit-recover.service <<'YSMSVC'
[Unit]
Description=You Super Markdown - 审计镜像恢复（由恢复请求触发，root 执行）
After=local-fs.target

[Service]
Type=oneshot
ExecStart=/usr/local/bin/ysm-admin audit-recover --from-request
YSMSVC
cat > /etc/systemd/system/ysm-audit-recover.path <<'YSMPATH'
[Unit]
Description=You Super Markdown - 监听审计恢复请求文件

[Path]
PathExists=/opt/you-super-markdown/run/audit-recover-request.json
Unit=ysm-audit-recover.service

[Install]
WantedBy=multi-user.target
YSMPATH
systemctl daemon-reload 2>/dev/null || true
systemctl enable --now ysm-audit-recover.path >/dev/null 2>&1 || warn "未能启用 ysm-audit-recover.path（后台「从镜像恢复」将不可用，可用 CLI）"

# ================================================================
# 6.5 审计链母密钥（v5.0.0 P7：链密钥化——安装时生成一次，仅 root 独占保存）
# ================================================================
# 母密钥用于 HMAC-SHA256 加封/校验审计链（由 root 守护进程加封，PHP/www-data 读不到 → 伪造不出链）。
# 存放于 webroot 外 /opt/you-super-markdown/secrets/audit_key（root 0600，chattr +i 锁定）。
# 绝不写入任何 web 可读位置；丢失将导致历史链永久无法校验（须随 root 备份一并保存）。
AUDIT_SECRETS_DIR="/opt/you-super-markdown/secrets"
AUDIT_KEY_FILE="$AUDIT_SECRETS_DIR/audit_key"
mkdir -p "$AUDIT_SECRETS_DIR"
chown root:root "$AUDIT_SECRETS_DIR"
chmod 700 "$AUDIT_SECRETS_DIR"
if [ ! -s "$AUDIT_KEY_FILE" ]; then
    openssl rand -hex 32 > "$AUDIT_KEY_FILE"
    chown root:root "$AUDIT_KEY_FILE"
    chmod 600 "$AUDIT_KEY_FILE"
    chattr +i "$AUDIT_KEY_FILE" 2>/dev/null || warn "chattr 不可用，审计母密钥未锁定（建议安装 e2fsprogs）"
    log "审计链母密钥已生成: $AUDIT_KEY_FILE (root 0600, webroot 外)"
else
    log "审计链母密钥已存在，保留原密钥（不覆盖）"
fi
# v5.0.0 P0-4：写入预期审计链 epoch（root 0600，webroot 外）——校验器据此拒绝「纯 legacy(sha256) 链」；
# 全新安装即 epoch=2（链由 root 守护进程 HMAC 加封）；4.x 老库经 ysm-migrate 迁移后同样应置 2。
AUDIT_EPOCH_FILE="$AUDIT_SECRETS_DIR/audit_epoch"
printf '2\n' > "$AUDIT_EPOCH_FILE"
chown root:root "$AUDIT_EPOCH_FILE"
chmod 600 "$AUDIT_EPOCH_FILE"
# 记录密钥 SHA256 指纹进安装审计（与 trust_root_replaced 同级；绝不记录密钥本体）
AUDIT_KEY_FPR=$(sha256sum "$AUDIT_KEY_FILE" | awk '{print $1}')
php -r "require_once '$WEB_ROOT/utils.php'; auditLog('audit_key_created', 'audit', 'v5.0.0 安装时生成审计链母密钥（HMAC-SHA256，root 0600，webroot 外 secrets/audit_key；SHA256 指纹: $AUDIT_KEY_FPR）');" 2>/dev/null || true

# 部署更新签名信任根（默认官方公钥；--gen-signing-key 时进入「四次确认」的生成流程）
setup_trust_root

# 初始化自动备份配置（默认：库 30 分钟 / 文章保留 7 份 / 手动备份保留 5 份，后台可改）
# v3.3.6：模板补齐 v3.3.5 的两个开关（上传触发立即备份 / 单篇篡改还原），全新安装即完整
cat > /opt/you-super-markdown/backup.conf << 'BACKUPCONF'
# 自动备份配置（守护进程 ysm-guard.py 读取；超管后台/SSH 可改）
DB_BACKUP_INTERVAL_MIN=30
ARTICLE_BACKUP_KEEP=7
MANUAL_BACKUP_KEEP=5
ARTICLE_TRIGGER_BACKUP=1
ARTICLE_SINGLE_RESTORE=1
BACKUPCONF
chown root:www-data /opt/you-super-markdown/backup.conf
chmod 664 /opt/you-super-markdown/backup.conf

# 安装守护进程脚本
cp "$SCRIPT_DIR/ysm-guard.py" /opt/you-super-markdown/ysm-guard.py
chmod 700 /opt/you-super-markdown/ysm-guard.py

# 创建邮件告警脚本（v2.8.0：mail 失败时落盘 alert.log，可追溯"邮件没发出去"）
touch /opt/you-super-markdown/alert.log 2>/dev/null || true
chown root:www-data /opt/you-super-markdown/alert.log 2>/dev/null || true
chmod 664 /opt/you-super-markdown/alert.log 2>/dev/null || true
cat > /usr/local/bin/ysm-alert << 'EOF'
#!/bin/bash
TO="$1"
SUBJECT="$2"
BODY="$3"
if command -v mail >/dev/null 2>&1; then
    echo "$BODY" | mail -s "$SUBJECT" "$TO" 2>/tmp/ysm-alert.err
    RC=$?
    if [ $RC -ne 0 ]; then
        ERR=$(head -1 /tmp/ysm-alert.err 2>/dev/null)
        echo "$(date '+%Y-%m-%d %H:%M:%S') [FAIL] mail 命令失败(rc=$RC): $ERR" >> /opt/you-super-markdown/alert.log 2>/dev/null || true
    fi
    rm -f /tmp/ysm-alert.err
    exit $RC
else
    echo "$(date '+%Y-%m-%d %H:%M:%S') [FAIL] mail 命令不存在，无法发送告警" >> /opt/you-super-markdown/alert.log 2>/dev/null || true
    exit 1
fi
EOF
chmod +x /usr/local/bin/ysm-alert

# 注册 systemd 服务
APP_NAME=$(php -r "\$c = json_decode(@file_get_contents('$SCRIPT_DIR/app-config.json'), true); echo \$c['app_name'] ?? 'You Super Markdown';" 2>/dev/null)
DOCS_URL=$(php -r "\$c = json_decode(@file_get_contents('$SCRIPT_DIR/app-config.json'), true); echo \$c['docs_url'] ?? '';" 2>/dev/null)
cat > /etc/systemd/system/ysm-guard.service << EOF
[Unit]
Description=${APP_NAME} File Guard Daemon
Documentation=${DOCS_URL}
After=network.target
Before=nginx.service

[Service]
Type=notify
ExecStart=/usr/bin/python3 /opt/you-super-markdown/ysm-guard.py
Environment=YSM_WEB_ROOT=$WEB_ROOT
Environment=PYTHONUNBUFFERED=1
Restart=always
RestartSec=5
WatchdogSec=30
User=root
Group=root
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=$WEB_ROOT /opt/you-super-markdown
NoNewPrivileges=yes
PrivateTmp=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
OOMScoreAdjust=-900

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable ysm-guard
systemctl start ysm-guard
log "守护进程已启动"

# 注册 cron 兜底
cat > /etc/cron.d/ysm-guard << EOF
# You Super Markdown 守护进程兜底检查（每5分钟）
*/5 * * * * root /usr/bin/systemctl is-active --quiet ysm-guard || /usr/bin/systemctl start ysm-guard
# 每天凌晨3点发送每日审计报告（ysm-admin audit-report：校验哈希链并经 SMTP 发送给管理员，无 MTA 依赖）
0 3 * * * root /usr/local/bin/ysm-admin audit-report > /dev/null 2>&1
EOF

# ================================================================
# 8. 配置防火墙
# ================================================================
log "配置防火墙..."
ufw --force enable > /dev/null 2>&1 || true
ufw allow 22/tcp > /dev/null 2>&1 || true
ufw allow 80/tcp > /dev/null 2>&1 || true
ufw allow 443/tcp > /dev/null 2>&1 || true
log "防火墙已配置"

# ================================================================
# 8.5 Hfish 蜜罐部署（可选，默认安装）
# ================================================================
# 蜜獾账户配置：用户名从 app-config.json 读取（默认 xiao），
# 密码与服务器登录密码保持一致（安装时交互输入或 $HFISH_PASSWORD 环境变量）
install_hfish() {
    set +e  # 临时关闭 set -e，避免 Hfish 安装失败时导致整个脚本退出
    if [ "$INSTALL_HFISH" = false ]; then
        info "已跳过 Hfish 蜜罐安装（--skip-hfish）"
        return 0
    fi

    # 低内存提示（v2.10.2 公网部署实测：2GB 内存装 Hfish 后可用内存吃紧）
    _mem_mb=$(free -m 2>/dev/null | awk '/Mem:/{print $2}')
    if [ -n "$_mem_mb" ] && [ "$_mem_mb" -lt 2048 ]; then
        warn "内存仅 ${_mem_mb}MB，Hfish（Go 服务 + 面板）可能使可用内存吃紧"
        warn "若后续网站卡顿/进程被杀，可用 --skip-hfish 跳过重装（蜜罐为可选组件）"
    fi

    echo ""
    log "============================================"
    log "  Hfish 蜜罐部署（可选安全组件，小白建议默认安装）"
    log "============================================"
    echo ""

    # 是否安装：--yes 默认安装；否则交互确认
    if [ "$AUTO_YES" = true ]; then
        hfish_confirm=Y
    else
        read -p "  是否安装 Hfish 蜜罐? (Y/n, 默认安装): " hfish_confirm
        hfish_confirm=${hfish_confirm:-Y}
    fi
    if [ "$hfish_confirm" != "Y" ] && [ "$hfish_confirm" != "y" ]; then
        info "已跳过 Hfish 蜜罐安装"
        HFISH_INSTALLED=false
        return 0
    fi

    # 读取蜜獾账户名（app-config.json -> hfish_user，默认 xiao）
    HFISH_USER=$(php -r "\$c = json_decode(@file_get_contents('$SCRIPT_DIR/app-config.json'), true); echo \$c['hfish_user'] ?? 'xiao';" 2>/dev/null)
    HFISH_USER=${HFISH_USER:-xiao}

    # 蜜獾账户密码：--hfish-password / YSM_HFISH_PASSWORD > 交互输入（留空自动生成强密码）
    HFISH_PASSWORD="${HFISH_PASSWORD_ARG:-${YSM_HFISH_PASSWORD:-}}"
    if [ -z "$HFISH_PASSWORD" ] && [ "$AUTO_YES" != true ]; then
        read -s -p "  蜜獾账户密码 (留空自动生成强密码): " HFISH_PASSWORD
        echo ""
    fi
    if [ -z "$HFISH_PASSWORD" ]; then
        HFISH_PASSWORD=$(openssl rand -base64 12 | tr -d '=+/')
        HFISH_PASSWORD_GENERATED=true
    fi
    info "蜜獾账户: $HFISH_USER（密码：$( [ "${HFISH_PASSWORD_GENERATED:-false}" = true ] && echo '自动生成，见完成页' || echo '已设置' )）"

    echo ""
    info "配置蜜罐端口（默认值即可；若被占用会自动顺延）："

    # 管理面板端口：--hfish-port-panel / YSM_HFISH_PANEL_PORT > 交互默认 4433 > 自动检测占用
    HFISH_PANEL_PORT="${HFISH_PANEL_PORT_ARG:-${YSM_HFISH_PANEL_PORT:-}}"
    if [ -z "$HFISH_PANEL_PORT" ] && [ "$AUTO_YES" != true ]; then
        read -p "  Web 管理面板端口 (默认 4433): " HFISH_PANEL_PORT
    fi
    HFISH_PANEL_PORT=$(pick_free_port "${HFISH_PANEL_PORT:-4433}")

    # 节点通信端口：--hfish-port-node / YSM_HFISH_NODE_PORT > 交互默认 4434 > 自动检测占用且避开面板端口
    HFISH_NODE_PORT="${HFISH_NODE_PORT_ARG:-${YSM_HFISH_NODE_PORT:-}}"
    if [ -z "$HFISH_NODE_PORT" ] && [ "$AUTO_YES" != true ]; then
        read -p "  节点通信端口 (默认 4434): " HFISH_NODE_PORT
    fi
    HFISH_NODE_PORT=$(pick_free_port "${HFISH_NODE_PORT:-4434}")
    while [ "$HFISH_NODE_PORT" = "$HFISH_PANEL_PORT" ]; do
        HFISH_NODE_PORT=$(pick_free_port "$((HFISH_NODE_PORT + 1))")
    done

    echo ""
    info "Hfish 蜜罐配置确认："
    echo "  管理面板端口: $HFISH_PANEL_PORT"
    echo "  节点通信端口: $HFISH_NODE_PORT"
    echo ""

    # 保存端口配置（供 ysm-admin 读取）
    mkdir -p /opt/you-super-markdown
    cat > /opt/you-super-markdown/hfish-ports.conf << PORTCONF
HFISH_PANEL_PORT=$HFISH_PANEL_PORT
HFISH_NODE_PORT=$HFISH_NODE_PORT
HFISH_USER=$HFISH_USER
PORTCONF
    chmod 600 /opt/you-super-markdown/hfish-ports.conf

    # 使用官方一键安装脚本部署 Hfish
    log "运行 Hfish 官方一键安装脚本..."
    echo ""
    info "HFish 官方一键安装脚本将自动下载并部署最新版本"
    info "默认端口: Web 管理面板 4433 / 节点通信 4434"
    echo ""

    # 运行官方安装脚本（自动选择选项 1 安装）
    echo 1 | bash <(curl -sS -L https://hfish.net/webinstall.sh) 2>&1 || {
        warn "Hfish 官方安装脚本执行失败，请手动安装:"
        warn "  bash <(curl -sS -L https://hfish.net/webinstall.sh)"
        HFISH_INSTALLED=false
        return 0
    }

    sleep 3
    if systemctl is-active --quiet hfish 2>/dev/null || pgrep -f '/hfish' > /dev/null 2>&1; then
        log "Hfish 蜜罐服务已启动"
        HFISH_INSTALLED=true
        # 自动配置蜜獾账户：名称改为 hfish_user，密码与服务器一致
        configure_hfish_account "$HFISH_USER" "$HFISH_PASSWORD"
        # 设计指标：管理面板仅本机可访问，需经 SSH 隧道（ysm-admin hfish-panel），不提供公网 URL
        info "Hfish 管理面板: 仅本机访问（SSH 隧道 → sudo ysm-admin hfish-panel）"
        info "蜜獾账户: $HFISH_USER（密码已配置，登录后请妥善保管）"
    else
        warn "Hfish 服务启动失败（HFish 由 crontab '* * * * * /opt/hfish/hfish' 托管，非 systemd）"
        HFISH_INSTALLED=false
    fi

    # 面板绑定回环（进程层硬锁）：web_addr → 127.0.0.1:PORT，即使防火墙误开公网也连不上。
    # 【警告】api_addr 绝不可写主机名——HFish 用 clients.server_addr 主机段 + api_addr 字符串拼接
    # 节点上报 URL，写成 "127.0.0.1:4434" 会拼成 "https://127.0.0.1127.0.0.1:4434" 导致蜜罐上报中断
    # （线上实测踩坑）。故仅锁 web_addr；api_addr 保持 ":PORT"（绑定 0.0.0.0，由防火墙兜底封锁）。
    _HFISH_CFG="/usr/share/hfish/config.toml"
    [ -f "$_HFISH_CFG" ] || _HFISH_CFG="/opt/hfish/config.toml"
    if [ -f "$_HFISH_CFG" ]; then
        sed -i -E "s|^[[:space:]]*web_addr[[:space:]]*=.*|    web_addr = \"127.0.0.1:${HFISH_PANEL_PORT}\"|" "$_HFISH_CFG"
        sed -i -E "s|^[[:space:]]*api_addr[[:space:]]*=.*|    api_addr = \":${HFISH_NODE_PORT}\"|" "$_HFISH_CFG"
        # HFish 非 systemd 托管（官方脚本用 crontab + nohup），按进程方式重启以生效
        pkill -9 -f '/hfish' > /dev/null 2>&1 || true
        sleep 2
        ( cd /opt/hfish && nohup ./hfish > /dev/null 2>&1 & ) > /dev/null 2>&1 || true
        log "Hfish 面板已绑定回环 127.0.0.1:${HFISH_PANEL_PORT}（进程层硬锁，公网不可达）"
    else
        warn "未找到 HFish config.toml，跳过面板回环绑定（面板可能仍监听公网）"
    fi

    # 防火墙：放行【诱饵端口】——蜜罐本意是故意暴露的假服务，用于诱捕扫描/爆破；
    # 管理面板端口（已回环绑定）与节点通信端口（单机内置节点走 loopback）一律不开放公网。
    # 注意：云厂商安全组需另行放行这些诱饵端口（云侧无法脚本化）。
    log "配置 Hfish 防火墙规则..."
    for _dp in 445 135 139 1433 3389 6379 7879 8080 8081 9000 9200; do
        ufw allow "${_dp}/tcp" comment 'HFish decoy' > /dev/null 2>&1 || true
    done
    # 清理历史残留/易误开端口：面板端口与节点端口都不放行公网
    for _p in "${HFISH_PANEL_PORT}" 4433 "${HFISH_NODE_PORT}" 4434; do
        ufw delete allow "${_p}/tcp" > /dev/null 2>&1 || true
    done
    ufw reload > /dev/null 2>&1 || true
    log "Hfish 防火墙已配置（放行 11 个诱饵端口；面板 ${HFISH_PANEL_PORT} 与节点 ${HFISH_NODE_PORT} 不开放公网）"
}

# 自动配置蜜獾账户（用户名 + 密码，bcrypt 存储，兼容 HFish Go 校验）
configure_hfish_account() {
    local user="$1" pass="$2"
    local db="/usr/share/hfish/database/hfish.db"
    [ -f "$db" ] || db="/opt/hfish/database/hfish.db"
    [ -f "$db" ] || { warn "未找到 HFish 数据库，跳过账户配置"; return 1; }

    # base64 传递避免引号转义问题
    local pass_b64 user_b64
    pass_b64=$(printf '%s' "$pass" | base64 -w0 2>/dev/null || printf '%s' "$pass" | base64)
    user_b64=$(printf '%s' "$user" | base64 -w0 2>/dev/null || printf '%s' "$user" | base64)

    local hash
    hash=$(PASS_B64="$pass_b64" php -r "echo password_hash(base64_decode(getenv('PASS_B64')), PASSWORD_BCRYPT);" 2>/dev/null)
    if [ -z "$hash" ]; then
        warn "PHP 生成密码哈希失败，跳过账户配置"
        return 1
    fi
    # Go bcrypt 仅接受 $2a$/$2b$，将 PHP 的 $2y$ 前缀修正为 $2a$
    hash="${hash/\$2y\$/\$2a\$}"

    USER_B64="$user_b64" HASH_B64=$(printf '%s' "$hash" | base64 -w0 2>/dev/null || printf '%s' "$hash" | base64) python3 - "$db" << 'PY'
import sqlite3, sys, os, base64
db = sys.argv[1]
user = base64.b64decode(os.environ['USER_B64']).decode()
hash_pw = base64.b64decode(os.environ['HASH_B64']).decode()
con = sqlite3.connect(db)
cur = con.cursor()
# 主账户：改名为指定用户并设置密码（role=1 超管第一条）
cur.execute("UPDATE users SET username=?, password=? WHERE id=1", (user, hash_pw))
# 兜底 admin 账户：同步为强密码（HFish 启动会自动重建 admin，避免弱口令）
cur.execute("UPDATE users SET password=? WHERE username='admin'", (hash_pw,))
con.commit()
print('hfish accounts:', cur.execute('SELECT id,username,role FROM users').fetchall())
con.close()
PY
    log "蜜獾账户配置完成: $user"
}

install_hfish
set -e  # 恢复 set -e

# 9.3 邮件告警配置（v5.3.0：由「可选」改为「必填」——告警/更新通知/注册验证码/每日审计报告靠 SMTP；
#     只强制「必须填写」，不强制「必须正确」（授权码无法在线校验）；测试邮件入口复用后台「邮件设置」/ set-smtp-pass）
configure_mail() {
    echo ""
    log "============================================"
    log "  邮件配置（必填：告警 / 更新通知 / 注册验证码 / 每日审计报告依赖 SMTP）"
    log "============================================"
    echo ""
    # v5.3.0：SMTP 必填——缺失/留空直接阻断安装（不再提供「跳过」）
    # 全自动模式（无 tty）不做交互 read：三项任一缺失即阻断，避免无输入时 read 空转死循环
    if [ "$AUTO_YES" = true ] && { [ -z "${YSM_SMTP_HOST:-}" ] || [ -z "${YSM_SMTP_USER:-}" ] || [ -z "${YSM_SMTP_PASS:-}" ]; }; then
        err "安装要求配置 SMTP：全自动模式下必须提供 YSM_SMTP_HOST / YSM_SMTP_USER / YSM_SMTP_PASS（交互模式直接运行即可）"
    fi
    SMTP_HOST="${YSM_SMTP_HOST:-}"
    SMTP_PORT="${YSM_SMTP_PORT:-465}"
    SMTP_ENC="${YSM_SMTP_ENC:-ssl}"
    SMTP_USER="${YSM_SMTP_USER:-}"
    SMTP_PASS="${YSM_SMTP_PASS:-}"
    SMTP_FROM="${YSM_SMTP_FROM:-}"
    # v5.3.0：服务器/发信账号/授权码为必填项，留空则循环重填（不强制正确性）
    while [ -z "$SMTP_HOST" ]; do
        read -p "  SMTP 服务器 (必填，如 smtp.163.com): " SMTP_HOST
        SMTP_HOST=$(printf '%s' "$SMTP_HOST" | tr -d '[:space:]')
    done
    [ -z "$SMTP_PORT" ] && SMTP_PORT=465
    [ -z "$SMTP_ENC" ] && SMTP_ENC=ssl
    while [ -z "$SMTP_USER" ]; do
        read -p "  发信账号 (必填，如 xxx@163.com): " SMTP_USER
        SMTP_USER=$(printf '%s' "$SMTP_USER" | tr -d '[:space:]')
    done
    while [ -z "$SMTP_PASS" ]; do
        read -s -p "  授权码 (必填，不回显): " SMTP_PASS
        echo ""
        [ -z "$SMTP_PASS" ] && warn "授权码不能为空，请重新输入"
    done
    [ -z "$SMTP_FROM" ] && read -p "  发件人 (可空=账号): " SMTP_FROM
    SMTP_PORT=${SMTP_PORT:-465}
    SMTP_ENC=${SMTP_ENC:-ssl}
    # v3.0.9/v5.4.2：授权码改为环境注入（密钥不落 Web 可达盘）——
    # ① Web 端：root-only 独立密钥文件 /etc/php/<ver>/fpm/pool.d/zz-ysm-secret.conf（0600 root:root，
    #    由 php-fpm 经 pool.d/*.conf 自动 include；不写入主配置 www.conf）
    # ② CLI/守护进程：root 密钥文件 /opt/you-super-markdown/secrets/smtp_pass（0600，临时环境变量注入）
    SECRETS_DIR="/opt/you-super-markdown/secrets"
    mkdir -p "$SECRETS_DIR"
    printf '%s' "$SMTP_PASS" > "$SECRETS_DIR/smtp_pass"
    chmod 600 "$SECRETS_DIR/smtp_pass"
    POOL_DIR="/etc/php/${PHP_VER}/fpm/pool.d"
    SECRET_CONF="$POOL_DIR/zz-ysm-secret.conf"
    if [ -d "$POOL_DIR" ]; then
        ESC_PASS=$(printf '%s' "$SMTP_PASS" | sed 's/[\\"]/\\&/g')
        printf 'env[YSM_SMTP_PASS] = "%s"\n' "$ESC_PASS" > "$SECRET_CONF"
        chmod 600 "$SECRET_CONF" 2>/dev/null || true
        chown root:root "$SECRET_CONF" 2>/dev/null || true
        # 迁移：旧版 www.conf 若仍有 env 行，一并移除（sed -i 无后缀，不留含口令的 .bak）
        if [ -f "$POOL_DIR/www.conf" ]; then
            sed -i '/env\[YSM_SMTP_PASS\]/d' "$POOL_DIR/www.conf" 2>/dev/null || true
        fi
        systemctl reload "php${PHP_VER}-fpm" 2>/dev/null || true
        info "授权码已写入 root-only 独立文件（$SECRET_CONF，0600 root:root，Web 端不可见）"
    else
        warn "未找到 php-fpm pool 目录（$POOL_DIR），授权码仅存 root 密钥文件"
    fi
    # 非敏感配置仍写 config 表；smtp_pass 不再落库（环境注入优先）
    SMTP_HOST_B64=$(printf '%s' "$SMTP_HOST" | base64 -w0 2>/dev/null || printf '%s' "$SMTP_HOST" | base64)
    SMTP_USER_B64=$(printf '%s' "$SMTP_USER" | base64 -w0 2>/dev/null || printf '%s' "$SMTP_USER" | base64)
    SMTP_FROM_B64=$(printf '%s' "$SMTP_FROM" | base64 -w0 2>/dev/null || printf '%s' "$SMTP_FROM" | base64)
    SMTP_HOST_B64="$SMTP_HOST_B64" SMTP_PORT_B64="$SMTP_PORT" SMTP_ENC_B64="$SMTP_ENC" \
    SMTP_USER_B64="$SMTP_USER_B64" SMTP_FROM_B64="$SMTP_FROM_B64" \
    php -r "
        require '$WEB_ROOT/utils.php';
        \$cfg = loadSiteConfig();
        \$cfg['smtp_host'] = base64_decode(getenv('SMTP_HOST_B64'));
        \$cfg['smtp_port'] = (int)getenv('SMTP_PORT_B64');
        \$cfg['smtp_user'] = base64_decode(getenv('SMTP_USER_B64'));
        \$cfg['smtp_pass'] = '';
        \$cfg['smtp_from'] = base64_decode(getenv('SMTP_FROM_B64'));
        \$cfg['smtp_enc'] = in_array(getenv('SMTP_ENC_B64'), ['ssl','tls','plain'], true) ? getenv('SMTP_ENC_B64') : 'ssl';
        saveSiteConfig(\$cfg);
        echo 'OK';
    " 2>/dev/null || true
    info "SMTP 配置完成（后台「邮件设置」可修改/测试）"
    # v5.3.0：SMTP 双向验证（发送带一次性确认码的邮件，用户查收后回填）改为「尽力而为」——
    # 只强制「必须填写」、不强制「必须正确」（授权码无法在线校验）：发送失败/回填超时均不再终止安装，
    # 明确提示后续用测试邮件入口修正（后台「邮件设置」测试 / sudo ysm-admin set-smtp-pass）。
    # （管理员邮箱已在参数收集环节强制必填，此处 ADMIN_EMAIL 一定非空。）
    echo ""
    log "SMTP 双向验证：发送确认码邮件到 $ADMIN_EMAIL ..."
    VERIFY_CODE=$(php -r "echo str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);")
    ADMIN_EMAIL_B64=$(printf '%s' "$ADMIN_EMAIL" | base64 -w0 2>/dev/null || printf '%s' "$ADMIN_EMAIL" | base64) \
    php -r "require '$WEB_ROOT/utils.php'; db_exec('INSERT INTO email_codes (id,email,code,purpose,expires,used,created,ip,operator_role) VALUES (?,?,?,?,?,0,?,?,?)', [bin2hex(random_bytes(8)), base64_decode(getenv('ADMIN_EMAIL_B64')), '$VERIFY_CODE', 'install_verify', time()+300, time(), 'install', 'install']);" 2>/dev/null || true
    SEND_RESULT=$(YSM_SMTP_PASS="$(cat "$SECRETS_DIR/smtp_pass" 2>/dev/null)" \
    ADMIN_EMAIL_B64=$(printf '%s' "$ADMIN_EMAIL" | base64 -w0 2>/dev/null || printf '%s' "$ADMIN_EMAIL" | base64) \
    CODE_B64=$(printf '%s' "$VERIFY_CODE" | base64 -w0 2>/dev/null || printf '%s' "$VERIFY_CODE" | base64) \
    php -r "
        require '$WEB_ROOT/utils.php';
        \$to = base64_decode(getenv('ADMIN_EMAIL_B64'));
        \$code = base64_decode(getenv('CODE_B64'));
        \$body = 'SMTP 双向验证：请输入以下确认码完成安装验证。' . \"\\n\\n确认码：{\$code}\\n有效期：5 分钟。\";
        \$html = renderMailHtml('You Super Markdown', '邮箱确认', \$body);
        [\$ok, \$err] = sendSmtpMail(\$to, '[You Super Markdown 安装] 邮箱确认码', \$body, \$html);
        echo \$ok ? 'OK' : ('FAIL: ' . \$err);
    " 2>/dev/null)
    SENT=0
    case "$SEND_RESULT" in
        OK*) SENT=1; info "确认码邮件已发送，请在 $ADMIN_EMAIL 查收" ;;
        *) warn "确认码邮件发送失败：${SEND_RESULT#FAIL: }（已保留 SMTP 配置，安装继续；请用后台「邮件设置」测试邮件或 sudo ysm-admin set-smtp-pass 修正授权码）" ;;
    esac
    if [ "$SENT" = "1" ]; then
        VERIFIED=0
        DEADLINE=$(( $(date +%s) + 300 ))
        while :; do
            if [ "$(date +%s)" -gt "$DEADLINE" ]; then
                warn "确认码超时（5 分钟）未回填，不阻断安装（SMTP 配置已保存，请稍后用测试邮件验证）"
                break
            fi
            read -r -p "  请输入邮件中的 6 位确认码: " USER_CODE
            USER_CODE=$(printf '%s' "$USER_CODE" | tr -d '[:space:]')
            if [ -z "$USER_CODE" ]; then continue; fi
            R=$(ADMIN_EMAIL_B64=$(printf '%s' "$ADMIN_EMAIL" | base64 -w0 2>/dev/null || printf '%s' "$ADMIN_EMAIL" | base64) \
            CODE_B64=$(printf '%s' "$USER_CODE" | base64 -w0 2>/dev/null || printf '%s' "$USER_CODE" | base64) \
            php -r "
                require '$WEB_ROOT/utils.php';
                \$to = base64_decode(getenv('ADMIN_EMAIL_B64'));
                \$code = base64_decode(getenv('CODE_B64'));
                \$r = email_code_verify(\$to, \$code, 'install_verify');
                echo \$r[0] ? 'OK' : 'NO';
            " 2>/dev/null)
            if [ "$R" = "OK" ]; then
                VERIFIED=1
                break
            fi
            warn "确认码不正确或已过期，请重新输入"
        done
        [ "$VERIFIED" = "1" ] && info "✅ SMTP 双向验证通过：$ADMIN_EMAIL 可正常收信"
    fi
}
configure_mail

# ================================================================
# 9. 安装 CLI 管理工具
# ================================================================
log "安装 CLI 管理工具..."

# 安装项目内完整版 CLI（含 apply-update/rollback、challenge 落盘等）
if [ -f "$SCRIPT_DIR/ysm-admin" ]; then
    cp "$SCRIPT_DIR/ysm-admin" /usr/local/bin/ysm-admin
    chmod +x /usr/local/bin/ysm-admin
else
    err "未找到 CLI 管理工具 ysm-admin（安装包不完整，安装终止）"
fi
log "CLI 管理工具已安装到 /usr/local/bin/ysm-admin"

# v5.4.4：自动登记服务器自身出口/域名解析 IP 到 threat_self_ips（自封防护，幂等；失败不阻断安装）
ysm-admin set-self-ip --auto >/dev/null 2>&1 || warn "自动登记服务器自身 IP 失败（可稍后执行 sudo ysm-admin set-self-ip --auto）"

# ================================================================
# 10. 完成
# ================================================================
echo ""
echo "============================================"
echo "  You Super Markdown v${APP_VER} 安装完成！"
echo "============================================"
echo ""
echo "  网站地址: https://$DOMAIN"
echo "  管理入口: https://$DOMAIN/admin/entry/$ENTRY_TOKEN"
echo "  一次性密码: $OTP"
echo ""
echo "  ⚠️ 以上信息仅显示一次，请立即保存！"
echo ""
echo "  高级管理员账号已创建（凭据不展示；进后台请使用上方 OTP 入口或 ysm-admin login）"
echo "  CLI 管理工具: ysm-admin login"
echo "  守护进程: systemctl status ysm-guard"
if [ "${HFISH_INSTALLED:-false}" = true ]; then
    echo ""
    echo "  Hfish 蜜罐:"
    echo "    管理面板端口: ${HFISH_PANEL_PORT}（SSH 隧道访问 → ysm-admin hfish-panel）"
    echo "    节点通信端口: ${HFISH_NODE_PORT}"
    echo "    蜜獾账户:     ${HFISH_USER}"
    if [ "${HFISH_PASSWORD_GENERATED:-false}" = true ]; then
        echo "    蜜獾密码:     $HFISH_PASSWORD"
    fi
    echo "    查看状态:     ysm-admin hfish-status"
fi
echo ""
echo "  下次登录需执行: sudo ysm-admin login"
echo "============================================"
echo ""

# 保存到 root 只读文件
cat > /root/ysm-credentials.txt << EOF
You Super Markdown 管理员凭证
========================
网站: https://$DOMAIN
首次 OTP 入口: https://$DOMAIN/admin/entry/$ENTRY_TOKEN
首次 OTP 密码: $OTP
（高级管理员账号凭据不展示；后续管理入口请用 sudo ysm-admin login 生成）
安装时间: $(date)
EOF
chmod 600 /root/ysm-credentials.txt
log "凭证已保存到 /root/ysm-credentials.txt"