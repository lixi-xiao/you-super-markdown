#!/bin/bash
# ================================================================
# You Super Markdown 一键安装脚本（版本读取自 app-config.json）
# 功能：部署源码 + 配置 Nginx + 守护进程 + 防火墙 + SSL + CLI 工具
# 使用：sudo bash ym-install.sh
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
            AUTO_YES=true
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
            echo "用法: sudo bash ym-install.sh [选项]"
            echo ""
            echo "选项（可与环境变量互换，参数优先）:"
            echo "  --yes, -y          全自动模式（所有交互用默认值/自动生成，需提供 --domain）"
            echo "  --domain=域名       站点域名（等价环境变量 YM_DOMAIN）"
            echo "  --web-root=路径     Web 根目录（默认 /var/www/you-markdown，等价 YM_WEB_ROOT）"
            echo "  --email=邮箱        管理员邮箱（告警通知，等价 YM_ADMIN_EMAIL）"
            echo "  --skip-hfish       跳过 Hfish 蜜罐安装"
            echo "  --hfish-password=密 蜜獾账户密码（留空自动生成强密码，等价 YM_HFISH_PASSWORD）"
            echo "  --hfish-port-panel=端口  蜜獾管理面板端口（默认 4433，自动检测占用，等价 YM_HFISH_PANEL_PORT）"
            echo "  --hfish-port-node=端口   蜜獾节点通信端口（默认 4434，等价 YM_HFISH_NODE_PORT）"
            echo "  --help, -h         显示此帮助信息"
            echo ""
            echo "示例:"
            echo "  sudo bash ym-install.sh                              # 交互式（小白默认流程）"
            echo "  sudo bash ym-install.sh --yes --domain=blog.example.com   # 全自动"
            echo "  YM_DOMAIN=x.example.com sudo bash ym-install.sh      # 环境变量方式"
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
    err "请使用 root 权限运行此脚本 (sudo bash ym-install.sh)"
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

# 域名：--domain / YM_DOMAIN > 交互输入（必填）
DOMAIN="${DOMAIN_ARG:-${YM_DOMAIN:-}}"
if [ -z "$DOMAIN" ]; then
    if [ "$AUTO_YES" = true ]; then
        err "全自动模式需提供域名: --domain=你的域名 (或环境变量 YM_DOMAIN)"
    fi
    read -p "  域名 (必填，如 youmarkdown.example.com): " DOMAIN
    if [ -z "$DOMAIN" ]; then
        err "域名不能为空"
    fi
fi

# Web 根目录：--web-root / YM_WEB_ROOT > 交互默认 /var/www/you-markdown
WEB_ROOT="${WEB_ROOT_ARG:-${YM_WEB_ROOT:-}}"
if [ -z "$WEB_ROOT" ]; then
    if [ "$AUTO_YES" = true ]; then
        WEB_ROOT="/var/www/you-markdown"
    else
        read -p "  Web 根目录 (默认 /var/www/you-markdown): " WEB_ROOT
        WEB_ROOT=${WEB_ROOT:-/var/www/you-markdown}
    fi
fi

# 管理员邮箱：--email / YM_ADMIN_EMAIL > 交互（可留空，用于告警与 Let's Encrypt）
ADMIN_EMAIL="${EMAIL_ARG:-${YM_ADMIN_EMAIL:-}}"
if [ -z "$ADMIN_EMAIL" ] && [ "$AUTO_YES" != true ]; then
    read -p "  管理员邮箱 (告警通知, 可留空): " ADMIN_EMAIL
fi
if [ -z "$ADMIN_EMAIL" ]; then
    warn "未提供邮箱，告警/Let's Encrypt 功能将不可用（可后续在超管后台配置）"
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

# 拷贝源码（排除不需要的文件和旧版目录；自身 ym-install.sh 不部署进 Web 根，
# 纵深防御：即使 nginx 配置失误，攻击者也无法通过 Web 下载安装脚本源码）
rsync -av --exclude='恢复.zip' --exclude='test_*.py' --exclude='__pycache__' \
    --exclude='youyou/' --exclude='ym-install.sh' "$SCRIPT_DIR/" "$WEB_ROOT/" > /dev/null 2>&1 || \
    cp -r "$SCRIPT_DIR/"* "$WEB_ROOT/" 2>/dev/null

# 清理旧版残留目录
rm -rf "$WEB_ROOT/youyou" 2>/dev/null

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
mkdir -p "$WEB_ROOT/data/articles" "$WEB_ROOT/data/comments" "$WEB_ROOT/data/bg" "$WEB_ROOT/data/avatars" "$WEB_ROOT/data/cache/thumbs"
chown -R www-data:www-data "$WEB_ROOT/data"

# 将调用本脚本的管理员加入 www-data 组（CLI 只读命令无需 sudo 即可读取 SQLite）
if [ -n "${SUDO_USER:-}" ] && [ "$SUDO_USER" != "root" ]; then
    usermod -aG www-data "$SUDO_USER" 2>/dev/null || warn "无法将 $SUDO_USER 加入 www-data 组，只读 CLI 命令请使用 sudo"
