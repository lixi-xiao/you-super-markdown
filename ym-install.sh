#!/bin/bash
# ================================================================
# You Super Markdown v2.3.3 一键安装脚本
# 功能：部署源码 + 配置 Nginx + 守护进程 + 防火墙 + SSL + CLI 工具
# 使用：sudo bash ym-install.sh
# ================================================================
set -e

# 解析命令行参数
INSTALL_HFISH=true
for arg in "$@"; do
    case $arg in
        --skip-hfish)
            INSTALL_HFISH=false
            shift
            ;;
        --help|-h)
            echo "用法: sudo bash ym-install.sh [选项]"
            echo ""
            echo "选项:"
            echo "  --skip-hfish    跳过 Hfish 蜜罐安装"
            echo "  --help, -h      显示此帮助信息"
            exit 0
            ;;
    esac
done

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
echo "  You Super Markdown v2.3.3 安装脚本"
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
        apt-get update -qq
        # 检测已安装的 PHP 版本
        PHP_VER=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null || echo "8.4")
        log "检测到 PHP 版本: $PHP_VER"
        apt-get install -y -qq nginx "php${PHP_VER}-fpm" "php${PHP_VER}-cli" "php${PHP_VER}-zip" "php${PHP_VER}-mbstring" "php${PHP_VER}-curl" certbot python3 python3-pip ufw > /dev/null 2>&1 || \
        apt-get install -y -qq nginx libnginx-mod-http-php php-fpm php-cli php-zip php-mbstring php-curl certbot python3 python3-pip ufw > /dev/null 2>&1
        ;;
    centos|rhel|fedora)
        yum install -y -q nginx php php-fpm php-zip php-mbstring php-curl certbot python3 python3-pip > /dev/null 2>&1
        ;;
    *)
        warn "未识别的系统，请手动安装: nginx, php8.x, python3, certbot"
        ;;
esac

# 安装 Python 依赖
pip3 install inotify 2>/dev/null || warn "inotify 安装失败，将使用轮询模式"

# ================================================================
# 1. 参数收集
# ================================================================
echo ""
log "请提供以下部署信息："

read -p "  域名 (如 youmarkdown.example.com): " DOMAIN
if [ -z "$DOMAIN" ]; then
    err "域名不能为空"
fi

read -p "  Web 根目录 (默认 /var/www/you-markdown): " WEB_ROOT
WEB_ROOT=${WEB_ROOT:-/var/www/you-markdown}

read -p "  管理员邮箱 (告警通知): " ADMIN_EMAIL
if [ -z "$ADMIN_EMAIL" ]; then
    warn "未提供邮箱，告警功能将不可用"
fi

echo ""
info "部署参数确认："
echo "  域名:       $DOMAIN"
echo "  Web 根目录: $WEB_ROOT"
echo "  管理员邮箱: ${ADMIN_EMAIL:-未设置}"
echo ""
read -p "确认继续? (y/n): " confirm
if [ "$confirm" != "y" ]; then
    err "已取消"
fi

# ================================================================
# 2. 部署项目文件
# ================================================================
log "部署项目文件到 $WEB_ROOT ..."

# 获取脚本所在目录（项目源码目录）
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# 创建 Web 根目录
mkdir -p "$WEB_ROOT"

# 拷贝源码（排除不需要的文件和旧版目录）
rsync -av --exclude='恢复.zip' --exclude='test_*.py' --exclude='__pycache__' \
    --exclude='youyou/' "$SCRIPT_DIR/" "$WEB_ROOT/" > /dev/null 2>&1 || \
    cp -r "$SCRIPT_DIR/"* "$WEB_ROOT/" 2>/dev/null

# 清理旧版残留目录
rm -rf "$WEB_ROOT/youyou" 2>/dev/null

# 设置权限
chown -R root:www-data "$WEB_ROOT"
find "$WEB_ROOT" -type d -exec chmod 755 {} \;
find "$WEB_ROOT" -type f -exec chmod 644 {} \;
chmod 755 "$WEB_ROOT/admin" "$WEB_ROOT/station" "$WEB_ROOT/author" 2>/dev/null || true

