# Isolated annual report benchmark

Synthetic SQL verification, not a full Sylius workflow or HTTP performance proof. The
runner uses real Sylius 1.14 models, minimal Doctrine mappings and report SQL;
repository-interface doubles only forward query builders. No kernel or `.env` is loaded.
Data is generated locally, not committed; CI does not automatically load it.

## Local setup and run

Install isolated dependencies and start this benchmark's own Compose project:
```sh
composer install --working-dir=tests/Performance
docker compose -p sales-reports-review -f tests/Performance/compose.yaml up -d --wait
docker compose -p sales-reports-review -f tests/Performance/compose.yaml port database 3306
# Substitute the reported ephemeral loopback port below.
export SALES_REPORT_BENCHMARK_DATABASE_URL='mysql://root@127.0.0.1:PORT/sales_reports_benchmark'
make benchmark.fixtures
make test.performance
```

The default is **30000 accepted orders**. The only larger supported scale is:

```sh
make benchmark.fixtures BENCHMARK_ORDERS=50000
make test.performance BENCHMARK_ORDERS=50000
```

At most **50000 accepted orders plus four excluded fixtures** are loaded.
Only exact scales `10`, `30000`, `50000` are accepted, before database access.
The URL must explicitly name loopback MySQL and `sales_reports_benchmark[_suffix]`
(lowercase alphanumeric suffix); query parameters/fragments are rejected.
URL-encode special credential characters. Use a dedicated, restricted local user.
`--load`/`--load-only` can create that database; they replace only `bench_*` tables,
never drop the database. Manifests and actual counts reject stale scales/profiles.

The small profile caps MySQL at 1 GiB, with a 512 MiB InnoDB pool and 16 MiB
temp/heap limits; Make sets PHP to 1 GiB. JSON records actual database settings,
data-query count, monotonic report time, memory and all six checked outputs.
Loading, table-statistics refresh, proxy initialization and assertions are outside
report measurement. Fresh statistics avoid poor plans after bulk fixture inserts.
The report ceiling is 180 seconds (`--limit` may only lower it); each query receives
the remaining SQL deadline. The external 185-second watchdog also bounds PHP work.
The aggregate entry point uses 8 data queries plus 3 transaction-control statements.
The full call, including transaction overhead, remains inside report timing.
Benchmark-only deadline SETs are not included in these query counts.
Optional `--source=/absolute/baseline/src`
runs original baseline public methods (16 queries) in a separate process on the same
data. Cache state is uncontrolled; use PHP 8.2+ and identical limits for comparisons.
The isolated Flysystem advisory exception is for local CLI only (no HTTP/uploads),
not a production dependency policy.

The aggregate path reads one repeatable-read snapshot, including options, on
MySQL/MariaDB or PostgreSQL. It requires the same order/options connection,
autocommit enabled and no caller-owned transaction; other cases fail explicitly.
Isolation applies to this transaction only, without changing session defaults.
Legacy customization paths keep their existing transaction behavior.

## Deterministic data and correctness controls

The annual profile spans all 365 days of 2025, including day-boundary timestamps.
One product has two variants: 90% popular orders have quantity 3; 10% rare have 1.
Four extra rows independently test channel, date, order-state and payment exclusions.
Integer formulas check all six reports, rounded averages, exact groups and each
group's net, tax, promotions, item totals, quantities and distinct order counts.
Legacy option counts use distinct representative order IDs (9 and 10 in controls).

Use a separate URL ending in `sales_reports_benchmark_control` for these commands:

```sh
php -d memory_limit=1G tests/Performance/run.php --orders=10 --load
php -d memory_limit=1G tests/Performance/run.php --orders=10 --load --fanout
php -d memory_limit=1G tests/Performance/run.php --orders=10 --load --multiitem
php -d memory_limit=1G tests/Performance/run.php --orders=10 --load --unitless
```

Controls have literal financial checks. Regression flags are mutually exclusive,
require `--load` and forbid `--load-only`. Fan-out counts equal promotions separately;
multi-item requires 10 product orders versus 9+2 variant orders. Unitless preserves
the old LEFT JOIN zero-unit placeholder despite stale stored quantities/totals.
Minimal mappings and these finite controls are not a production query-plan guarantee.
