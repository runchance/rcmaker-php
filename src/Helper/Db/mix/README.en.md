# Mix Database Compatibility

[中文](README.md)

This directory retains the legacy `RC\Helper\Db\mix` API for PHP 8.5. **It is not a new SDB engine.** New application work should still use framework AutoForm, SDB for ordinary database access, and the framework database interface for complex SQL. Do not initialize databases inside application models.

## Existing Projects

The following `$database` is an existing `RC\Helper\Db\mix\Database\Database` instance held by a legacy integration, not an instruction to introduce separate application connection initialization.

```php
use RC\Helper\Db\mix\Database\Expr;

$users = $database->table('users')
    ->where('id IN (?)', [1, 2, 3])
    ->order('id', 'asc')
    ->limit(20)
    ->get();

$database->table('users')->where('id = ?', 1)->updates([
    'name' => "O'Reilly",
    'visits' => new Expr('visits + ?', 1),
]);
```

- Array values expand into PDO placeholders, never SQL literals. Empty arrays expand to `NULL`. Do not replace `IN (?)` binding with concatenation.
- `Expr` accepts developer-controlled SQL templates; external values belong in subsequent binding arguments. Casting an expression with bound values to a string is rejected.
- SQL fragments in `table/select/join/where/order/group/having` remain trusted SQL APIs. User-provided identifiers and statements are not automatically validated; whitelist dynamic fields and ordering.
- An ordinary query object executes once. Start the next query from `$database` instead of reusing a retained builder.

## SQL Modes And Array Parameters

MySQL `NO_BACKSLASH_ESCAPES`/`ANSI_QUOTES` and PostgreSQL `standard_conforming_strings` change quoting rules. Array placeholders follow the **current mode of the actual execution PDO**, without interpolating values or caching session modes across queries. Metadata failures stop business preparation, and cursor cleanup cannot replace an earlier fetch exception.

This adaptation adds no mode query for scalar values or ordinary array SQL. Only backslash/quote combinations in the SQL template that can change placeholder positions require metadata. Backslashes in bound values do not trigger it. Prefer bound text to avoid that extra path.

PDO emulated preparation retains native scanner limitations for some nondefault MySQL modes. When those modes and complex raw SQL are intentional, explicitly configure this in the **existing MySQL connection's PDO options**:

```php
PDO::ATTR_EMULATE_PREPARES => false,
```

The framework does not silently change preparation options, replace the PDO or rewrite user SQL backslashes. Prefer explicit PostgreSQL `E'...'` escape strings. Ordinary strings with standard mode off, or placeholders around complex Dollar Quote bodies, can still hit native PDO limits. Do not bypass binding errors through concatenation.

## Limits And Offsets

Omitting `limit()` is unbounded. Explicit `limit(0)` is an empty bound, not an unset value. `limit()` / `offset()` retain their `int` parameters; negative values fail without replacing the previous valid bound.

| Call | Behavior |
| --- | --- |
| `limit(0)->get()` / `first()` | Returns `[]` / `false`, including aggregate queries; `value()` still raises a missing-row error |
| `limit(0)->update(...)` / `delete()` | Executes native empty-match SQL retaining the original conditions; returns the original connection result with `rowCount()` equal to 0 |
| `offset(10)->get()` | Actually skips ten rows on SQLite, MySQL and PostgreSQL; specify ordering for stable pagination |
| Positive bounded mutation | MySQL accepts LIMIT without an offset; SQLite depends on its optional native compiled grammar; other dialects fail explicitly |
| Mutation with a positive offset | Delegated only to SQLite native bounded-DML grammar, with native errors propagated; MySQL and PostgreSQL reject it explicitly |

```php
$rows = $database->table('users')->order('id', 'asc')->offset(20)->limit(10)->get();
$affected = $database->table('users')->where('active = ?', 0)->limit(0)->delete()->rowCount(); // 0
```

Zero-bound writes group all OR conditions before adding an empty-match guard; this is not a SQL parser. Never concatenate user-controlled SQL fragments. Unsupported native `ORDER/GROUP/JOIN/LOCK` combinations may still fail; modifiers are not dropped merely to succeed. Statement-level triggers may execute, so zero matching rows is not a promise of no database side effects.

These bounds apply only to table builders. `raw()` / `exec()` execute immediately; appending `limit()` cannot retroactively constrain their SQL. `first()` retains cursor semantics and does not force a one-row query; use `limit(1)->first()` when only one row is needed. Each compiled transaction statement clears its bounds; the next statement is unbounded unless explicitly constrained.

## Use The Supplied Transaction

```php
$database->transaction(static function ($transaction): void {
    $transaction->table('users')->where('id = ?', 1)->update('name', 'updated');
    $transaction->insert('audit_log', ['message' => 'user updated']);
});
```

Normal completion commits; exceptions trigger rollback without replacing the original error. The outer manager and retained builders cannot borrow a connection owned by the active transaction. For manual transactions, use the object returned by `beginTransaction()` and explicitly call `commit()` / `rollback()`. Abandoned transactions attempt rollback. Do not bypass this lifecycle with raw `BEGIN/COMMIT/ROLLBACK`; nested transactions and automatic savepoints are not promised.

A transaction already known to have lost its session cannot execute or commit, and cannot continue on a replacement connection. Cursor-close failure still permits a rollback attempt, retaining the first error. Business SQL errors may abort transactions according to the database's own rules; catching and ignoring an error does not guarantee a later commit will persist data.

## Results And Failures

