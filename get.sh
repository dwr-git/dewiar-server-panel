#!/usr/bin/env bash
# Dewiar Server Panel — установка и обновление одной командой:
#   curl -fsSL https://raw.githubusercontent.com/dwr-git/dewiar-server-panel/main/get.sh | bash
set -euo pipefail

REPO="dwr-git/dewiar-server-panel"
SRC="/usr/local/src/dewiar-panel"

[ "$(id -u)" = "0" ] || { echo "Запустите от root (например: sudo -i, затем повторите команду)."; exit 1; }
for tool in curl tar; do
  command -v "$tool" >/dev/null || { echo "Не найдена программа $tool. Установите её: apt install $tool"; exit 1; }
done

VER="$(curl -fsSL "https://raw.githubusercontent.com/$REPO/main/VERSION" | tr -d '[:space:]')"
if ! printf '%s' "$VER" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
  echo "Не удалось узнать номер последней версии. Проверьте доступ сервера к github.com."; exit 1
fi
OLD="$(cat /opt/dewiar-panel/VERSION 2>/dev/null || true)"
if [ -n "$OLD" ]; then echo "Dewiar Server Panel: установлена $OLD, последняя $VER"; else echo "Dewiar Server Panel: устанавливаю версию $VER"; fi

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
if ! curl -fsSL "https://github.com/$REPO/archive/refs/tags/v$VER.tar.gz" -o "$TMP/src.tgz" 2>/dev/null; then
  curl -fsSL "https://github.com/$REPO/archive/refs/heads/main.tar.gz" -o "$TMP/src.tgz"
fi
mkdir "$TMP/src"
tar xzf "$TMP/src.tgz" --strip-components=1 -C "$TMP/src"
[ -f "$TMP/src/install.sh" ] || { echo "Загруженный архив повреждён, установка отменена."; exit 1; }
rm -rf "$SRC"; mkdir -p "$(dirname "$SRC")"; mv "$TMP/src" "$SRC"; chmod 700 "$SRC"

# Скрипт пришёл через конвейер, поэтому вопросы установщика читаем с терминала.
# При обновлении вопросов нет, и терминал не нужен (так панель обновляет сама себя).
if [ -t 0 ]; then
  bash "$SRC/install.sh" "$@"
elif [ -f /etc/dewiar-panel/config.php ] && [ "${1:-}" != "--reset" ]; then
  bash "$SRC/install.sh" "$@" </dev/null
else
  bash "$SRC/install.sh" "$@" </dev/tty
fi
