#!/usr/bin/env sh
# Keep test orchestration available while Composer replaces .Build/vendor.
# The caller supplies SCRIPT_DIR and REPO_ROOT.

prepare_test_runtime() {
    TEST_RUNTIME=''
    case "$SCRIPT_DIR" in
        "$REPO_ROOT"/.Build/qa-runtime/*/Testing/Scripts) return 0 ;;
    esac

    mkdir -p "$REPO_ROOT/.Build/qa-runtime"
    TEST_RUNTIME="$(mktemp -d "$REPO_ROOT/.Build/qa-runtime/run-XXXXXXXX")"
    if ! cp -R "$SCRIPT_DIR/.." "$TEST_RUNTIME/Testing"; then
        rm -rf "$TEST_RUNTIME"
        return 1
    fi
    SCRIPT_DIR="$TEST_RUNTIME/Testing/Scripts"
}

cleanup_test_runtime() {
    if [ -n "$TEST_RUNTIME" ]; then
        rm -rf "$TEST_RUNTIME"
    fi
}
