# Harness protocol

The harness (`harness/`, namespace `Opmin\Harness`) is the part of the differential tester that runs under the
project's PHP (`php.binary`, 8.1+). It builds inputs, calls one version of a function and describes everything
observable. Everything else — what to generate, whether two results are equivalent — happens in the orchestrator.

Requirements (brief, «Две версии PHP»): PHP 8.1 syntax and standard library, no Composer dependencies, no
autoloader of its own (`harness/bootstrap.php` requires the files), a few hundred lines per file at most. CI checks
`php -l` on 8.1 and Psalm with `phpVersion="8.1"` (`composer psalm:harness`). In the PHAR the directory is copied
as is (`directories-bin`: never compacted or scoped).

## One version per process

A worker loads **one version** of the code: the original or the candidate. Two workers — one per version — get
the same inputs, and the orchestrator compares their results. The brief proposed a copy of the original renamed into
`__opmin_orig\…` in the same process; that changes behavior the tester must see unchanged: `static::class`,
`__CLASS__`, `__NAMESPACE__`, class names in messages (`TypeError`), `instanceof` and type declarations of other
project classes that expect the original class. Two processes keep both versions under their real names.

The file of a version is served under its real path by a `file://` stream wrapper during `load`
(`FileOverride`), so `__FILE__`, `__DIR__`, `include_once` and Composer's `files` autoload see the original
location while the content comes from the version's copy.

## Transport

```
php -d opcache.enable_cli=0 -d memory_limit=256M -d display_errors=0 harness/worker.php <token>
```

- One JSON request per line on **stdin**, one response per line on **stdout**, prefixed with the token and a space:
  `<token> {"ok":true,...}`. Lines without the token are output of the analyzed code that escaped capture
  (`fwrite(STDOUT)`) — not responses. Responses are written to a duplicate of stdout, so `fclose(STDOUT)` in user
  code does not cut the worker off.
- The first line after start is `{"ok": true, "ready": true, "php": "8.1.34", "pid": 123}`.
- Every response has `ok`; `ok: false` comes with `error` (a harness failure, not a result of the analyzed code).
- `exit()` or a fatal error (also out of memory) during a request: the shutdown handler writes the response
  (`status: "exited"` / `"fatal"`), then the process ends. A crash (segfault) or a timeout gives no response —
  the orchestrator kills and restarts the worker and treats it as a result too.

## Commands

### `ping`, `shutdown`

`{"cmd": "ping"}` → `{"ok": true, "php": "8.5.0", "pid": 123}`. `{"cmd": "shutdown"}` → `{"ok": true}`, then exit.

### `load`

```json
{"cmd": "load",
 "autoload": "/project/vendor/autoload.php",
 "files": ["/project/src/Foo.php", "/tmp/opmin/closure-wrappers.php"],
 "overrides": {"/project/src/Foo.php": "/tmp/opmin/v2/Foo.php"},
 "fakes": {"namespaces": ["App\\Service"], "time": 1700000000.25, "seed": 42},
 "coverage": "probes"}
```

1. `overrides` registers the stream wrapper: reading a key path returns the content of its value.
2. `autoload` (nullable) and `files` are `require_once`d, output is captured and returned.
3. The wrapper is removed: later includes are native.
4. `fakes` declares `time()`, `microtime()`, `hrtime()`, `date()`, `gmdate()`, `mktime()`, `strtotime()`,
   `random_int()`, `random_bytes()`, `uniqid()`, `lcg_value()`, `sleep()`, `usleep()`… in each namespace unless
   the namespace declares them itself. They return values of a fixed clock (`time`) and of `mt_rand()` seeded with
   `seed`; `sleep()` advances the fake clock. Before every call the clock is reset, `mt_srand(seed)` is called
   (it also fixes `rand`, `shuffle`, `array_rand`, `str_shuffle`), Carbon gets `setTestNow()` and Symfony Clock a
   `MockClock` when they are loaded. Fully qualified `\time()` is not faked: the determinism check sees it.
