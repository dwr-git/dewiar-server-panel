#!/usr/bin/env bash
# Dewiar Server Panel — установка и обновление. Запускать от root: bash install.sh
set -euo pipefail

[ "$(id -u)" = "0" ] || { echo "Запустите от root: sudo bash install.sh"; exit 1; }
SRC="$(cd "$(dirname "$0")" && pwd)"
APP=/opt/dewiar-panel
LIB=/usr/local/lib/dewiar-panel
ETC=/etc/dewiar-panel
DATA=/var/lib/dewiar-panel
PUSER=dewiar-panel

echo "== Dewiar Server Panel: установка =="

# 1. PHP для самой панели (не зависит от PHP сайтов)
find_php() {
  local c
  for c in "$(command -v php 2>/dev/null || true)" /opt/php8*/bin/php /opt/php7*/bin/php /usr/bin/php8* /usr/bin/php7*; do
    [ -n "$c" ] && [ -x "$c" ] || continue
    if "$c" -d disable_functions= -r 'exit(PHP_VERSION_ID>=70400 && function_exists("proc_open") && function_exists("json_encode") && function_exists("session_start") ? 0 : 1);' 2>/dev/null; then
      echo "$c"; return 0
    fi
  done
  return 1
}
PHP="$(find_php)" || { echo "Не найден PHP 7.4+ (cli). Установите, например: apt install php-cli"; exit 1; }
echo "PHP: $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"

command -v nginx >/dev/null || { echo "nginx не найден — панель рассчитана на сервер с nginx"; exit 1; }
if ! command -v sudo >/dev/null; then
  echo "Устанавливаю sudo…"
  (apt-get install -y sudo || yum install -y sudo || dnf install -y sudo) >/dev/null
fi

# 2. Параметры (при обновлении сохраняются)
if [ -f "$ETC/config.php" ] && [ -f "$ETC/listen" ] && [ "${1:-}" != "--reset" ]; then
  echo "Найдена существующая установка: обновляю файлы, логин и пароль не меняю."
  LISTEN="$(cat "$ETC/listen")"
  NEWCFG=0
else
  DEF_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"; DEF_IP="${DEF_IP:-127.0.0.1}"
  read -r -p "Адрес, на котором откроется панель [$DEF_IP]: " BIND; BIND="${BIND:-$DEF_IP}"
  read -r -p "Порт [8890]: " PORT; PORT="${PORT:-8890}"
  read -r -p "Логин администратора [admin]: " PLOGIN; PLOGIN="${PLOGIN:-admin}"
  while :; do
    read -r -s -p "Пароль (не короче 10 символов): " PPASS; echo
    read -r -s -p "Пароль ещё раз: " PPASS2; echo
    [ "$PPASS" = "$PPASS2" ] && [ "${#PPASS}" -ge 10 ] && break
    echo "Пароли не совпали или пароль слишком короткий."
  done
  LISTEN="$BIND:$PORT"
  NEWCFG=1
fi

# 3. Пользователь и каталоги
id "$PUSER" >/dev/null 2>&1 || useradd --system --home-dir "$DATA" --shell /usr/sbin/nologin "$PUSER"
install -d -m 755 "$APP" "$LIB"
install -d -m 750 -o root -g "$PUSER" "$ETC"
install -d -m 700 -o "$PUSER" -g "$PUSER" "$DATA" "$DATA/sessions"
install -d -m 700 -o root -g root "$ETC/sites" "$ETC/ssl"
install -d -m 755 -o root -g root /var/lib/dewiar-sockets

rm -rf "$APP/public" "$APP/lib"
cp -r "$SRC/app/public" "$SRC/app/lib" "$APP/"
cp "$SRC/VERSION" "$APP/VERSION"
chown -R root:root "$APP"; chmod -R a+rX,go-w "$APP"

install -m 700 -o root -g root "$SRC/helper/helper.php" "$LIB/helper.php"
cat > /usr/local/sbin/dewiar-helper <<EOF
#!/bin/sh
exec "$PHP" -d disable_functions= -d open_basedir= "$LIB/helper.php" "\$@"
EOF
chown root:root /usr/local/sbin/dewiar-helper; chmod 700 /usr/local/sbin/dewiar-helper

if [ "$NEWCFG" = "1" ]; then
  PANEL_LOGIN="$PLOGIN" PANEL_PASS="$PPASS" PANEL_DATA="$DATA" "$PHP" -r '
    $c = ["login" => getenv("PANEL_LOGIN"), "pass_hash" => password_hash(getenv("PANEL_PASS"), PASSWORD_DEFAULT), "data_dir" => getenv("PANEL_DATA")];
    file_put_contents("'"$ETC"'/config.php", "<?php\nreturn " . var_export($c, true) . ";\n");'
  echo "$LISTEN" > "$ETC/listen"
fi
chown root:"$PUSER" "$ETC/config.php" "$ETC/listen"; chmod 640 "$ETC/config.php" "$ETC/listen"

