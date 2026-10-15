#!/bin/sh
# measure.sh <out-dir> <jobs-dir>: every job file (<rule>.<function>.<input>-<a|b>.php: one variant)
# in a process of its own under callgrind, without JIT and with the tracing JIT, four at a time →
# <out-dir>/<off|tracing>/<job file name>. callgrind dumps its counters at every usleep() — the marker
# between the warm-up, N1 and N2 segments. A process and a file of its own, because the instruction
# count of a case depends on what else the process loaded and ran (string and function tables,
# runtime caches, the JIT state): measured together, the same case differed by up to 2×.
# Runs where `php` and `valgrind` are on PATH: in the image of bench/cost/Dockerfile or on Linux.
set -eu
out="$1"; jobs="$2"
for mode in off tracing; do
  for f in "$jobs"/*.php; do echo "$mode $f"; done
done | xargs -P 4 -n 2 sh -c '
  out="$0"; mode="$1"; file="$2"
  if [ "$mode" = off ]; then jit="-d opcache.jit=disable"; else jit="-d opcache.jit=tracing -d opcache.jit_buffer_size=64M"; fi
  dir="$out/$mode/$(basename "$file" .php)"; rm -rf "$dir"; mkdir -p "$dir"
  # Without address randomization: instruction counts of libc string functions depend on alignment,
  # so with ASLR the same file differed by up to 20 instructions per call from run to run.
  norand=""; setarch "$(uname -m)" -R true 2>/dev/null && norand="setarch $(uname -m) -R"
  $norand valgrind --tool=callgrind --smc-check=all-non-file --dump-before=usleep --callgrind-out-file="$dir/cg.%p" \
    php -d opcache.enable_cli=1 -d zend.assertions=-1 $jit "$file" > "$dir/log.txt" 2>&1
' "$out"
for mode in off tracing; do echo "$mode: $(ls -d "$out/$mode"/* | wc -l | tr -d ' ') processes"; done
