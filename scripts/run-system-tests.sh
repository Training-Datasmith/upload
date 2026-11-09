#!/usr/bin/env bash
# Digest-pinned PHP 8.2, checksum-verified Composer, composer update + audit, pin check, Codeception unit suite.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

USE_SUDO_DOCKER="${USE_SUDO_DOCKER:-0}"
DOCKER=(docker)
if [[ "$USE_SUDO_DOCKER" == "1" ]]; then
  DOCKER=(sudo docker)
fi

PHP_IMAGE='php:8.2-cli-bookworm@sha256:ce09ca1d4b532edf9b0347b44a1968a80d9175addb581c450ba0c9a43fa0c5ea'
COMPOSER_URL='https://getcomposer.org/download/2.11.1/composer.phar'
COMPOSER_SHA256='6f1b891e1d4927d62740276c540fb490e47689e19e102c49e8da849794882f8c'
PCOV_TGZ_URL='https://pecl.php.net/get/pcov-1.0.12.tgz'
PCOV_SHA256='23255c8c9335a9636ccb743f5302436a97a582a0bbde9869485be911bbc15da8'

log() {
  printf '%s\n' "$*" >&2
}

strip_ansi() {
  sed 's/\x1b\[[0-9;]*[a-zA-Z]//g; s/\x1b\][^\x07]*\x07//g'
}

output_has_blocked_messages() {
  local stripped
  stripped="$(printf '%s\n' "$1" | strip_ansi)"
  if printf '%s\n' "$stripped" | grep -q 'Warning:'; then
    return 0
  fi
  if printf '%s\n' "$stripped" | grep -qE '(^| )Deprecated:|: Deprecated:'; then
    return 0
  fi
  if printf '%s\n' "$stripped" | grep -qE '^Notice:'; then
    return 0
  fi
  if printf '%s\n' "$stripped" | grep -qE '^(PHP )?Warning:'; then
    return 0
  fi
  if printf '%s\n' "$stripped" | grep -qE '^(PHP )?Deprecated:'; then
    return 0
  fi
  if printf '%s\n' "$stripped" | grep -qE '^(PHP )?Notice:'; then
    return 0
  fi
  return 1
}

validate_footer() {
  local stripped
  stripped="$(printf '%s\n' "$1" | strip_ansi)"
  if printf '%s\n' "$stripped" | grep -qE '^OK \([0-9]+ tests?, [0-9]+ assertions?\)$'; then
    return 0
  fi
  if printf '%s\n' "$stripped" | grep -qFx 'No tests executed!'; then
    return 0
  fi
  return 1
}

extract_footer_line() {
  local stripped
  stripped="$(printf '%s\n' "$1" | strip_ansi)"
  if line="$(printf '%s\n' "$stripped" | grep -E '^OK \([0-9]+ tests?, [0-9]+ assertions?\)$' | tail -n 1)"; then
    if [[ -n "$line" ]]; then
      printf '%s\n' "$line"
      return 0
    fi
  fi
  if line="$(printf '%s\n' "$stripped" | grep -Fx 'No tests executed!' | tail -n 1)"; then
    if [[ -n "$line" ]]; then
      printf '%s\n' "$line"
      return 0
    fi
  fi
  return 1
}

log "Pulling digest-pinned image..."
"${DOCKER[@]}" pull "$PHP_IMAGE"

read -r -d '' INNER <<EOF || true
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
export COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_NO_INTERACTION=1
export COMPOSER_ROOT_VERSION=2.0.0

apt-get update
apt-get install -y --no-install-recommends git unzip ca-certificates curl \$PHPIZE_DEPS libzip-dev
rm -rf /var/lib/apt/lists/*
docker-php-ext-install zip

cd /tmp
curl -fsSL -o composer.phar "${COMPOSER_URL}"
echo '${COMPOSER_SHA256}  composer.phar' | sha256sum -c -
install -m 0755 composer.phar /usr/local/bin/composer
rm -f composer.phar
composer --version

curl -fsSL -o /tmp/pcov.tgz "${PCOV_TGZ_URL}"
echo '${PCOV_SHA256}  /tmp/pcov.tgz' | sha256sum -c -
pecl install /tmp/pcov.tgz
docker-php-ext-enable pcov
rm -f /tmp/pcov.tgz
echo 'pcov.enabled=1' > /usr/local/etc/php/conf.d/pcov.ini

git config --global --add safe.directory /workspace
cd /workspace

composer update --no-interaction --no-progress

composer audit --no-interaction

php scripts/check-cluster-pins.php --demo-wrong-sha

vendor/bin/codecept run unit --no-ansi --coverage-xml
EOF

set +e
OUTPUT="$("${DOCKER[@]}" run --rm \
  -v "$ROOT:/workspace" \
  -w /workspace \
  "$PHP_IMAGE" \
  bash -lc "$INNER" 2>&1)"
RUN_EXIT=$?
set -e

printf '%s\n' "$OUTPUT"

if printf '%s\n' "$OUTPUT" | grep -q 'Your requirements could not be resolved\|because they are affected by security advisories'; then
  log "FAIL: composer update/audit did not complete cleanly"
  exit 2
fi

if output_has_blocked_messages "$OUTPUT"; then
  log "FAIL: output contains Warning/Deprecated/Notice"
  exit 1
fi

if [[ "$RUN_EXIT" -ne 0 ]]; then
  log "FAIL: container exit code $RUN_EXIT"
  exit "$RUN_EXIT"
fi

if ! validate_footer "$OUTPUT"; then
  log "FAIL: missing strict footer (OK line or No tests executed!)"
  exit 1
fi

footer="$(extract_footer_line "$OUTPUT" || true)"
if [[ -n "${footer:-}" ]]; then
  log "Strict success footer: ${footer}"
fi

log "System test passed."
exit 0
