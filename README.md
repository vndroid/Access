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