fi

log "文件部署完成"

# 写入站点域名到 app-config.json（供 ym-admin 生成管理入口 URL；v2.10.2 起禁止 ym-admin 硬编码域名）
php -r "
    \$p = '$WEB_ROOT/app-config.json';
    if (file_exists(\$p)) {
        \$c = json_decode(file_get_contents(\$p), true) ?: [];
        \$c['site_url'] = '$DOMAIN';
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
SUPER_QQ="admin_$(openssl rand -hex 4)"

# 创建用户数据
mkdir -p "$WEB_ROOT/data"
cat > "$WEB_ROOT/data/.users.json" << EOF
[
    {
        "id": "$SUPER_ID",
        "qq": "$SUPER_QQ",
        "nickname": "高级管理员",
        "password": "$SUPER_PASSWORD_HASH",
        "avatar": "",
        "signature": "高级管理员",
        "role": "super_admin",
        "created": "$(date '+%Y-%m-%d %H:%M:%S')"
    }
]
EOF
chown www-data:www-data "$WEB_ROOT/data/.users.json"
chmod 640 "$WEB_ROOT/data/.users.json"

# 角色定义已内置在 utils.php loadRoles() 的默认值中，无需单独落盘

# 生成 JWT 密钥
JWT_SECRET=$(openssl rand -hex 32)
echo "$JWT_SECRET" > "$WEB_ROOT/data/.jwt_secret"
chmod 600 "$WEB_ROOT/data/.jwt_secret"
chown www-data:www-data "$WEB_ROOT/data/.jwt_secret"

# 初始化配置
cat > "$WEB_ROOT/data/.config.json" << EOF
{
    "site_title": "You Super Markdown",
    "registration_enabled": true,
    "guest_comments_enabled": false,
    "admin_email": "${ADMIN_EMAIL}",
    "update_channel": "stable",
    "auto_ban": true,
    "auto_ban_unauthorized": true,
    "max_login_fails": 10,
    "station_path": "station",
    "author_path": "author",
    "hide_default_paths": true,
    "email_verify_enabled": ${VERIFY_EMAIL_FLAG},
    "author_dual_verify_enabled": ${VERIFY_DUAL_FLAG},
    "verify_code_ttl": 300,
    "confirm_link_ttl": 86400,
    "resend_cooldown": 60
}
EOF
chown www-data:www-data "$WEB_ROOT/data/.config.json"

log "高级管理员账号已创建（凭据不展示，进后台请用上方 OTP 入口或 ym-admin login）"

# ================================================================
# 4. 生成 OTP 入口
# ================================================================
log "生成 OTP 动态入口..."

ENTRY_TOKEN=$(openssl rand -base64 9 | tr -d '=+/' | cut -c1-12)
OTP=$(openssl rand -base64 9 | tr -d '=+/' | cut -c1-12)
OTP_HASH=$(php -r "echo password_hash('$OTP', PASSWORD_DEFAULT);")
ENTRY_EXPIRES=$(( $(date +%s) + 600 ))

cat > "$WEB_ROOT/data/.entries.json" << EOF
[
    {
        "token": "$ENTRY_TOKEN",
        "otp_hash": "$OTP_HASH",
        "expires": $ENTRY_EXPIRES,
        "used": 0,
        "created": "$(date '+%Y-%m-%d %H:%M:%S')"
    }
]
EOF
chown www-data:www-data "$WEB_ROOT/data/.entries.json"

# 将种子 JSON 数据导入 SQLite（v2.5.0 起使用 SQLite；幂等）
log "初始化 SQLite 数据库..."
php "$WEB_ROOT/ym-migrate" 2>&1 || warn "数据迁移失败，请手动执行: php $WEB_ROOT/ym-migrate"
# 确保 PHP-FPM（www-data）可写 SQLite 及 WAL/SHM 文件；data 目录组可写供 CLI 只读命令无 sudo 读取
chown -R www-data:www-data "$WEB_ROOT/data"
chmod 775 "$WEB_ROOT/data" 2>/dev/null || true
chmod 660 "$WEB_ROOT/data/ym.db" 2>/dev/null || true

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
    location ~ ^/data/ym\.db(-wal|-shm)?\$ {
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

    # 禁止访问脚本/配置/备份等敏感文件（安装脚本、守护进程、蜜罐同步等源码不得外泄）
    location ~* \.(sh|py|conf|bak|sql|log)\$ {
        deny all;
        return 403;
    }

    # 禁止访问 CLI/安装/迁移/调试文件（无后缀脚本显式封禁）
    location ~ ^/(ym-admin|ym-install\.sh|ym-guard\.py|ym-hfish-sync\.py|ym-migrate|test\.php|debug\.php|entry_debug\.php|entry_fixed\.php)\$ {
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
# 7. 部署守护进程
# ================================================================
log "部署守护进程..."

# 创建母本目录
INSTALL_BASE="/opt/you-markdown/install-base"
mkdir -p "$INSTALL_BASE"
rsync -av --exclude='data/' --exclude='*.json' --exclude='youyou/' "$WEB_ROOT/" "$INSTALL_BASE/" > /dev/null 2>&1
rm -rf "$INSTALL_BASE/youyou" 2>/dev/null
chown -R root:root "$INSTALL_BASE"
chmod -R 755 "$INSTALL_BASE"
find "$INSTALL_BASE" -type f -exec chmod 644 {} \;

# chattr +i 锁定母本
chattr -R +i "$INSTALL_BASE" 2>/dev/null || warn "chattr 不可用，母本未锁定（建议安装 e2fsprogs）"

# 创建日志镜像目录（chattr +i 锁定，防 PHP 权限/未知 bug 篡改审计镜像）
mkdir -p /opt/you-markdown/logs
chown www-data:www-data /opt/you-markdown/logs
chmod 750 /opt/you-markdown/logs

# 创建自动备份目录（数据库 30 分钟备份 / 文章每日备份）并 chattr +i 锁定
# 备份目录与母本同理念：root 锁定，守护进程写入时临时解锁→重锁，PHP 权限不可篡改
mkdir -p /opt/you-markdown/backups/db /opt/you-markdown/backups/articles
chown root:www-data /opt/you-markdown/backups /opt/you-markdown/backups/db /opt/you-markdown/backups/articles
chmod 775 /opt/you-markdown/backups /opt/you-markdown/backups/db /opt/you-markdown/backups/articles
chattr -R +i /opt/you-markdown/backups/db /opt/you-markdown/backups/articles 2>/dev/null || warn "chattr 不可用，备份目录未锁定（建议安装 e2fsprogs）"
chattr +i /opt/you-markdown/logs 2>/dev/null || warn "chattr 不可用，日志镜像目录未锁定（建议安装 e2fsprogs）"

# 初始化自动备份配置（默认：库 30 分钟 / 文章保留 7 份 / 手动备份保留 5 份，后台可改）
# v3.3.6：模板补齐 v3.3.5 的两个开关（上传触发立即备份 / 单篇篡改还原），全新安装即完整
cat > /opt/you-markdown/backup.conf << 'BACKUPCONF'
# 自动备份配置（守护进程 ym-guard.py 读取；超管后台/SSH 可改）
DB_BACKUP_INTERVAL_MIN=30
ARTICLE_BACKUP_KEEP=7
MANUAL_BACKUP_KEEP=5
ARTICLE_TRIGGER_BACKUP=1
ARTICLE_SINGLE_RESTORE=1
BACKUPCONF
chown root:www-data /opt/you-markdown/backup.conf
chmod 664 /opt/you-markdown/backup.conf

# 安装守护进程脚本
cp "$SCRIPT_DIR/ym-guard.py" /opt/you-markdown/ym-guard.py
chmod 700 /opt/you-markdown/ym-guard.py

# 创建邮件告警脚本（v2.8.0：mail 失败时落盘 alert.log，可追溯"邮件没发出去"）
touch /opt/you-markdown/alert.log 2>/dev/null || true
chown root:www-data /opt/you-markdown/alert.log 2>/dev/null || true
chmod 664 /opt/you-markdown/alert.log 2>/dev/null || true
cat > /usr/local/bin/ym-alert << 'EOF'
#!/bin/bash
TO="$1"
SUBJECT="$2"
BODY="$3"
if command -v mail >/dev/null 2>&1; then
    echo "$BODY" | mail -s "$SUBJECT" "$TO" 2>/tmp/ym-alert.err
    RC=$?
    if [ $RC -ne 0 ]; then
        ERR=$(head -1 /tmp/ym-alert.err 2>/dev/null)
        echo "$(date '+%Y-%m-%d %H:%M:%S') [FAIL] mail 命令失败(rc=$RC): $ERR" >> /opt/you-markdown/alert.log 2>/dev/null || true
    fi
    rm -f /tmp/ym-alert.err
    exit $RC
else
    echo "$(date '+%Y-%m-%d %H:%M:%S') [FAIL] mail 命令不存在，无法发送告警" >> /opt/you-markdown/alert.log 2>/dev/null || true
    exit 1
fi
EOF
chmod +x /usr/local/bin/ym-alert

# 注册 systemd 服务
APP_NAME=$(php -r "\$c = json_decode(@file_get_contents('$SCRIPT_DIR/app-config.json'), true); echo \$c['app_name'] ?? 'You Super Markdown';" 2>/dev/null)
DOCS_URL=$(php -r "\$c = json_decode(@file_get_contents('$SCRIPT_DIR/app-config.json'), true); echo \$c['docs_url'] ?? '';" 2>/dev/null)
cat > /etc/systemd/system/ym-guard.service << EOF
[Unit]
Description=${APP_NAME} File Guard Daemon
Documentation=${DOCS_URL}
After=network.target
Before=nginx.service

[Service]
Type=notify
ExecStart=/usr/bin/python3 /opt/you-markdown/ym-guard.py
Environment=YM_WEB_ROOT=$WEB_ROOT
Environment=PYTHONUNBUFFERED=1
Restart=always
RestartSec=5
WatchdogSec=30
User=root
Group=root
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=$WEB_ROOT /opt/you-markdown
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
systemctl enable ym-guard
systemctl start ym-guard
log "守护进程已启动"

# 注册 cron 兜底
cat > /etc/cron.d/ym-guard << EOF
# You Super Markdown 守护进程兜底检查（每5分钟）
*/5 * * * * root /usr/bin/systemctl is-active --quiet ym-guard || /usr/bin/systemctl start ym-guard
# 每天凌晨3点发送每日审计报告（ym-admin audit-report：校验哈希链并经 SMTP 发送给管理员，无 MTA 依赖）
0 3 * * * root /usr/local/bin/ym-admin audit-report > /dev/null 2>&1
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

    # 蜜獾账户密码：--hfish-password / YM_HFISH_PASSWORD > 交互输入（留空自动生成强密码）
    HFISH_PASSWORD="${HFISH_PASSWORD_ARG:-${YM_HFISH_PASSWORD:-}}"
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

    # 管理面板端口：--hfish-port-panel / YM_HFISH_PANEL_PORT > 交互默认 4433 > 自动检测占用
    HFISH_PANEL_PORT="${HFISH_PANEL_PORT_ARG:-${YM_HFISH_PANEL_PORT:-}}"
    if [ -z "$HFISH_PANEL_PORT" ] && [ "$AUTO_YES" != true ]; then
        read -p "  Web 管理面板端口 (默认 4433): " HFISH_PANEL_PORT
    fi
    HFISH_PANEL_PORT=$(pick_free_port "${HFISH_PANEL_PORT:-4433}")

    # 节点通信端口：--hfish-port-node / YM_HFISH_NODE_PORT > 交互默认 4434 > 自动检测占用且避开面板端口
    HFISH_NODE_PORT="${HFISH_NODE_PORT_ARG:-${YM_HFISH_NODE_PORT:-}}"
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

    # 保存端口配置（供 ym-admin 读取）
    mkdir -p /opt/you-markdown
    cat > /opt/you-markdown/hfish-ports.conf << PORTCONF
HFISH_PANEL_PORT=$HFISH_PANEL_PORT
HFISH_NODE_PORT=$HFISH_NODE_PORT
HFISH_USER=$HFISH_USER
PORTCONF
    chmod 600 /opt/you-markdown/hfish-ports.conf

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
    if systemctl is-active --quiet hfish 2>/dev/null || pgrep -x hfish > /dev/null 2>&1; then
        log "Hfish 蜜罐服务已启动"
        HFISH_INSTALLED=true
        # 确保 systemd 托管（官方脚本可能仅用 nohup/crontab，此处统一规范管理）
        if [ -f /etc/systemd/system/hfish.service ]; then
            systemctl enable hfish > /dev/null 2>&1 || true
        fi
        # 自动配置蜜獾账户：名称改为 hfish_user，密码与服务器一致
        configure_hfish_account "$HFISH_USER" "$HFISH_PASSWORD"
        # 设计指标：管理面板仅本机可访问，需经 SSH 隧道（ym-admin hfish-panel），不提供公网 URL
        info "Hfish 管理面板: 仅本机访问（SSH 隧道 → sudo ym-admin hfish-panel）"
        info "蜜獾账户: $HFISH_USER（密码已配置，登录后请妥善保管）"
    else
        warn "Hfish 服务启动失败，请检查: systemctl status hfish"
        HFISH_INSTALLED=false
    fi

    # 防火墙配置
    # 设计指标：管理面板端口（HFISH_PANEL_PORT）不开放公网，仅经 ym-admin hfish-panel SSH 隧道本机访问；
    # 节点通信端口为蜜罐诱饵/节点通道，需公网可达才能捕获攻击者（蜜罐本意：故意暴露的诱饵），故放行
    log "配置 Hfish 防火墙规则..."
    ufw allow "${HFISH_NODE_PORT}/tcp" comment 'Hfish node' > /dev/null 2>&1 || true
    # 清理历史残留：旧版安装脚本（≤v2.2.x）曾把面板端口放行到公网，
    # 此处主动删除（默认 4433 + 当前面板端口），确保旧服务器升级/重装后面板仍仅经 SSH 隧道本机可达
    for _p in "${HFISH_PANEL_PORT}" 4433; do
        ufw delete allow "${_p}/tcp" > /dev/null 2>&1 || true
    done
    ufw reload > /dev/null 2>&1 || true
    log "Hfish 防火墙规则已配置（管理面板端口 ${HFISH_PANEL_PORT} 不开放公网，仅 SSH 隧道访问）"
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

# 9.3 邮件告警配置（可选，v2.8.0：SMTP 直连，配置写入 config 表；后台「邮件设置」可随时修改/测试）
configure_mail() {
    if [ "$AUTO_YES" = true ] && [ -z "${YM_SMTP_HOST:-}" ]; then
        info "已跳过邮件配置（后台「邮件设置」可随时配置，或环境变量 YM_SMTP_*）"
        return 0
    fi
    echo ""
    log "============================================"
    log "  邮件告警配置（可选，建议配置以接收安全告警）"
    log "============================================"
    echo ""
    read -p "  是否配置 SMTP 邮件? (y/N, 默认跳过): " mail_confirm
    mail_confirm=${mail_confirm:-N}
    if [ "$mail_confirm" != "y" ] && [ "$mail_confirm" != "Y" ]; then
        info "已跳过邮件配置（后台「邮件设置」可随时配置）"
        return 0
    fi
    SMTP_HOST="${YM_SMTP_HOST:-}"
    SMTP_PORT="${YM_SMTP_PORT:-465}"
    SMTP_ENC="${YM_SMTP_ENC:-ssl}"
    SMTP_USER="${YM_SMTP_USER:-}"
    SMTP_PASS="${YM_SMTP_PASS:-}"
    SMTP_FROM="${YM_SMTP_FROM:-}"
    [ -z "$SMTP_HOST" ] && read -p "  SMTP 服务器 (如 smtp.163.com): " SMTP_HOST
    [ -z "$SMTP_PORT" ] && read -p "  端口 (默认 465): " SMTP_PORT
    [ -z "$SMTP_ENC" ] && read -p "  加密方式 (ssl/tls/plain, 默认 ssl): " SMTP_ENC
    [ -z "$SMTP_USER" ] && read -p "  发信账号 (如 xxx@163.com): " SMTP_USER
    if [ -z "$SMTP_PASS" ]; then
        read -s -p "  授权码 (不回显): " SMTP_PASS
        echo ""
    fi
    [ -z "$SMTP_FROM" ] && read -p "  发件人 (可空=账号): " SMTP_FROM
    SMTP_PORT=${SMTP_PORT:-465}
    SMTP_ENC=${SMTP_ENC:-ssl}
    # v3.0.9：授权码改为环境变量注入（密钥不落 Web 可达盘）——
    # ① php-fpm pool env[YM_SMTP_PASS]（root 只读，Web/DB 均不可见）
    # ② root 密钥文件 /opt/you-markdown/secrets/smtp_pass（0600，供 CLI 发信如 audit-report 注入）
    SECRETS_DIR="/opt/you-markdown/secrets"
    mkdir -p "$SECRETS_DIR"
    printf '%s' "$SMTP_PASS" > "$SECRETS_DIR/smtp_pass"
    chmod 600 "$SECRETS_DIR/smtp_pass"
    POOL_CONF="/etc/php/${PHP_VER}/fpm/pool.d/www.conf"
    if [ -f "$POOL_CONF" ]; then
        sed -i '/env\[YM_SMTP_PASS\]/d' "$POOL_CONF" 2>/dev/null || true
        ESC_PASS=$(printf '%s' "$SMTP_PASS" | sed 's/[\\"]/\\&/g')
        printf '\nenv[YM_SMTP_PASS] = "%s"\n' "$ESC_PASS" >> "$POOL_CONF"
        systemctl reload "php${PHP_VER}-fpm" 2>/dev/null || true
        info "授权码已注入 php-fpm 环境变量（$POOL_CONF，Web 端不可见）"
    else
        warn "未找到 php-fpm pool 配置（$POOL_CONF），授权码仅存 root 密钥文件"
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
    # 初次配置即测试：发一封测试邮件到管理员邮箱（CLI 注入 YM_SMTP_PASS 走 root 密钥文件）
    if [ -n "$ADMIN_EMAIL" ]; then
        echo ""
        log "发送测试邮件到 $ADMIN_EMAIL ..."
        TEST_RESULT=$(YM_SMTP_PASS="$(cat "$SECRETS_DIR/smtp_pass" 2>/dev/null)" \
        ADMIN_EMAIL_B64=$(printf '%s' "$ADMIN_EMAIL" | base64 -w0 2>/dev/null || printf '%s' "$ADMIN_EMAIL" | base64) \
        php -r "
            require '$WEB_ROOT/utils.php';
            \$to = base64_decode(getenv('ADMIN_EMAIL_B64'));
            [\$ok, \$err] = sendSmtpMail(\$to, '[You Super Markdown 安装成功] 邮件配置测试', '邮件配置测试：如果你收到此邮件，说明 SMTP 配置正确，安全告警可以正常发送。');
            echo \$ok ? 'OK' : ('FAIL: ' . \$err);
        " 2>/dev/null)
        case "$TEST_RESULT" in
            OK*) info "✅ 测试邮件发送成功（请查收 $ADMIN_EMAIL）" ;;
            *) warn "⚠️ 测试邮件发送失败：${TEST_RESULT#FAIL: }（可在服务器重配 YM_SMTP_PASS 后重试）" ;;
        esac
    else
        warn "未设置管理员邮箱，跳过测试邮件（请在后台「系统配置」设置 admin_email）"
    fi
}
configure_mail

# ================================================================
# 9. 安装 CLI 管理工具
# ================================================================
log "安装 CLI 管理工具..."

cat > /usr/local/bin/ym-admin << 'EOF'
#!/bin/bash
WEB_ROOT="${YM_WEB_ROOT:-/var/www/you-markdown}"
ENTRIES_FILE="$WEB_ROOT/data/.entries.json"
USERS_FILE="$WEB_ROOT/data/.users.json"

case "${1:-}" in
    login)
        ENTRY_TOKEN=$(openssl rand -base64 9 | tr -d '=+/' | cut -c1-12)
        OTP=$(openssl rand -base64 9 | tr -d '=+/' | cut -c1-12)
        OTP_HASH=$(php -r "echo password_hash('$OTP', PASSWORD_DEFAULT);")
        EXPIRES=$(( $(date +%s) + 600 ))

        echo '[{"token":"'$ENTRY_TOKEN'","otp_hash":"'$OTP_HASH'","expires":'$EXPIRES',"used":0,"created":"'$(date '+%Y-%m-%d %H:%M:%S')'"}]' > "$ENTRIES_FILE"
        chown www-data:www-data "$ENTRIES_FILE" 2>/dev/null || true

        echo ""
        echo "============================================"
        echo "  管理入口（仅显示一次，10分钟有效）"
        echo "============================================"
        echo ""
        echo "  入口 URL: https://$(grep -m1 'server_name' /etc/nginx/sites-enabled/*.conf 2>/dev/null | grep -v 'server_name _' | awk '{print $2}' | tr -d ';' | head -1)/admin/entry/$ENTRY_TOKEN"
        echo "  一次性密码: $OTP"
        echo ""
        echo "  ⚠️ 请立即保存！"
        echo "============================================"
        echo ""
        ;;

    create-station)
        NAME="${2:-}"
        if [ -z "$NAME" ]; then echo "用法: ym-admin create-station <站长名称>"; exit 1; fi
        QQ="station_$(openssl rand -hex 4)"
        PWD=$(openssl rand -base64 12 | tr -d '=+/')
        php -r "
            \$users = json_decode(file_get_contents('$USERS_FILE'), true);
            \$users[] = ['id'=>bin2hex(random_bytes(8)),'qq'=>'$QQ','nickname'=>'$NAME','password'=>password_hash('$PWD',PASSWORD_DEFAULT),'role'=>'station_admin','created'=>date('Y-m-d H:i:s')];
            file_put_contents('$USERS_FILE', json_encode(\$users, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        "
        echo "站长账号已创建: $NAME (QQ: $QQ, 密码: $PWD)"
        ;;

    create-author)
        NAME="${2:-}"
        STATION_ID="${3:-}"
        if [ -z "$NAME" ]; then echo "用法: ym-admin create-author <写作者名称> [站长ID]"; exit 1; fi
        QQ="author_$(openssl rand -hex 4)"
        PWD=$(openssl rand -base64 12 | tr -d '=+/')
        php -r "
            \$users = json_decode(file_get_contents('$USERS_FILE'), true);
            \$users[] = ['id'=>bin2hex(random_bytes(8)),'qq'=>'$QQ','nickname'=>'$NAME','password'=>password_hash('$PWD',PASSWORD_DEFAULT),'role'=>'author','station_id'=>'$STATION_ID','created'=>date('Y-m-d H:i:s')];
            file_put_contents('$USERS_FILE', json_encode(\$users, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        "
        echo "写作者账号已创建: $NAME (QQ: $QQ, 密码: $PWD)"
        ;;

    revoke-user)
        USER_ID="${2:-}"
        if [ -z "$USER_ID" ]; then echo "用法: ym-admin revoke-user <用户ID>"; exit 1; fi
        php -r "
            \$users = json_decode(file_get_contents('$USERS_FILE'), true);
            \$users = array_values(array_filter(\$users, fn(\$u) => (\$u['id']??'')!=='$USER_ID'));
            file_put_contents('$USERS_FILE', json_encode(\$users, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        "
        echo "用户已吊销: $USER_ID"
        ;;

    backup)
        BACKUP_DIR="/opt/you-markdown/backups"
        mkdir -p "$BACKUP_DIR"
        tar -czf "$BACKUP_DIR/ym-backup-$(date +%Y%m%d-%H%M%S).tar.gz" -C "$WEB_ROOT" data/ 2>/dev/null
        echo "备份完成: $BACKUP_DIR"
        ;;

    status)
        echo "守护进程: $(systemctl is-active ym-guard 2>/dev/null || echo 'unknown')"
        echo "Nginx: $(systemctl is-active nginx 2>/dev/null || echo 'unknown')"
        PHP_FPM_SVC="php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
        echo "PHP-FPM: $(systemctl is-active $PHP_FPM_SVC 2>/dev/null || systemctl is-active php-fpm 2>/dev/null || echo 'unknown')"
        [ -f "$WEB_ROOT/data/.users.json" ] && echo "用户数: $(php -r "echo count(json_decode(file_get_contents('$USERS_FILE'),true)?:[]);")"
        ;;

    log-verify)
        php -r "
            require_once '$WEB_ROOT/utils.php';
            \$r = verifyAuditChain();
            echo \$r['valid'] ? '审计日志哈希链校验通过 ('.\$r['count'].' 条)' : '校验失败！断裂于第 '.\$r['broken_at'].' 条';
        "
        ;;

    audit-report)
        # 每日审计报告：校验结果经 sendAlert 走 SMTP 直连发送给管理员（无 MTA 依赖，失败落盘 alert.log）
        php -r "
            require_once '$WEB_ROOT/utils.php';
            \$r = verifyAuditChain();
            \$result = \$r['valid'] ? '审计日志哈希链校验通过 ('.\$r['count'].' 条)' : '校验失败！断裂于第 '.\$r['broken_at'].' 条';
            echo \$result . PHP_EOL;
            sendAlert('每日审计报告', \$result);
        "
        ;;

    challenge)
        CODE=$(openssl rand -hex 3)
        EXPIRES=$(( $(date +%s) + 60 ))
        php -r "
            \$f = '$WEB_ROOT/data/.challenge.json';
            \$challenges = file_exists(\$f) ? json_decode(file_get_contents(\$f), true) : [];
            if (!is_array(\$challenges)) \$challenges = [];
            \$challenges = array_filter(\$challenges, function(\$c) { return (\$c['expires'] ?? 0) > time() && empty(\$c['used']); });
            \$challenges[] = ['code'=>'$CODE', 'expires'=>$EXPIRES, 'used'=>0, 'created'=>time()];
            file_put_contents(\$f, json_encode(array_values(\$challenges), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX);
        " 2>/dev/null
        echo "挑战码: $CODE (300秒有效)"
        ;;

    set-smtp-pass)
        # v3.3.7：快捷修改 SMTP 授权码（密钥不落盘：root 密钥文件 + php-fpm 环境变量双写 + reload + 测试邮件）
        if [ "$(id -u)" -ne 0 ]; then
            echo "错误：修改 SMTP 授权码需 root 权限（sudo ym-admin set-smtp-pass）"
            exit 1
        fi
        SMTP_PASS=""
        case "${2:-}" in
            "")
                read -s -p "请输入新的 SMTP 授权码（输入时不可见）: " SMTP_PASS
                echo ""
                ;;
            --pass=*)
                SMTP_PASS="${2#--pass=}"
                ;;
            *)
                echo "用法: ym-admin set-smtp-pass [--pass=新授权码]"
                exit 1
                ;;
        esac
        if [ -z "$SMTP_PASS" ]; then echo "错误：授权码不能为空"; exit 1; fi
        SECRETS_DIR="/opt/you-markdown/secrets"
        mkdir -p "$SECRETS_DIR"
        printf '%s' "$SMTP_PASS" > "$SECRETS_DIR/smtp_pass"
        chmod 600 "$SECRETS_DIR/smtp_pass"
        echo "[1/3] 已更新 CLI 密钥文件: $SECRETS_DIR/smtp_pass (0600)"
        PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)
        POOL_CONF="/etc/php/${PHP_VER}/fpm/pool.d/www.conf"
        if [ -f "$POOL_CONF" ]; then
            sed -i '/env\[YM_SMTP_PASS\]/d' "$POOL_CONF" 2>/dev/null || true
            ESC_PASS=$(printf '%s' "$SMTP_PASS" | sed 's/[\\"]/\\&/g')
            printf '\nenv[YM_SMTP_PASS] = "%s"\n' "$ESC_PASS" >> "$POOL_CONF"
            systemctl reload "php${PHP_VER}-fpm" 2>/dev/null || true
            echo "[2/3] 已更新 Web 端 php-fpm 环境变量并 reload ($POOL_CONF)"
        else
            echo "[2/3] 警告：未找到 php-fpm pool 配置 ($POOL_CONF)，仅更新了 CLI 密钥文件"
        fi
        php -r "require_once '$WEB_ROOT/utils.php'; auditLog('smtp_pass_update', 'smtp', 'CLI 更新 SMTP 授权码（密钥不落盘，未记录明文）');" 2>/dev/null || true
        echo "[3/3] 发送测试邮件..."
        TEST_RESULT=$(YM_SMTP_PASS="$SMTP_PASS" php -r "
            require_once '$WEB_ROOT/utils.php';
            \$cfg = loadSiteConfig();
            \$to = \$cfg['admin_email'] ?? '';
            if (\$to === '') { echo 'NO_ADMIN_EMAIL'; exit(0); }
            \$site = \$cfg['site_title'] ?? 'You Super Markdown';
            \$msg = 'SMTP 授权码已更新成功。若收到此邮件说明新的授权码配置正确，安全告警可正常发送。';
            \$html = renderMailHtml(\$site, '通知', \$msg, ['server' => gethostname(), 'time' => date('Y-m-d H:i:s')]);
            [\$ok, \$err] = sendSmtpMail(\$to, '[You Super Markdown] SMTP 授权码已更新', \$msg, \$html);
            echo \$ok ? 'MAIL_OK' : ('MAIL_FAIL: ' . \$err);
        " 2>/dev/null)
        case "$TEST_RESULT" in
            MAIL_OK) echo "测试邮件发送成功，新的授权码已生效" ;;
            NO_ADMIN_EMAIL) echo "警告：未设置管理员邮箱，跳过测试邮件（后台「系统配置」可设置 admin_email）" ;;
            *) echo "警告：测试邮件发送失败: ${TEST_RESULT#MAIL_FAIL: }（可在后台「邮件设置」检查 SMTP 配置）" ;;
        esac
        echo "SMTP 授权码更新完成"
        ;;

    hfish-panel)
        PORTS_FILE="/opt/you-markdown/hfish-ports.conf"
        if [ ! -f "$PORTS_FILE" ]; then
            echo "Hfish 未安装或配置文件不存在"
            exit 1
        fi
        source "$PORTS_FILE"
        PANEL_PORT="${HFISH_PANEL_PORT:-9001}"
        echo ""
        echo "============================================"
        echo "  Hfish 管理面板隧道已建立"
        echo "============================================"
        echo ""
        echo "  本地访问地址: http://127.0.0.1:${PANEL_PORT}"
        echo ""
        echo "  请在本地浏览器中打开上述地址"
        echo "  按 Ctrl+C 关闭隧道"
        echo "============================================"
        echo ""
        ssh -L "${PANEL_PORT}:127.0.0.1:${PANEL_PORT}" -N root@127.0.0.1
        ;;

    hfish-status)
        echo "=== Hfish 蜜罐状态 ==="
        echo ""
        PORTS_FILE="/opt/you-markdown/hfish-ports.conf"
        if [ -f "$PORTS_FILE" ]; then
            source "$PORTS_FILE"
            echo "配置端口:"
            echo "  假 HTTP 服务: ${HFISH_HTTP_PORT:-8080}"
            echo "  假 SSH 服务:  ${HFISH_SSH_PORT:-2222}"
            echo "  管理面板:     ${HFISH_PANEL_PORT:-9001} (仅 127.0.0.1)"
        else
            echo "  端口配置文件不存在"
        fi
        echo ""
        echo "服务状态:"
        if systemctl is-active --quiet hfish 2>/dev/null; then
            echo "  hfish.service: 运行中"
        else
            echo "  hfish.service: 未运行"
        fi
        ;;

    *)
        echo "You Super Markdown CLI 管理工具"
        echo ""
        echo "用法: ym-admin <命令> [参数]"
        echo ""
        echo "命令:"
        echo "  login                    生成 OTP 管理入口"
        echo "  create-station <名称>     创建站长账号"
        echo "  create-author <名称>      创建写作者账号"
        echo "  revoke-user <用户ID>      吊销用户"
        echo "  backup                    备份数据"
        echo "  status                    查看服务状态"
        echo "  log-verify                校验审计日志哈希链"
        echo "  audit-report              每日审计报告（校验结果经 SMTP 发送给管理员）"
        echo "  challenge                 生成挑战码"
        echo "  set-smtp-pass [--pass=授权码] 修改 SMTP 授权码（需 sudo；密钥文件 + php-fpm 环境变量双写，自动发测试邮件）"
        echo "  hfish-panel               建立 SSH 隧道访问 Hfish 管理面板"
        echo "  hfish-status              查看 Hfish 蜜罐状态"
        echo ""
        echo "环境变量:"
        echo "  YM_WEB_ROOT               Web 根目录 (默认 /var/www/you-markdown)"
        ;;
