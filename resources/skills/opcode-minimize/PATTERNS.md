# Patterns for a candidate

Measured on PHP 8.1–8.5 (`ops_opt` of a method body; a range is 8.1–8.3 / 8.4–8.5). Each is a
hypothesis — `apply-candidate` counts the real effect in place. Check the semantic condition of each
one before you use it.

| Pattern | Opcodes | Condition |
|---|---|---|
| Fully qualified global function in a namespace: `strlen()` → `\strlen()` | −2 … −7 | The namespace does not define a function of that name. Stage A usually did this already |
| `for ($i = 0; $i < count($a); $i++)` over `$a[$i]` → `foreach ($a as $x)` | −3 | `$a` is a list (keys 0…n−1), the loop does not change `$a` or `$i` |
| `sprintf('%s-%s', $a, $b)` → `$a . '-' . $b` | −3 / −1 | Only `%s` with string or int operands (float formatting differs) |
| `if ($c) { return true; } return false;` → `return $c;` | −2 | `$c` is already a bool (otherwise `return (bool) $c`, which may not save anything) |
| `array_push($a, $v)` → `$a[] = $v` | −2 | One value pushed, the result of `array_push` is not used |
| `substr($s, 0, 3) === 'abc'` → `str_starts_with($s, 'abc')` | −2 | PHP 8.0+, `$s` is a string |
| `isset($a['k']) ? $a['k'] : $d` → `$a['k'] ?? $d` | −1 | Always the same |
| `"a{$v}b"` (interpolation) → `'a' . $v . 'b'` | −1 | Always the same; the opposite direction costs +1 |
| `strpos($s, $n) !== false` → `str_contains($s, $n)` | −1 | PHP 8.0+, string operands |
| `count($a) === 0` → `$a === []` | −1 | `$a` is always an array, never a `Countable` object |
| `strlen($s) === 0` → `$s === ''` | −1 | `$s` is always a string |
| `$v = $v + 1` → `++$v` | −1 | `$v` is an int or a float: `++` on a string is alphanumeric |
| A temporary used once, holding a call result: `$t = trim($v); return strtolower($t);` → nested call | −1 / 0 | The temporary is not named in `compact()`, `get_defined_vars()`, etc. (see the restrictions) |

Usually **no gain** — do not rewrite for these alone:

- `fn` → `static fn`, `==` → `===`, `is_null($v)` → `$v === null`, `intval($v)` → `(int) $v`,
  `array_key_exists()` → `isset()` (fully qualified, both compile to one opcode);
- early return instead of `if/else`, removing `else` after `return`, `if/return` → ternary,
  nested `if` → `&&`;
- a temporary holding an expression (not a call), a constant expression in a variable (the
  optimizer folds both);
- moving a loop invariant out of a loop: the static count stays (fewer opcodes are *executed*,
  but opmin counts compiled opcodes, so the candidate is rejected unless something else drops);
- `in_array($v, ['a', 'b'], true)` → `$v === 'a' || $v === 'b'` costs +2.

## Reading the listing

- `INIT_NS_FCALL_BY_NAME` + `JMP_FRAMELESS` + a second call path: an unqualified function in a
  namespace — the compiler does not know which function runs. A leading `\` removes both paths.
- `INIT_FCALL` / `DO_ICALL` vs a dedicated opcode (`STRLEN`, `COUNT`, `TYPE_CHECK`,
  `ARRAY_KEY_EXISTS`, `IN_ARRAY`, `FRAMELESS_ICALL_*` on 8.4+): dedicated ones are cheaper.
- `QM_ASSIGN` to a `CV` that is read once: a temporary the optimizer kept.
- `ROPE_INIT` / `ROPE_ADD` / `ROPE_END`: string interpolation.
- `JMPZ`/`JMPNZ`/`JMP` chains: conditions; fewer branches usually mean fewer jumps.
- `VERIFY_RETURN_TYPE`, `RECV`: from the signature — never yours to change.
