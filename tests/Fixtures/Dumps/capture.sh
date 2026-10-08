#!/bin/sh
# Re-captures the OPcache dumps of tests/Fixtures/Count/*.php on every PHP version of the matrix
# with the official php:<ver>-cli images. The dump is the same one opmin takes: both phases
# (opt_debug_level=0x30000) with the optimizer settings of OptimizerSettings::INI.
#
#   tests/Fixtures/Dumps/capture.sh            # all versions
#   tests/Fixtures/Dumps/capture.sh 8.4 8.5    # some versions
#   FILES=Basic.php tests/Fixtures/Dumps/capture.sh   # some files
#
# Paths in the dumps are /fixtures/<file>. The dump is piped out of the container (not written
# into the mounted directory): Docker Desktop file sharing sometimes shows a stale directory.
set -eu
cd "$(dirname "$0")/../Count"
for v in ${*:-8.1 8.2 8.3 8.4 8.5}; do
  mkdir -p "../Dumps/$v"
  for f in ${FILES:-[A-Z]*.php}; do
    case "$f:$v" in Readonly.php:8.1|Hooks.php:8.[123]) continue ;; esac
    docker run --rm -v "$PWD":/fixtures:ro -w /fixtures "php:$v-cli" php \
      -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.file_cache= -d opcache.file_cache_only=0 -d opcache.file_update_protection=0 \
      -d opcache.jit=disable -d opcache.optimization_level=0x7FFEBFFF -d opcache.opt_debug_level=0x30000 \
      -d xdebug.mode=off -r 'exit(opcache_compile_file($argv[1]) ? 0 : 1);' "/fixtures/$f" \
      2> "../Dumps/$v/${f%.php}.txt" >&2
    grep -q 'after optimizer' "../Dumps/$v/${f%.php}.txt" || { echo "no dump: $v $f" >&2; exit 1; }
    echo "$v $f: $(grep -c 'after optimizer' "../Dumps/$v/${f%.php}.txt") functions"
  done
  docker run --rm "php:$v-cli" php -r 'echo PHP_VERSION, "\n";' > "../Dumps/$v/VERSION"
done
