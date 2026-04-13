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
#   Build/Scripts/runTests.sh -x               # Enable Xdebug
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# Defaults
PHP_VERSION="8.2"
TEST_SUITE="unit"
EXTRA_ARGS=""
XDEBUG=""
CI=${CI:-false}

# Image base — matches TYPO3 Core CI images
IMAGE_PREFIX="ghcr.io/typo3/core-testing-php"
IMAGE_TAG="1.15"

usage() {
    cat <<EOF
Usage: $(basename "$0") [options] [-- phpunit-args]

Options:
    -s <suite>    Test suite: unit (default), functional, phpstan, cgl, lint
    -p <version>  PHP version: 8.2 (default), 8.3, 8.4
    -x            Enable Xdebug
    -h            Show this help

Examples:
    $(basename "$0")                           Run unit tests
    $(basename "$0") -s unit -p 8.3            Run unit tests with PHP 8.3
    $(basename "$0") -s phpstan                Run PHPStan
    $(basename "$0") -- --filter BudgetService Run specific test
EOF
    exit 0
}

while getopts "s:p:xh" opt; do
    case ${opt} in
        s) TEST_SUITE="${OPTARG}" ;;
        p) PHP_VERSION="${OPTARG}" ;;
        x) XDEBUG="-e XDEBUG_MODE=debug -e XDEBUG_CONFIG=client_host=host.docker.internal" ;;
        h) usage ;;
        *) usage ;;
    esac
done
shift $((OPTIND - 1))
EXTRA_ARGS="$*"

PHP_IMAGE="${IMAGE_PREFIX}$(echo "${PHP_VERSION}" | tr -d '.'):${IMAGE_TAG}"

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
        echo "Running functional tests with PHP ${PHP_VERSION} (SQLite)..."
        docker run --rm \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            -e typo3DatabaseDriver=pdo_sqlite \
            ${XDEBUG} \
            "${PHP_IMAGE}" \
            .Build/vendor/bin/phpunit -c Build/phpunit/FunctionalTests.xml ${EXTRA_ARGS}
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
