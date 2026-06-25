#!/usr/bin/env bash
# Provision the WordPress PHPUnit test scaffolding (Phase 5.0 per D11).
#
# Usage:
#   bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]
#
# Defaults: db-host=localhost, wp-version=latest, skip-database-creation=false.

set -euo pipefail

if [ $# -lt 3 ]; then
    echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]" >&2
    exit 1
fi

DB_NAME="$1"
DB_USER="$2"
DB_PASS="$3"
DB_HOST="${4:-localhost}"
WP_VERSION="${5:-latest}"
SKIP_DB="${6:-false}"

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress/}"

download() {
    if command -v curl >/dev/null 2>&1; then
        curl -sSL -o "$2" "$1"
    elif command -v wget >/dev/null 2>&1; then
        wget -nv -O "$2" "$1"
    else
        echo "Need curl or wget to download." >&2
        exit 1
    fi
}

resolve_wp_version() {
    if [ "$WP_VERSION" = "latest" ]; then
        local latest
        latest=$(curl -sSL https://api.wordpress.org/core/version-check/1.7/ | sed -E 's/.*"version":"([0-9.]+)".*/\1/')
        WP_VERSION="${latest:-6.9}"
    fi
}

install_wp() {
    if [ -d "$WP_CORE_DIR" ]; then
        return
    fi
    mkdir -p "$WP_CORE_DIR"
    download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" /tmp/wordpress.tar.gz
    tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
    download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/wp-content/db.php"
}

install_test_suite() {
    if [ ! -d "$WP_TESTS_DIR" ]; then
        mkdir -p "$WP_TESTS_DIR"
        svn co --quiet "https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
        svn co --quiet "https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/data/"     "$WP_TESTS_DIR/data"
    fi

    if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
        download https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php "$WP_TESTS_DIR/wp-tests-config.php"
        local abspath_pattern
        abspath_pattern="dirname( __FILE__ ) . '/src/'"
        sed -i.bak "s:$abspath_pattern:'$WP_CORE_DIR':" "$WP_TESTS_DIR/wp-tests-config.php"
        sed -i.bak "s/youremptytestdbnamehere/$DB_NAME/"      "$WP_TESTS_DIR/wp-tests-config.php"
        sed -i.bak "s/yourusernamehere/$DB_USER/"             "$WP_TESTS_DIR/wp-tests-config.php"
        sed -i.bak "s/yourpasswordhere/$DB_PASS/"             "$WP_TESTS_DIR/wp-tests-config.php"
        sed -i.bak "s|localhost|$DB_HOST|"                    "$WP_TESTS_DIR/wp-tests-config.php"
        rm -f "$WP_TESTS_DIR/wp-tests-config.php.bak"
    fi
}

install_db() {
    if [ "$SKIP_DB" = "true" ]; then
        return
    fi
    local protocol host port db_socket extra
    if [[ "$DB_HOST" == *":"* ]]; then
        host="${DB_HOST%%:*}"
        port="${DB_HOST##*:}"
        if [[ "$port" =~ ^[0-9]+$ ]]; then
            extra=" --host=$host --port=$port --protocol=tcp"
        else
            extra=" --socket=$port"
        fi
    else
        extra=" --host=$DB_HOST --protocol=tcp"
    fi
    mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS"$extra 2>/dev/null || true
}

resolve_wp_version
install_wp
install_test_suite
install_db

echo "WP test harness ready at ${WP_TESTS_DIR} (core: ${WP_CORE_DIR}, version: ${WP_VERSION})."