# Команда dewiar-panel для терминала: обновление, версия, пароль
cat > /usr/local/sbin/dewiar-panel <<'CLI'
#!/bin/sh
REPO="dwr-git/dewiar-server-panel"
case "${1:-}" in
  update)   curl -fsSL "https://raw.githubusercontent.com/$REPO/main/get.sh" | bash ;;
  version)  cat /opt/dewiar-panel/VERSION ;;
  status)   systemctl status dewiar-panel --no-pager ;;
  restart)  systemctl restart dewiar-panel && echo "Панель перезапущена." ;;
  password) curl -fsSL "https://raw.githubusercontent.com/$REPO/main/get.sh" | bash -s -- --reset ;;
  *) echo "Dewiar Server Panel $(cat /opt/dewiar-panel/VERSION 2>/dev/null)"
     echo "  dewiar-panel update     обновить до последней версии"
     echo "  dewiar-panel version    показать установленную версию"
     echo "  dewiar-panel status     состояние службы панели"
     echo "  dewiar-panel restart    перезапустить панель"
     echo "  dewiar-panel password   сменить логин, пароль, адрес или порт" ;;
esac
CLI
chown root:root /usr/local/sbin/dewiar-panel; chmod 755 /usr/local/sbin/dewiar-panel

# 4. Право панели вызывать помощник (и только его)
TMP="$(mktemp)"
printf 'Defaults:%s !requiretty\n%s ALL=(root) NOPASSWD: /usr/local/sbin/dewiar-helper\n' "$PUSER" "$PUSER" > "$TMP"
visudo -cf "$TMP" >/dev/null
install -m 440 -o root -g root "$TMP" /etc/sudoers.d/dewiar-panel; rm -f "$TMP"

# 5. acme.sh — клиент Let's Encrypt с автопродлением
if [ ! -x /root/.acme.sh/acme.sh ]; then
  LE_MAIL=""
  if [ -t 0 ]; then read -r -p "E-mail для уведомлений Let's Encrypt: " LE_MAIL; fi
  echo "Устанавливаю acme.sh…"
  T="$(mktemp -d)"
  if curl -fsSL https://github.com/acmesh-official/acme.sh/archive/refs/heads/master.tar.gz | tar xz -C "$T" \
     && (cd "$T"/acme.sh-master && ./acme.sh --install --home /root/.acme.sh --accountemail "$LE_MAIL" >/dev/null); then
    /root/.acme.sh/acme.sh --home /root/.acme.sh --set-default-ca --server letsencrypt >/dev/null
    echo "acme.sh установлен, автопродление добавлено в cron."
  else
    echo "ВНИМАНИЕ: acme.sh установить не удалось (нужны curl, tar, cron и доступ к github.com)."
    echo "Панель заработает, но выпуск SSL будет недоступен, пока acme.sh не установлен."
  fi
  rm -rf "$T"
fi

# 6. Служба
cat > /etc/systemd/system/dewiar-panel.service <<EOF
[Unit]
Description=Dewiar Server Panel
After=network.target

[Service]
User=$PUSER
Group=$PUSER
Environment=PHP_CLI_SERVER_WORKERS=4
ExecStart=$PHP -d disable_functions= -d open_basedir= -d display_errors=0 -d log_errors=1 -S $LISTEN -t $APP/public $APP/public/router.php
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF
cat > /etc/systemd/system/dewiar-metrics.service <<EOF
[Unit]
Description=Dewiar Server Panel: сбор метрик

[Service]
Type=oneshot
User=$PUSER
ExecStart=$PHP -d disable_functions= -d open_basedir= $APP/lib/collect.php
EOF
cat > /etc/systemd/system/dewiar-metrics.timer <<EOF
[Unit]
Description=Dewiar Server Panel: сбор метрик раз в минуту

[Timer]
OnCalendar=minutely
AccuracySec=5s

[Install]
WantedBy=timers.target
EOF
cat > /etc/systemd/system/dewiar-backup.service <<EOF
[Unit]
Description=Dewiar Server Panel: резервная копия сайтов и баз

[Service]
Type=oneshot
Nice=10
IOSchedulingClass=idle
ExecStart=/usr/local/sbin/dewiar-helper backup-run
EOF
systemctl daemon-reload
systemctl enable --now dewiar-metrics.timer >/dev/null 2>&1
systemctl enable dewiar-panel >/dev/null 2>&1
systemctl restart dewiar-panel
sleep 1
if systemctl is-active --quiet dewiar-panel; then
  echo
  echo "Готово. Dewiar Server Panel $(cat "$APP/VERSION"): http://$LISTEN"
  echo "Обновление в будущем: команда dewiar-panel update или кнопка в разделе «Безопасность»."
  echo "Если включён файрвол, откройте порт только для своей локальной сети."
else
  echo "Служба не запустилась. Журнал: journalctl -u dewiar-panel -n 30"
  exit 1
fi