esac
EOF
chmod +x /usr/local/bin/ym-admin
# 覆盖为项目内完整版（含 apply-update/rollback，challenge 落盘）
if [ -f "$SCRIPT_DIR/ym-admin" ]; then
    cp "$SCRIPT_DIR/ym-admin" /usr/local/bin/ym-admin
    chmod +x /usr/local/bin/ym-admin
fi
log "CLI 管理工具已安装到 /usr/local/bin/ym-admin"

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
echo "  高级管理员账号已创建（凭据不展示；进后台请使用上方 OTP 入口或 ym-admin login）"
echo "  CLI 管理工具: ym-admin login"
echo "  守护进程: systemctl status ym-guard"
if [ "${HFISH_INSTALLED:-false}" = true ]; then
    echo ""
    echo "  Hfish 蜜罐:"
    echo "    管理面板端口: ${HFISH_PANEL_PORT}（SSH 隧道访问 → ym-admin hfish-panel）"
    echo "    节点通信端口: ${HFISH_NODE_PORT}"
    echo "    蜜獾账户:     ${HFISH_USER}"
    if [ "${HFISH_PASSWORD_GENERATED:-false}" = true ]; then
        echo "    蜜獾密码:     $HFISH_PASSWORD"
    fi
    echo "    查看状态:     ym-admin hfish-status"
fi
echo ""
echo "  下次登录需执行: sudo ym-admin login"
echo "============================================"
echo ""

# 保存到 root 只读文件
cat > /root/ym-credentials.txt << EOF
You Super Markdown 管理员凭证
========================
网站: https://$DOMAIN
首次 OTP 入口: https://$DOMAIN/admin/entry/$ENTRY_TOKEN
首次 OTP 密码: $OTP
（高级管理员账号凭据不展示；后续管理入口请用 sudo ym-admin login 生成）
安装时间: $(date)
EOF
chmod 600 /root/ym-credentials.txt
log "凭证已保存到 /root/ym-credentials.txt"