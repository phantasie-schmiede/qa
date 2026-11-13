#!/usr/bin/env sh
# Read-only preparation checks shared by the matrix wrappers and single-leg runner.
# The caller supplies SCRIPT_DIR and REPO_ROOT; console.sh is already loaded.

local_preflight() {
    PREFLIGHT_FAILED=0

    for tool in php composer; do
        if ! command -v "$tool" >/dev/null 2>&1; then
            echo "Missing command: $tool (install it and add it to PATH)." >&2
            PREFLIGHT_FAILED=1
        fi
    done

    if [ "$PREFLIGHT_FAILED" -ne 0 ]; then
        echo "Test preparation failed; no test run started." >&2

        return 1
    fi

    php "$SCRIPT_DIR/checkTestEnvironment.php" "$REPO_ROOT" "$1"
}

docker_preflight() {
    if ! command -v docker >/dev/null 2>&1; then
        echo "Test preparation failed: Docker is missing. Install Docker and Docker Compose; no container started." >&2

        return 1
    fi

    if ! docker compose version >/dev/null 2>&1; then
        echo "Test preparation failed: Docker Compose is unavailable. Install the Docker Compose plugin; no container started." >&2

        return 1
    fi

    if ! docker info >/dev/null 2>&1; then
        echo "Test preparation failed: Docker daemon is unreachable. Start Docker and check access to the selected Docker context; no container started." >&2

        return 1
    fi

    TEST_IMAGE="psbits/${PSBITS_QA_EXTENSION}-test:${PHP_VERSION:-8.4}"

    if ! docker image inspect "$TEST_IMAGE" >/dev/null 2>&1; then
        echo "Test preparation failed: missing image $TEST_IMAGE; no container started." >&2
        echo "Preparation: composer docker:images" >&2
        echo "If docker:images is missing, run .Build/bin/qa sync, or directly:" >&2
        echo "  sh .Build/vendor/psbits/qa/Resources/Testing/Scripts/buildTestImages.sh" >&2
        echo "Select another image with PHP_VERSION=8.3 composer test:docker (default: 8.4)." >&2

        return 1
    fi

    echo "Preparation: using $TEST_IMAGE. Rebuild test images with composer docker:images after Dockerfile or CA changes."
}