# data 目录可写
if [ -d "$WEB_ROOT/data" ]; then
    chown -R www-data:www-data "$WEB_ROOT/data"
    chmod 755 "$WEB_ROOT/data"
fi

# 创建必要的子目录
mkdir -p "$WEB_ROOT/data/articles" "$WEB_ROOT/data/comments" "$WEB_ROOT/data/bg" "$WEB_ROOT/data/avatars"
chown -R www-data:www-data "$WEB_ROOT/data"

log "文件部署完成"

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
        "signature": "系统管理员",
        "role": "super_admin",
        "created": "$(date '+%Y-%m-%d %H:%M:%S')"
    }
]
EOF
chown www-data:www-data "$WEB_ROOT/data/.users.json"
chmod 640 "$WEB_ROOT/data/.users.json"

# 初始化角色定义
cat > "$WEB_ROOT/data/.roles.json" << 'EOF'
{
    "super_admin": {"label": "高级管理员", "can": ["*"]},
    "station_admin": {"label": "站长", "can": ["article.create","article.edit","article.delete","article.edit_any","article.delete_any","author.create","author.delete","user.view"]},
    "author": {"label": "写作者", "can": ["article.create","article.edit_own","article.delete_own"]},
    "user": {"label": "用户", "can": ["comment.create","profile.edit"]},
    "guest": {"label": "访客", "can": ["article.read"]}
}
EOF
chown www-data:www-data "$WEB_ROOT/data/.roles.json"

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
    "hide_default_paths": true
}
EOF
chown www-data:www-data "$WEB_ROOT/data/.config.json"

log "管理员账号已创建"

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

# ================================================================
# 5. 配置 Nginx
# ================================================================
log "配置 Nginx..."

