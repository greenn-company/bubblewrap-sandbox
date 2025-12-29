#!/usr/bin/env bash
set -euo pipefail

# Runs the PHPUnit suite across several PHP/Laravel version pairs using Docker.
# Customize COMPOSER_FLAGS/PHPUNIT_FLAGS env vars if you need to tweak installs or test runs.

COMBOS=(
  "8.3|12.*"
  "8.2|12.*"
  "8.2|11.*"
  "8.2|10.*"
  "8.1|10.*"
  "8.1|9.*"
  "8.0|9.*"
  "8.0|8.*"
  "7.4|8.*"
  "7.4|7.*"
  "7.4|6.*"
  "7.2|5.7.*"
  "7.1|5.6.*"
  "7.0|5.5.*"
  # PHP 5.6 can't run the current test suite (anonymous classes); enable at your own risk:
  # "5.6|5.4.*"
)

run_combo() {
  local php_version="$1"
  local laravel_constraint="$2"

  echo
  echo "=== PHP ${php_version} / illuminate/support:${laravel_constraint} ==="

  docker run --rm \
    -v "$PWD":/app \
    -w /app \
    -e COMPOSER_CACHE_DIR=/tmp/composer-cache \
    -e COMPOSER_ROOT_VERSION=dev-main \
    "php:${php_version}-cli" \
    bash -lc "
      set -euo pipefail
      release=\$( ( [ -f /etc/os-release ] && . /etc/os-release && echo \"\${VERSION_CODENAME:-}\" ) || true )
      export DEBIAN_FRONTEND=noninteractive
      # WARNING: The following apt settings relax signature verification to keep EOL Debian images (stretch/jessie) installable.
      # This is insecure and should be used only inside ephemeral CI containers. Do NOT mirror this in production.
      echo 'Acquire::Check-Valid-Until \"0\";' > /etc/apt/apt.conf.d/99archive
      echo 'Acquire::AllowInsecureRepositories \"true\";' >> /etc/apt/apt.conf.d/99archive
      echo 'Acquire::AllowDowngradeToInsecureRepositories \"true\";' >> /etc/apt/apt.conf.d/99archive
      echo 'Acquire::AllowWeaklyTrustedRepositories \"true\";' >> /etc/apt/apt.conf.d/99archive
      echo 'APT::Get::AllowUnauthenticated \"true\";' >> /etc/apt/apt.conf.d/99archive
      if ! apt-get update -qq; then
        # EOL fallback: switch to archive.debian.org when the default mirrors refuse to serve old releases.
        codename=\${release:-stretch}
        cat >/etc/apt/sources.list <<EOF
deb http://archive.debian.org/debian \${codename} main contrib non-free
deb http://archive.debian.org/debian-security \${codename}/updates main contrib non-free
EOF
        apt-get update -o Acquire::AllowInsecureRepositories=true -o Acquire::AllowDowngradeToInsecureRepositories=true -o Acquire::AllowWeaklyTrustedRepositories=true -qq || true
      fi
      apt-get install -yqq git unzip rsync >/dev/null
      command -v composer >/dev/null || curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer >/dev/null

      workdir=\$(mktemp -d)
      rsync -a --exclude vendor --exclude .git /app/ \"\$workdir/\"
      cd \"\$workdir\"

      composer config --no-interaction --global allow-plugins.kylekatarnls/update-helper true || true
      rm -f composer.lock
      composer require \"illuminate/support:${laravel_constraint}\" --with-all-dependencies --no-interaction --no-progress \${COMPOSER_FLAGS:-}
      vendor/bin/phpunit \${PHPUNIT_FLAGS:-}
    "
}

for combo in "${COMBOS[@]}"; do
  IFS='|' read -r php_version laravel_constraint <<<"$combo"
  run_combo "$php_version" "$laravel_constraint"
done
