# Access

## Access Logs Plugin For Typecho

Record typecho system access logs and analytics.

## Thanks

Thanks a lot for the ide support from [jetbrains](https://jb.gg/OpenSourceSupport).

![LOGO](https://resources.jetbrains.com/storage/products/company/brand/logos/PhpStorm.png)

## Intro

Current Version: [CHANGELOG](/CHANGELOG)

Current Lauguage: **English** | [Simplified Chinese](/README_CN.md)

## Preview

![VIEW1](/pictures/example1.png)

![VIEW2](/pictures/example2.png)

### Latest Update Description

- Fix Chromium Style Error.
- Add IPv6 Support.
- Add [MailMaster](http://mail.163.com/dashi/) Support.
- General bug fixes and performance improvements.

### Notice

* This branch need to change the Database to `_access` (**REMOVE** the _log suffix), Example: `typecho.typecho_access`.
* When the plugin update, please disable the plugin before updating.
* The plugin directory name must be 'Access'.
* Requires PHP 8.2 or newer (checked at activation).
* Need PHP cURL ext.
* MySQL / MariaDB, SQLite and PostgreSQL are supported. The schema files live in `sql/MySQL.sql`, `sql/SQLite.sql` and `sql/PostgreSQL.sql`; the right one is picked automatically from the active Typecho database adapter.
* Access logs share Typecho's own database by default, but the plugin settings can point them at a separate MySQL / PostgreSQL server instead (host, port, user, password, database, table prefix). The target database must already exist — the plugin only creates the table. The connection is tested before the settings are saved, and article titles are resolved with a second query against Typecho's own database since a cross-database JOIN is not possible. Existing stats are migrated to the new database: up to 50k rows are moved inline when the settings are saved. Beyond that there are two options, both using keyset pagination with batched inserts and both resumable: a progress bar in the stats console, which drives the migration in ~3s chunks from the browser (no SSH needed, measured at roughly the same throughput as the CLI), and `php usr/plugins/Access/tools/migrate.php` for very large tables or scheduled jobs. An incomplete migration is always reported as incomplete — it is never silently treated as done. Sites upgrading from 3.0.x must disable and re-enable the plugin once so the migration route gets registered.
* When Redis is configured (i.e. "cache acceleration" is on), a **write queue** is enabled automatically: hits are pushed to a Redis list and flushed to the database in batches with a single multi-row INSERT. This removes the per-hit connection setup and cuts database connections from one-per-hit to one-per-batch, which is what actually saturates `max_connections` under a traffic spike. Measured against a separate PostgreSQL, one process per request: 4.67 ms/hit direct vs 0.12 ms/hit queued. Flushing is triggered by visits and deferred until after the response is sent (`fastcgi_finish_request` under PHP-FPM), the console flushes synchronously before reading, and `tools/flush-queue.php` can be put on cron as a backstop. The queue is never trimmed unless the write actually succeeded, and it is capped at 200k entries. With no Redis — or with the queue disabled in the settings — writes behave exactly as before.
* Large tables (hundreds of thousands of rows and up) get two extra covering indexes. The overview aggregates by time range: PV is `COUNT(*)`, IP is `COUNT(DISTINCT ip)`, UV is `COUNT(DISTINCT ip, ua)`. With only a single-column `time` index the last two have to visit the heap for every matching row to read `ip` / `ua`, and on a 3M-row table that heap I/O dominates. Since 3.1.0 the schema ships with `(time, ip)` and `(time, ip, ua)`; on PostgreSQL the plan becomes an Index Only Scan with `Heap Fetches: 0` and buffer reads drop from 838 to 165. Tables created by 3.0.x are missing them; the plugin does not alter existing tables, so run the two `CREATE INDEX` statements from the schema file under `sql/` by hand (substituting your own table prefix for `typecho_`). Use `CONCURRENTLY` on PostgreSQL to avoid blocking reads and writes; MySQL and SQLite block writes on that table while the index builds, so pick a quiet moment. Measured at roughly 6s per index on 3M rows.
* The overview page loads in sections — today, yesterday, totals, referrers, the article pie chart and the current month are each their own request, filling the page as they arrive. This breaks a vicious cycle: one big request times out, so nothing finishes, so nothing is cached, so the next load is a first load all over again. Split up, every request completes (the slowest section measured 1.5s on 3M rows), the cache gets built, and a warm overview is around 0.1s. Past days are cached long-term since their numbers can no longer change, and the month chart advances day by day, resuming from the cache if one request cannot finish it. That resumption needs Redis; without it the sections still work, they are just recomputed each time.
* Since v3.1.2, plugin settings can live in a file and ship with the code. On activation, if `config/current.yaml` exists the plugin configures itself from it; on deactivation the current settings are written back to that same file, so "deactivate → move host or rebuild the container → put the file back → activate" is a closed loop with no re-typing in the admin UI. Keys present in the file win, **keys absent fall back to defaults** — loading it replaces the configuration wholesale with what the file describes. The document is a top-level `access:` map of `key: value` lines; see [`config/README.md`](config/README.md) for every key, its default and a full example. Booleans accept `1`/`0` or `true`/`false`, and quoted values are always literal (`dbName: "off"` is the string off). Only a small YAML subset is supported (scalars, quotes, comments) and no extension is required, though PHP's `yaml` extension is used for parsing when present. The file holds the database password, Redis password and IPinfo token: it is written with mode `0600`, and `config/` ships with an `.htaccess` and an empty `index.html` to block direct download — **neither has any effect under Nginx**, so deny that directory in the site config yourself; the plugin's `.gitignore` already lists `config/current.yaml` so it is not committed by default. A parse failure (bad syntax, unreadable file) never blocks activation; it is reported in the success notice and the existing configuration is kept. A write failure (read-only directory) never blocks deactivation. The table is created after the file's settings are applied, so a separate database named in the file takes effect immediately — and if that database cannot be reached, activation fails as usual **without** overwriting the configuration already stored in the database.

### Features

- Show PV/UV and more.
- Ignore the administrator login log.
- Support the referer and domain show and sort.
- Add remove all logs when the plugin is disabled feature.
- Support Frontend or Backend write log.
- Log filter supports filtering by ip, article title, and route
- Works on MySQL / MariaDB, SQLite and PostgreSQL

### Author

<a href="https://github.com/vndroid/Access/graphs/contributors">
<img src="https://contrib.rocks/image?repo=vndroid/Access" />
</a>

And origin authors

[@kokororin](https://github.com/kokororin)

[@一名宅](https://github.com/tinymins)

[@Zhizheng Zhang](https://github.com/izhizheng)