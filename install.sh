#!/bin/sh
# Installs the static opmin binary from GitHub Releases.
#
#   curl -fsSL https://raw.githubusercontent.com/melentev-av/opmin/master/install.sh | sh
#   curl -fsSL .../install.sh | sh -s -- --dir=/usr/local/bin --version=1.2.3
#
# Checks the SHA-256 of the archive against sha256sum.txt of the release and the signature of sha256sum.txt
# against the opmin release keys below (with openssl; without it — the checksum only, with a warning).
# The keys are the same as resources/release-keys/ of the repository.

set -eu

REPO="melentev-av/opmin"
DIR="${OPMIN_INSTALL_DIR:-$HOME/.local/bin}"
VERSION="${OPMIN_VERSION:-}"
# Mirrors and tests: where release assets are downloaded from (<base>/v<version>/<file>).
BASE="${OPMIN_DOWNLOAD_BASE:-https://github.com/$REPO/releases/download}"

KEY_PRIMARY='-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEYRyWpEgbd7aRp/B/MjCLBK7BVu+C
ka0GOqdkKa9FhX+55BxyO85whYBRcD69IuQssP+oOsVKGC/6HKv8ppX7vA==
-----END PUBLIC KEY-----'
KEY_RESERVE='-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE8xz6oQ9Sj2L0qnTBtDDOEUSCLmUv
4omEh1V0dUBqOoHOGKhmV37wtfReVVW43OD9nouCrr5LnrEcYK0n9OH1rg==
-----END PUBLIC KEY-----'

say() { printf '%s\n' "$*" >&2; }
fail() { say "opmin install: $*"; exit 1; }

for arg in "$@"; do
  case "$arg" in
    --dir=*) DIR="${arg#--dir=}" ;;
    --version=*) VERSION="${arg#--version=}" ;;
    -h|--help)
      say "Usage: install.sh [--dir=<directory, default ~/.local/bin>] [--version=<x.y.z, default latest>]"
      exit 0 ;;
    *) fail "unknown argument $arg (see --help)" ;;
  esac
done
VERSION="${VERSION#v}"

case "$(uname -s)" in
  Linux) OS=linux ;;
  Darwin) OS=macos ;;
  *) fail "there is no opmin binary for $(uname -s): use the PHAR or the Docker image (README)" ;;
esac
case "$(uname -m)" in
  x86_64|amd64) ARCH=x86_64 ;;
  aarch64|arm64) if [ "$OS" = macos ]; then ARCH=arm64; else ARCH=aarch64; fi ;;
  *) fail "there is no opmin binary for $OS on $(uname -m): use the PHAR or the Docker image (README)" ;;
esac

if command -v curl >/dev/null 2>&1; then
  fetch() { curl -fsSL --retry 3 -o "$2" "$1"; }
  fetch_stdout() { curl -fsSL --retry 3 "$1"; }
elif command -v wget >/dev/null 2>&1; then
  fetch() { wget -q -O "$2" "$1"; }
  fetch_stdout() { wget -q -O - "$1"; }
else
  fail "curl or wget is required"
fi

if [ -z "$VERSION" ]; then
  # The latest release without the API (no rate limit): the tag in the redirect of releases/latest.
  if command -v curl >/dev/null 2>&1; then
    URL="$(curl -fsSLI -o /dev/null -w '%{url_effective}' "https://github.com/$REPO/releases/latest")"
  else
    URL="$(wget -q --max-redirect=5 -S -O /dev/null "https://github.com/$REPO/releases/latest" 2>&1 | sed -n 's/^ *[Ll]ocation: *//p' | tail -n 1)"
  fi
  VERSION="$(printf '%s' "$URL" | sed -n 's~.*/tag/v\{0,1\}\([^/[:space:]]*\).*~\1~p')"
  [ -n "$VERSION" ] || fail "cannot find the latest release of $REPO"
fi

ARCHIVE="opmin-$VERSION-$OS-$ARCH.tar.gz"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT INT TERM

say "Downloading opmin $VERSION for $OS-$ARCH..."
fetch "$BASE/v$VERSION/$ARCHIVE" "$TMP/$ARCHIVE" || fail "cannot download $ARCHIVE of release $VERSION"
fetch "$BASE/v$VERSION/sha256sum.txt" "$TMP/sha256sum.txt" || fail "cannot download sha256sum.txt of release $VERSION"

if command -v openssl >/dev/null 2>&1; then
  fetch "$BASE/v$VERSION/sha256sum.txt.sig" "$TMP/sha256sum.txt.sig" || fail "cannot download sha256sum.txt.sig of release $VERSION"
  printf '%s\n' "$KEY_PRIMARY" > "$TMP/primary.pem"
  printf '%s\n' "$KEY_RESERVE" > "$TMP/reserve.pem"
  if openssl dgst -sha256 -verify "$TMP/primary.pem" -signature "$TMP/sha256sum.txt.sig" "$TMP/sha256sum.txt" >/dev/null 2>&1 \
    || openssl dgst -sha256 -verify "$TMP/reserve.pem" -signature "$TMP/sha256sum.txt.sig" "$TMP/sha256sum.txt" >/dev/null 2>&1; then
    say "Signature of sha256sum.txt: OK"
  else
    fail "the signature of sha256sum.txt does not match the opmin release keys: nothing was installed"
  fi
else
  say "warning: openssl is not installed, the release signature is not checked (the checksum still is)"
fi

EXPECTED="$(awk -v f="$ARCHIVE" '{ n = $2; sub(/^\*/, "", n); if (n == f) print $1 }' "$TMP/sha256sum.txt")"
[ -n "$EXPECTED" ] || fail "$ARCHIVE is not listed in sha256sum.txt"
if command -v sha256sum >/dev/null 2>&1; then
  ACTUAL="$(sha256sum "$TMP/$ARCHIVE" | awk '{print $1}')"
elif command -v shasum >/dev/null 2>&1; then
  ACTUAL="$(shasum -a 256 "$TMP/$ARCHIVE" | awk '{print $1}')"
else
  fail "sha256sum or shasum is required to check the download"
fi
[ "$EXPECTED" = "$ACTUAL" ] || fail "checksum mismatch for $ARCHIVE: nothing was installed"

tar -xzf "$TMP/$ARCHIVE" -C "$TMP" opmin
mkdir -p "$DIR"
chmod 755 "$TMP/opmin"
mv -f "$TMP/opmin" "$DIR/opmin"
say "Installed: $DIR/opmin ($("$DIR/opmin" --version 2>/dev/null || echo "opmin $VERSION"))"

case ":$PATH:" in
  *":$DIR:"*) ;;
  *) say "Add $DIR to PATH, e.g.: echo 'export PATH=\"$DIR:\$PATH\"' >> ~/.profile" ;;
esac
say "Next: opmin init && opmin doctor"
