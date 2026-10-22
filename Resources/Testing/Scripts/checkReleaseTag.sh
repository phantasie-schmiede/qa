#!/usr/bin/env sh
#
# Validate a release tag reference and derive the extension version and
# the full tag name.
#
# Usage:
#   sh Resources/Testing/Scripts/checkReleaseTag.sh <ref>
#
#   <ref>  The git reference being processed, e.g. refs/tags/0.1.0-alpha.
#
# On success the script prints two lines in GitHub Actions environment
# file format (safe to append to $GITHUB_ENV) and exits with status 0:
#
#   full_tag=0.1.0-alpha
#   version=0.1.0
#
# The version published to TER and compared against ext_emconf.php is
# the semver base without the pre-release suffix. The suffix (for
# example -alpha or -rc.1) only makes the tag a valid composer
# pre-release for Packagist consumers. Exits with status 1 for
# references that do not match the pattern.
#
# The script is POSIX sh so it runs on any runner or local system
# without a bash dependency.

set -eu

fail() {
    echo "Not a semver tag (optionally with a pre-release suffix): $1" >&2
    exit 1
}

is_digits() {
    # is_digits <string>
    case "$1" in
        '' | *[!0-9]*)
            return 1
            ;;
    esac
}

ref="${1:-}"

case "$ref" in
    refs/tags/*)
        tag="${ref#refs/tags/}"
        ;;
    *)
        fail "$ref"
        ;;
esac

base="${tag%%-*}"

# The version base is three dot-separated numeric parts.
set -f
IFS='.'
# shellcheck disable=SC2086  # intentional: split the version base on dots
set -- $base
set +f
IFS=' '

case "$base" in
    *. )
        fail "$ref"
        ;;
esac

if [ "$#" -ne 3 ] || ! is_digits "$1" || ! is_digits "$2" || ! is_digits "$3"; then
    fail "$ref"
fi

# An optional pre-release suffix: a dash followed by one or more
# characters from [0-9A-Za-z.-].
if [ "$tag" != "$base" ]; then
    suffix="${tag#"$base"}"
    case "$suffix" in
        -*)
            identifier="${suffix#-}"
            ;;
        *)
            fail "$ref"
            ;;
    esac
    [ -n "$identifier" ] || fail "$ref"
    leftover="$(printf '%s' "$identifier" | tr -d '0-9A-Za-z.-')"
    [ -z "$leftover" ] || fail "$ref"
fi

echo "full_tag=${tag}"
echo "version=${base}"