5. `coverage`: `probes` (default), `xdebug` or `pcov` — the driver for `lines` in call results.

Response: `{"ok": true, "php": "8.1.34", "output": <string>, "drivers": ["probes", "pcov"], "fork": true}` —
`fork`: the worker can run calls in forked children (pcntl, posix).

### `describe`

`{"cmd": "describe", "target": <target>}` → `{"ok": true, "function": {...}}`: `name`, `params` (each `name`,
`type`, `optional`, `variadic`, `by_ref`, `promoted`, `default` as a value description), `return`, `doc` (raw
docblock), `file`, `line`, `end_line`; methods also `static`, `visibility`, `class`; closures `uses` (parameters
of the wrapper). A type is `null` (none), `{"name": "int", "builtin": true, "nullable": false}`,
`{"union": [...]}` or `{"intersection": [...]}`.

### `class`

`{"cmd": "class", "name": "App\\Money"}` → `{"ok": true, "class": {...}}`: `exists`, `kind`
(`class|abstract|interface|trait|enum`), `final`, `internal`, `instantiable`, `doc`, `parents` (classes and
interfaces), `constructor` (signature + `visibility`), `props` (instance properties: `name`, `class`, `type`,
`readonly`, `promoted`, `doc`), `methods` (signatures + `abstract`, `static`), `cases` (enums). Used to generate
recipes of objects and mocks.

### `call`

```json
{"cmd": "call",
 "target": {"kind": "method", "class": "App\\Cart", "name": "total"},
 "input": {"args": [<recipe>...], "this": <recipe>|null, "uses": {"name": <recipe>}, "strict": false},
 "errors": "record",
 "repeat": 1}
```

- Targets: `{"kind": "function", "name"}`; `{"kind": "method", "class", "name"}` — static, instance (needs
  `this`) or the constructor (the result is the new object); non-public ones through `Closure::bind` to the
  declaring class; `{"kind": "closure", "wrapper", "scope"}` — `wrapper` is a function generated by the
  orchestrator that returns the closure (its parameters are the `use` variables), `scope` the class to bind to;
  `{"kind": "hook", "class", "property", "hook": "get|set"}` (PHP 8.4).
- `strict`: the call site is compiled with `strict_types=1` (the caller decides coercion of arguments).
- `errors`: `record` — warnings, notices and deprecations are recorded and execution continues; `throw` — they
  are thrown as `ErrorException`, as Laravel's `HandleExceptions` and Symfony's `ErrorHandler` do. `@`-silenced
  errors are recorded with `suppressed: true` and never thrown.
- `repeat`: call several times in a row (functions with `static` variables); arguments are rebuilt for every call,
  `this` is kept.
- `isolate: "fork"` with `timeout_ms`: the call runs in a forked child and the worker relays its response, so
  `static` variables and any other state the call leaves behind die with the child. A child that hangs is killed
  after `timeout_ms` (`status: "timeout"`), one that dies without an answer is `status: "crashed"`; the worker
  itself goes on. Without pcntl the orchestrator starts a new worker for every such call instead.

Before the call: fakes are reset, globals, superglobals and static properties of user classes are snapshotted,
`error_reporting(E_ALL)`. After the call they are compared (changes are part of the result) and restored.
`static` variables of functions cannot be reset in a running process: the orchestrator uses a fresh worker for them.

Response:

```json
{"ok": true, "status": "done",
 "calls": [{"status": "returned", "value": <value>, "output": <string>,
            "errors": [{"level": "E_WARNING", "message": "Undefined array key \"x\"", "line": 3, "suppressed": false}]}],
 "args": [<value>...], "this": <value>|null,
 "mocks": [{"mock": 1, "method": "now", "args": [<value>...]}],
 "globals": {"name": <value>|{"type": "unset"}}, "statics": {"App\\Cache::$items": <value>},
 "probes": [1, 2, 5], "lines": {"/project/src/Foo.php": {"executed": [...], "executable": [...]}}}
```

