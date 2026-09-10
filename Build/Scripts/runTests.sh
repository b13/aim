#!/usr/bin/env bash

#
# AiM test runner.
#
# Runs unit tests, functional tests, phpstan, and cgl inside a Docker container
# using the same PHP images as the TYPO3 Core CI.
#
# Usage:
#   Build/Scripts/runTests.sh                  # Run unit tests (default)
#   Build/Scripts/runTests.sh -s unit          # Run unit tests
#   Build/Scripts/runTests.sh -s functional    # Run functional tests
#   Build/Scripts/runTests.sh -s phpstan       # Run static analysis
#   Build/Scripts/runTests.sh -s cgl           # Run coding standards check
#   Build/Scripts/runTests.sh -p 8.3           # Use PHP 8.3
#   Build/Scripts/runTests.sh -s functional -d mariadb   # Functional on MariaDB
#   Build/Scripts/runTests.sh -x               # Enable Xdebug
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# Defaults
PHP_VERSION="8.2"
TEST_SUITE="unit"
DBMS="sqlite"
EXTRA_ARGS=""
XDEBUG=""
CI=${CI:-false}

# SQLite accepts a double-quoted unknown column as a string literal instead of
# erroring, so a query against a column that does not exist yet passes there and
# fails on every other DBMS. Functional tests therefore need to be runnable
# against MariaDB too.
MARIADB_IMAGE="mariadb:10.11"
DB_CONTAINER="aim-test-mariadb-$$"
DB_NETWORK="aim-test-net-$$"
DB_PASSWORD="funcp"

# Image base, matches TYPO3 Core CI images.
IMAGE_PREFIX="ghcr.io/typo3/core-testing-php"

# The tag streams of these images are per PHP version and NOT in lockstep: as of
# 2026-08-28 php82 is on 1.15.x, php83 on 1.16.x and php84 still on 1.8.x. A
# single shared tag therefore resolves to a non-existent image for some versions,
# and that only fails where the image is not already cached locally. TYPO3 Core
# has the same lookup in its own runTests.sh (getPhpImageVersion).
getPhpImageVersion() {
    case ${1} in
        8.2) echo -n "1.15" ;;
        8.3) echo -n "1.16" ;;
        8.4) echo -n "1.8" ;;
        *)
            echo "Unsupported PHP version: ${1} (expected 8.2, 8.3 or 8.4)" >&2
            exit 1
            ;;
    esac
}

usage() {
    cat <<EOF
Usage: $(basename "$0") [options] [-- phpunit-args]

Options:
    -s <suite>    Test suite: unit (default), functional, phpstan, cgl, lint
    -p <version>  PHP version: 8.2 (default), 8.3, 8.4
    -d <dbms>     Functional DBMS: sqlite (default), mariadb
    -x            Enable Xdebug
    -h            Show this help

Examples:
    $(basename "$0")                           Run unit tests
    $(basename "$0") -s unit -p 8.3            Run unit tests with PHP 8.3
    $(basename "$0") -s phpstan                Run PHPStan
    $(basename "$0") -s functional -d mariadb  Functional tests on MariaDB
    $(basename "$0") -- --filter BudgetService Run specific test
EOF
    exit 0
}

while getopts "s:p:d:xh" opt; do
    case ${opt} in
        s) TEST_SUITE="${OPTARG}" ;;
        p) PHP_VERSION="${OPTARG}" ;;
        d) DBMS="${OPTARG}" ;;
        x) XDEBUG="-e XDEBUG_MODE=debug -e XDEBUG_CONFIG=client_host=host.docker.internal" ;;
        h) usage ;;
        *) usage ;;
    esac
done
shift $((OPTIND - 1))
EXTRA_ARGS="$*"

PHP_IMAGE="${IMAGE_PREFIX}$(echo "${PHP_VERSION}" | tr -d '.'):$(getPhpImageVersion "${PHP_VERSION}")"

# Ensure .Build/vendor exists (composer install)
if [ ! -d "${ROOT_DIR}/.Build/vendor" ]; then
    echo "Running composer install..."
    docker run --rm \
        -v "${ROOT_DIR}:/app" \
        -w /app \
        "${PHP_IMAGE}" \
        composer install --no-progress --no-interaction 2>&1
fi