| Case | Behavior |
| --- | --- |
| `queryColumn()` | Preserves `0`, empty strings, `false`, and `null` without truncation |
| `value()` | Returns stored `null`; missing rows or fields still raise an error |
| `queryOne()` / `queryScalar()` | Reads the next row; unread pooled results retain their connection |
| `queryAll()` / `queryColumn()` | Drains remaining rows before returning a pooled connection; result destruction also closes its cursor |
| SQL or network failure | Keeps native exceptions; promotes PDO false failures as described below, without replay or transaction retries |
| New query after a marked disconnect | Only a fresh nonpooled manager borrow attempts reconnection; the previous statement is not recovered |

A disconnect after a write can mean the server executed it but its acknowledgement was lost. Do not assume the write was unexecuted or retry it blindly. Orders and payments still require business idempotency keys and explicit recovery rules.

Native SQLSTATE/vendor status takes priority over text. A duplicate value, identifier or business exception containing "server has gone away" must not by itself discard a healthy connection. Query cancellation is not a disconnect; server-terminated idle sessions are lost connections. Unknown driver statuses retain the legacy compatibility detector; this is not verification of every PDO driver, locale or failure scenario.

`PDO::ATTR_ERRMODE` is not forced to another mode. Native false failures in silent/warning mode become `RC\Helper\Db\PdoFailure` (a `PDOException` subclass) carrying the failing provider's current `errorInfo`. `getCode()` is SQLSTATE; the vendor code belongs in `errorInfo[1]`. Already-thrown native exceptions are not wrapped, and warning mode still emits native warnings. See [PDO error handling](https://www.php.net/manual/en/pdo.error-handling.php).

`queryAll()` / `queryColumn()` check silent fetch errors instead of accepting partial arrays as complete results. Rows already delivered by incremental APIs cannot be withdrawn, but a read failure throws instead of pretending to be normal EOF. A pooled driver whose cursor cannot close is discarded rather than returned; cleanup does not replace an earlier business exception.

`lastInsertId()` follows PDO semantics. PostgreSQL callers needing an exact key should use explicit `RETURNING`, not guessed table or sequence names. Automatic transaction identity capture is deferred until after a successful PostgreSQL commit, so `LASTVAL` cannot abort a manual-key transaction. An unavailable sequence identity caches an empty string. Explicit calls inside a transaction retain native behavior. Optional identity capture cannot turn a confirmed commit into a reported commit failure.

Debug records keep SQL templates and bound values instead of interpolated executable SQL. Logs may contain application data; access and redaction remain application responsibilities.

## Pool Boundaries

No Workerman coroutine support was added. Legacy `startPool()` requires a real Swoole coroutine context; unsupported environments fail clearly while retaining the working synchronous driver. **Real Swoole scheduling, waiting/reclamation and sustained pool pressure remain unverified.** PHP return-counter test doubles establish result ownership only, not actual coroutine-pool behavior.

Current cases cover SQLite, MySQL and PostgreSQL values, bindings, transactions, pagination, cleanup and actual array reads/writes under the listed live modes. Native/emulated preparation is recorded separately; native emulation failures are not successes. Other PDO dialects, all SQL-mode combinations, real mid-command network failures and lost commit acknowledgements need separate validation. This is not a promise for arbitrary SQL grammars, databases or concurrency models.

## Upstream And Loading

Stable releases underpin Mix Database **3.0.23**, Mix Redis **3.0.11** and Mix Object Pool **3.0.27**. Provenance and per-file hashes are recorded in `src/ThirdParty/mix-*/upstream-manifest.json`. The original archives declare Apache-2.0 but omit its full text; an unmodified official license text is separately included with its provenance.

RC reuses the native Driver, Expr data structure and ObjectPool primitives. Query and transaction logic remains an explicitly marked RC-derived adapter fixing interpolation, recursive replay, implicit nullable parameters and ownership. Calling upstream `Mix\Database\Database` directly **is not the same API**; RC results do not accept every native SQL, recovery or pool behavior.

### PHP 8.5 Declaration Compatibility

Bundled AbstractConnection.php and Connection.php each add explicit `?int` to queryOne/queryAll's fetchStyle parameter: four question marks total. The old `int $fetchStyle = null` already accepts null and reflects as `?int`, but PHP 8.4+ deprecates its declaration syntax. Bodies, names, defaults, return types and SQL do not change; no runtime wrapper or diagnostic suppression is added. [PHP documentation](https://www.php.net/manual/en/migration84.deprecated.php#migration84.deprecated.core.implicitly-nullable-parameter)

These two files are explicitly not pristine. Manifest files retain the original release hashes; compatibility_patches records runtime hashes. Auditing reverses exactly two markers per file and checks restoration of every published byte. Other upstream files remain unchanged.

The manifest's runtime_sha256 describes revised plaintext source, not an encrypted PHAR entry. Protected delivery checks actual payload bytes and origins separately; runtime does not compare ciphertext with plaintext hashes.

This affects only the bundled copy, not a user's Composer-installed mix/database. RC still prefers Composer Driver/Expr primitives without loading that package's native query core. Separate declaration probes deliberately isolate the bundled native copy; they do not claim the external Composer query classes have been patched.

Compatible Composer packages take precedence; the adjacent bootstrap otherwise loads the bundled copy while retaining `phar://` paths. Neither changing `public_path()` nor redirecting to external source directories is required. Mix Redis adaptation and boundaries have a [separate guide](../../Redis/mix/README.en.md); database results do not establish Redis acceptance.