NGINX_CONF="/etc/nginx/sites-available/$DOMAIN"
cat > "$NGINX_CONF" << EOF
server {
    listen 80;
    server_name $DOMAIN;
    return 301 https://\$server_name\$request_uri;
}

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

    # 禁止访问数据目录
    location ~ ^/data/.*\.json\$ {
        deny all;
        return 403;
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

# 启用站点
ln -sf "$NGINX_CONF" "/etc/nginx/sites-enabled/$DOMAIN" 2>/dev/null || \
    ln -sf "$NGINX_CONF" "/etc/nginx/conf.d/$DOMAIN.conf" 2>/dev/null

# Nginx 语法检查
if nginx -t 2>/dev/null; then
    systemctl reload nginx
    log "Nginx 配置完成"
else
    warn "Nginx 配置有误，请手动检查"
fi

# ================================================================
# 6. SSL 证书
# ================================================================
log "申请 SSL 证书..."
if [ -n "$ADMIN_EMAIL" ]; then
    certbot certonly --webroot -w "$WEB_ROOT" -d "$DOMAIN" --email "$ADMIN_EMAIL" --agree-tos --non-interactive 2>/dev/null || \
        warn "SSL 证书申请失败，请手动执行: certbot --nginx -d $DOMAIN"
else
    certbot certonly --webroot -w "$WEB_ROOT" -d "$DOMAIN" --agree-tos --non-interactive --register-unsafely-without-email 2>/dev/null || \
        warn "SSL 证书申请失败，请手动执行: certbot --nginx -d $DOMAIN"
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

# 创建日志镜像目录
mkdir -p /opt/you-markdown/logs
chown www-data:www-data /opt/you-markdown/logs
chmod 750 /opt/you-markdown/logs

# 安装守护进程脚本
cp "$SCRIPT_DIR/ym-guard.py" /opt/you-markdown/ym-guard.py
chmod 700 /opt/you-markdown/ym-guard.py

# 创建邮件告警脚本
cat > /usr/local/bin/ym-alert << 'EOF'
#!/bin/bash
TO="$1"
SUBJECT="$2"
BODY="$3"
echo "$BODY" | mail -s "$SUBJECT" "$TO" 2>/dev/null || true
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
# 每天凌晨3点校验审计日志
0 3 * * * root /usr/bin/python3 /opt/you-markdown/ym-guard.py --verify-only 2>/dev/null | mail -s "You Super Markdown 每日审计报告" $ADMIN_EMAIL
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

    echo ""
    log "============================================"
    log "  Hfish 蜜罐部署（可选安全组件）"
    log "============================================"
    echo ""

    read -p "  是否安装 Hfish 蜜罐? (Y/n, 默认安装): " hfish_confirm
    hfish_confirm=${hfish_confirm:-Y}
    if [ "$hfish_confirm" != "Y" ] && [ "$hfish_confirm" != "y" ]; then
        info "已跳过 Hfish 蜜罐安装"
        HFISH_INSTALLED=false
        return 0
    fi

    # 读取蜜獾账户名（app-config.json -> hfish_user，默认 xiao）
    HFISH_USER=$(php -r "\$c = json_decode(@file_get_contents('$SCRIPT_DIR/app-config.json'), true); echo \$c['hfish_user'] ?? 'xiao';" 2>/dev/null)
    HFISH_USER=${HFISH_USER:-xiao}

    # 蜜獾账户密码：优先 $HFISH_PASSWORD 环境变量，否则交互输入（默认与服务器登录密码一致）
    if [ -z "${HFISH_PASSWORD:-}" ]; then
        read -s -p "  蜜獾账户密码 (默认与服务器登录密码一致): " HFISH_PASSWORD
        echo ""
        HFISH_PASSWORD=${HFISH_PASSWORD:-"${SERVER_PASSWORD:-}"}
    fi
    if [ -z "${HFISH_PASSWORD:-}" ]; then
        warn "未设置蜜獾账户密码，将使用默认强随机密码"
        HFISH_PASSWORD=$(openssl rand -base64 12 | tr -d '=+/')
    fi
    info "蜜獾账户: $HFISH_USER"

    echo ""
    info "配置蜜罐端口（请勿使用已占用的端口）："

    read -p "  Web 管理面板端口 (默认 4433): " HFISH_PANEL_PORT
    HFISH_PANEL_PORT=${HFISH_PANEL_PORT:-4433}

    read -p "  节点通信端口 (默认 4434): " HFISH_NODE_PORT
    HFISH_NODE_PORT=${HFISH_NODE_PORT:-4434}

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
        info "Hfish 管理面板: https://$DOMAIN:$HFISH_PANEL_PORT"
        info "蜜獾账户: $HFISH_USER（密码已配置，登录后请妥善保管）"
    else
        warn "Hfish 服务启动失败，请检查: systemctl status hfish"
        HFISH_INSTALLED=false
    fi

    # 防火墙配置
    log "配置 Hfish 防火墙规则..."
    ufw allow ${HFISH_PANEL_PORT}/tcp comment 'Hfish web panel' > /dev/null 2>&1 || true
    ufw allow ${HFISH_NODE_PORT}/tcp comment 'Hfish node' > /dev/null 2>&1 || true
    ufw reload > /dev/null 2>&1 || true
    log "Hfish 防火墙规则已配置"
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
        echo "  challenge                 生成挑战码"
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
echo "  You Super Markdown v2.3.3 安装完成！"
echo "============================================"
echo ""
echo "  网站地址: https://$DOMAIN"
echo "  管理入口: https://$DOMAIN/admin/entry/$ENTRY_TOKEN"
echo "  一次性密码: $OTP"
echo "  管理账号: $SUPER_QQ"
echo "  管理密码: $SUPER_PASSWORD"
echo ""
echo "  ⚠️ 以上信息仅显示一次，请立即保存！"
echo ""
echo "  CLI 管理工具: ym-admin login"
echo "  守护进程: systemctl status ym-guard"
if [ "${HFISH_INSTALLED:-false}" = true ]; then
    echo ""
    echo "  Hfish 蜜罐:"
    echo "    假 HTTP 端口: ${HFISH_HTTP_PORT}"
    echo "    假 SSH 端口:  ${HFISH_SSH_PORT}"
    echo "    管理面板:     SSH 隧道访问 → ym-admin hfish-panel"
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
管理账号: $SUPER_QQ
管理密码: $SUPER_PASSWORD
首次 OTP 入口: https://$DOMAIN/admin/entry/$ENTRY_TOKEN
首次 OTP 密码: $OTP
安装时间: $(date)
EOF
chmod 600 /root/ym-credentials.txt
log "凭证已保存到 /root/ym-credentials.txt"