- `status`: `done`; `unbuildable` (the input could not be built: `exception` — e.g. the constructor threw);
  `fatal` / `exited` (from the shutdown handler: `calls` up to the failing one, which has `status` `fatal`/`exited`
  and `message`).
- A call `status`: `returned` with `value`, `threw` with `exception`, `fatal`, `exited`.
- `exception`: `{"class", "message", "code", "props": [...user properties], "previous": <exception>|null}` — no
  file, line or trace.
- `errors.line` is relative to the first line of the function (null for errors in other files); the orchestrator
  does not compare lines (code moves when it is rewritten).
- `args` are the arguments after the call: by-ref parameters and mutated objects.
- `mocks` is the journal of mock and callable calls in order (`method` `__invoke` for callables).
- `probes`: ids of branch probes hit; `lines` only with the `xdebug`/`pcov` driver.

## Recipes (inputs)

A recipe describes how to build a value under `php.binary`; objects cannot be passed between processes.

| Recipe | Value |
|---|---|
| `{"type": "null"}`, `{"type": "bool", "value": true}`, `{"type": "int", "value": 5}` | scalars |
| `{"type": "float", "value": "-0.0"}` | float as a string: `NAN`, `INF`, `-INF`, `-0.0`, exact decimal |
| `{"type": "string", "value": "abc"}` / `{"type": "string", "base64": "..."}` | UTF-8 / binary string |
| `{"type": "array", "items": [[<key recipe>, <value recipe>], ...]}` | ordered pairs; keys int or string |
| `{"type": "object", "class": "App\\Money", "via": "ctor", "args": [...], "id": 1}` | `new Money(...args)` |
| `{"type": "object", "class": "App\\Money", "via": "props", "props": {"amount": ..., "Base::secret": ...}, "id": 1}` | without the constructor, properties set in the declaring class's scope (readonly too); `Class::name` for a private property of a parent |
| `{"type": "enum", "class": "App\\Status", "case": "Active"}` | enum case |
| `{"type": "mock", "interface": "App\\Clock", "returns": {"now": [<recipe>, ...]}, "id": 2}` | generated class implementing the interface (or extending an abstract class); each method records its call and returns the next recipe (the last repeats), else a default by return type (`0`, `''`, `false`, `[]`, `null`, `$this` for `self`/`static`, a nested mock for an interface) |
| `{"type": "callable", "returns": [<recipe>, ...], "id": 3}` | closure that records `__invoke` calls and returns its recipes in turn |
| `{"type": "ref", "id": 1}` | the value built earlier for `id` in the same input (identity); build order is `this`, `uses`, `args` |

## Values (results)

The same scalar shapes as recipes, plus:

- `{"type": "array", "items": [[<key>, <value>], ...]}` — order is part of the value.
- `{"type": "object", "class", "id", "props": [["name", <value>], ["Class::private", <value>]], "state": <value>}` —
  properties in declaration order through `get_mangled_object_vars()` (uninitialized ones absent), `state` for
  internal classes (`DateTimeInterface` formatted, `ArrayObject` contents, `SplObjectStorage` pairs…).
  `id` numbers objects in order of first appearance in the response (not `spl_object_id()`), so identity between
  the return value, the arguments, `this` and the journal is visible.
- `{"type": "ref", "id"}` — a cycle (the object is already being described higher in the same value).
- `{"type": "enum", "class", "case"}`, `{"type": "closure", "id", "this", "scope", "params"}`,
  `{"type": "resource", "kind"}`, `{"type": "truncated"}` (depth > 64 or more than 20000 nodes).
- `{"type": "generator", "id", "items": [[<key>, <value>]...], "return": <value>}` or `"exception"` — a returned
  generator is iterated (up to 1000 items) while errors and output are still captured.
- An object that is a `Throwable`: `{"type": "object", "class", "id", "exception": <exception>}`.
