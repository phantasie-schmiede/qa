#!/usr/bin/env sh
#
# Build the local test images for all supported PHP versions.
#
# Usage:
#   sh .Build/vendor/psbits/qa/Resources/Testing/Scripts/buildTestImages.sh
#
# The images are tagged psbits/<extension>-test:<php-version> (<extension>
# from PSBITS_QA_EXTENSION, default: the repository directory name);
# docker-compose.yml selects the version via PHP_VERSION (default 8.4).
# USER_UID/USER_GID default to the current user so the bind-mounted
# repository keeps its ownership.
#
# The test containers talk to packagist and the composer mirrors over TLS. On
# hosts whose network routes TLS through a corporate (MITM) proxy the proxy's
# root CA must be trusted inside the image. This script therefore stages the
# host's additional CA certificates (everything beyond the standard bundle)
# into .Build/docker-build/build-ca, which the Dockerfile installs. Set
# EXTRA_CA_FILES (colon separated paths) to control the selection explicitly.
#
set -eu

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="${PSBITS_QA_REPO_ROOT:-$(pwd)}"
BUILD_CONTEXT="$REPO_ROOT/.Build/docker-build"
CA_STAGE_DIR="$BUILD_CONTEXT/build-ca"
EXTENSION="${PSBITS_QA_EXTENSION:-$(basename "$REPO_ROOT")}"

cd "$REPO_ROOT"

# ---------------------------------------------------------------------------
# Stage the host's additional CA certificates into the build context.
# ---------------------------------------------------------------------------
rm -rf "$CA_STAGE_DIR"
mkdir -p "$CA_STAGE_DIR"

stage_ca() {
    for ca in "$@"; do
        [ -f "$ca" ] || continue
        cp "$ca" "$CA_STAGE_DIR/"
    done
}

if [ -n "${EXTRA_CA_FILES:-}" ]; then
    OLD_IFS="$IFS"
    IFS=':'
    stage_ca $EXTRA_CA_FILES
    IFS="$OLD_IFS"
else
    # Debian convention: the standard Mozilla CA set lives in
    # /usr/share/ca-certificates/mozilla/. Any other subdirectory (e.g. a
    # corporate proxy CA under corp/) is an additional vendor CA, as are the
    # certificates registered via update-ca-certificates under /usr/local.
    for dir in /usr/share/ca-certificates/*/; do
        [ -d "$dir" ] || continue
        [ "$(basename "$dir")" = "mozilla" ] && continue
        stage_ca "$dir"*.crt "$dir"*.pem
    done
    stage_ca /usr/local/share/ca-certificates/*.crt /usr/local/share/ca-certificates/*.pem
fi

CA_COUNT="$(ls -1A "$CA_STAGE_DIR" | wc -l | tr -d ' ')"
echo "Staged $CA_COUNT CA certificate(s) into $(basename "$CA_STAGE_DIR")"

# The Dockerfile lives in the package; copy it into the staged build context
# so the image build stays self-contained under .Build/ (git-ignored).
cp "$SCRIPT_DIR/../Docker/Dockerfile" "$BUILD_CONTEXT/Dockerfile"

# ---------------------------------------------------------------------------
# Build one image per supported PHP version.
# ---------------------------------------------------------------------------
for php in 8.3 8.4 8.5; do
    echo "Building psbits/${EXTENSION}-test:$php ..."
    docker build \
        --build-arg php_version="$php" \
        --build-arg USER_UID="$(id -u)" \
        --build-arg USER_GID="$(id -g)" \
        --tag "psbits/${EXTENSION}-test:$php" \
        --file .Build/docker-build/Dockerfile \
        .Build/docker-build
done

echo "Done. Images:"
docker images --format '  {{.Repository}}:{{.Tag}}  ({{.Size}})' "psbits/${EXTENSION}-test"
