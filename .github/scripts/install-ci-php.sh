#!/usr/bin/env bash
set -euo pipefail

php_version="${PHP_VERSION:-8.4}"
extensions="${PHP_EXTENSIONS:-curl mbstring xml zip}"
install_composer="${INSTALL_COMPOSER:-false}"
export DEBIAN_FRONTEND=noninteractive

run_apt() {
  if [ "$(id -u)" -eq 0 ]; then
    apt-get "$@"
  else
    sudo apt-get "$@"
  fi
}

run_cmd() {
  if [ "$(id -u)" -eq 0 ]; then
    "$@"
  else
    sudo "$@"
  fi
}

add_php_repository() {
  . /etc/os-release

  case "${ID}" in
    debian)
      run_apt update
      run_apt install -y --no-install-recommends ca-certificates curl gnupg lsb-release
      run_cmd install -d -m 0755 /etc/apt/keyrings
      curl -fsSL https://packages.sury.org/php/apt.gpg \
        | run_cmd gpg --batch --yes --dearmor -o /etc/apt/keyrings/sury-php.gpg
      echo "deb [signed-by=/etc/apt/keyrings/sury-php.gpg] https://packages.sury.org/php/ ${VERSION_CODENAME} main" \
        | run_cmd tee /etc/apt/sources.list.d/sury-php.list >/dev/null
      ;;
    ubuntu)
      run_apt update
      run_apt install -y --no-install-recommends ca-certificates curl gnupg software-properties-common
      run_cmd add-apt-repository -y ppa:ondrej/php
      ;;
    *)
      echo "Unsupported CI image '${ID}'. Use a Debian or Ubuntu based runner." >&2
      exit 1
      ;;
  esac
}

php_matches_requested_version() {
  command -v php >/dev/null 2>&1 \
    && php -r "exit(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION === '${php_version}' ? 0 : 1);"
}

package_for_extension() {
  case "$1" in
    dom|libxml)
      printf 'php%s-xml\n' "${php_version}"
      ;;
    pdo_pgsql)
      printf 'php%s-pgsql\n' "${php_version}"
      ;;
    pdo_sqlite|sqlite3)
      printf 'php%s-sqlite3\n' "${php_version}"
      ;;
    exif|iconv|pcntl|pdo)
      return 0
      ;;
    *)
      printf 'php%s-%s\n' "${php_version}" "$1"
      ;;
  esac
}

if ! php_matches_requested_version; then
  add_php_repository
fi

packages=("php${php_version}-cli" "php${php_version}-common" ca-certificates curl git unzip)
for extension in ${extensions//,/ }; do
  package="$(package_for_extension "${extension}")"
  if [ -n "${package}" ]; then
    packages+=("${package}")
  fi
done

run_apt update
run_apt install -y --no-install-recommends "${packages[@]}"

if command -v "php${php_version}" >/dev/null 2>&1; then
  run_cmd update-alternatives --set php "/usr/bin/php${php_version}" >/dev/null || true
fi

php -v

if [ "${install_composer}" = "true" ]; then
  if ! command -v composer >/dev/null 2>&1; then
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    run_cmd php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
  fi

  composer --version
fi