case ${TEST_SUITE} in
    unit)
        echo "Running unit tests with PHP ${PHP_VERSION}..."
        docker run --rm \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            ${XDEBUG} \
            "${PHP_IMAGE}" \
            .Build/vendor/bin/phpunit -c Build/phpunit/UnitTests.xml ${EXTRA_ARGS}
        ;;
    functional)
        case ${DBMS} in
            sqlite)
                echo "Running functional tests with PHP ${PHP_VERSION} (SQLite)..."
                docker run --rm \
                    -v "${ROOT_DIR}:/app" \
                    -w /app \
                    -e typo3DatabaseDriver=pdo_sqlite \
                    ${XDEBUG} \
                    "${PHP_IMAGE}" \
                    .Build/vendor/bin/phpunit -c Build/phpunit/FunctionalTests.xml ${EXTRA_ARGS}
                ;;
            mariadb)
                echo "Running functional tests with PHP ${PHP_VERSION} (MariaDB)..."
                # Preserve the phpunit exit code: the cleanup commands would
                # otherwise become the script's status and turn a red run green.
                cleanupDb() {
                    local code=$?
                    docker rm -f "${DB_CONTAINER}" >/dev/null 2>&1 || true
                    docker network rm "${DB_NETWORK}" >/dev/null 2>&1 || true
                    exit "${code}"
                }
                trap cleanupDb EXIT
                docker network create "${DB_NETWORK}" >/dev/null
                docker run --rm --name "${DB_CONTAINER}" --network "${DB_NETWORK}" -d \
                    -e MARIADB_ROOT_PASSWORD="${DB_PASSWORD}" \
                    "${MARIADB_IMAGE}" \
                    --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci >/dev/null
                # Probed over TCP: the entrypoint's temporary init server runs
                # with --skip-networking and would answer a socket ping before
                # the real server is up, which drops the connections the first
                # tests make.
                echo -n "Waiting for MariaDB"
                DB_READY=""
                for _ in $(seq 1 60); do
                    if docker exec "${DB_CONTAINER}" mariadb-admin ping \
                        --protocol=TCP -h 127.0.0.1 -uroot -p"${DB_PASSWORD}" >/dev/null 2>&1; then
                        DB_READY="1"
                        break
                    fi
                    echo -n "."
                    sleep 1
                done
                echo ""
                if [ -z "${DB_READY}" ]; then
                    echo "MariaDB was not reachable over TCP within 60 seconds"
                    docker logs "${DB_CONTAINER}"
                    exit 1
                fi
                docker run --rm \
                    -v "${ROOT_DIR}:/app" \
                    -w /app \
                    --network "${DB_NETWORK}" \
                    -e typo3DatabaseDriver=mysqli \
                    -e typo3DatabaseHost="${DB_CONTAINER}" \
                    -e typo3DatabaseName=func_test \
                    -e typo3DatabaseUsername=root \
                    -e typo3DatabasePassword="${DB_PASSWORD}" \
                    ${XDEBUG} \
                    "${PHP_IMAGE}" \
                    .Build/vendor/bin/phpunit -c Build/phpunit/FunctionalTests.xml ${EXTRA_ARGS}
                ;;
            *)
                echo "Unknown DBMS: ${DBMS} (expected sqlite or mariadb)"
                exit 1
                ;;
        esac
        ;;
    phpstan)
        echo "Running PHPStan with PHP ${PHP_VERSION}..."
        docker run --rm \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            "${PHP_IMAGE}" \
            .Build/vendor/bin/phpstan analyse -c phpstan.neon --no-progress
        ;;
    cgl)
        echo "Running coding standards check..."
        docker run --rm \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            "${PHP_IMAGE}" \
            .Build/vendor/bin/php-cs-fixer fix --dry-run --diff
        ;;
    lint)
        echo "Linting PHP files..."
        docker run --rm \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            "${PHP_IMAGE}" \
            bash -c 'find Classes Tests -name "*.php" -print0 | xargs -0 -n1 php -l > /dev/null'
        ;;
    *)
        echo "Unknown suite: ${TEST_SUITE}"
        usage
        ;;
esac

EXIT_CODE=$?
echo ""
if [ ${EXIT_CODE} -eq 0 ]; then
    echo "✓ ${TEST_SUITE} passed"
else
    echo "✗ ${TEST_SUITE} failed (exit ${EXIT_CODE})"
fi
exit ${EXIT_CODE}
