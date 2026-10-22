#!/usr/bin/env sh
#
# Case table test for checkReleaseTag.sh, run by the qa CI.
#
# Usage:
#   sh Resources/Testing/Scripts/checkReleaseTagTest.sh

set -eu

SCRIPT="$(dirname "$0")/checkReleaseTag.sh"
FAILED=0

expect_ok() {
    # expect_ok <ref> <full_tag> <version>
    if ! output="$(sh "$SCRIPT" "$1")"; then
        echo "FAIL: $1 (expected to be accepted)" >&2
        FAILED=1
        return
    fi
    actual_tag="$(printf '%s\n' "$output" | sed -n 's/^full_tag=//p')"
    actual_version="$(printf '%s\n' "$output" | sed -n 's/^version=//p')"
    if [ "$actual_tag" != "$2" ] || [ "$actual_version" != "$3" ]; then
        echo "FAIL: $1 (expected full_tag=$2 version=$3, got full_tag=$actual_tag version=$actual_version)" >&2
        FAILED=1
    fi
}

expect_reject() {
    # expect_reject <ref>
    if output="$(sh "$SCRIPT" "$1" 2>/dev/null)"; then
        echo "FAIL: $1 (expected to be rejected, output: $output)" >&2
        FAILED=1
    fi
}

expect_ok 'refs/tags/0.1.0' '0.1.0' '0.1.0'
expect_ok 'refs/tags/0.1.0-alpha' '0.1.0-alpha' '0.1.0'
expect_ok 'refs/tags/1.2.3-rc.1' '1.2.3-rc.1' '1.2.3'
expect_ok 'refs/tags/2.0.0-beta' '2.0.0-beta' '2.0.0'
expect_ok 'refs/tags/10.20.30-alpha1' '10.20.30-alpha1' '10.20.30'

expect_reject 'refs/tags/v1.0.0'
expect_reject 'refs/tags/1.0'
expect_reject 'refs/tags/1.0.0-'
expect_reject 'refs/heads/main'
expect_reject ''

if [ "$FAILED" -ne 0 ]; then
    echo 'checkReleaseTag test cases failed.' >&2
    exit 1
fi

echo 'checkReleaseTag: all test cases passed.'
