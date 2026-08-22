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
* Need PHP Calendar ext.
* Need PHP cURL ext.
* MySQL / MariaDB, SQLite and PostgreSQL are supported. The schema files live in `sql/MySQL.sql`, `sql/SQLite.sql` and `sql/PostgreSQL.sql`; the right one is picked automatically from the active Typecho database adapter.
* Access logs share Typecho's own database by default, but the plugin settings can point them at a separate MySQL / PostgreSQL server instead (host, port, user, password, database, table prefix). The target database must already exist — the plugin only creates the table. The connection is tested before the settings are saved, and article titles are resolved with a second query against Typecho's own database since a cross-database JOIN is not possible. Existing stats are migrated to the new database: up to 50k rows are moved inline when the settings are saved. Beyond that there are two options, both using keyset pagination with batched inserts and both resumable: a progress bar in the stats console, which drives the migration in ~3s chunks from the browser (no SSH needed, measured at roughly the same throughput as the CLI), and `php usr/plugins/Access/tools/migrate.php` for very large tables or scheduled jobs. An incomplete migration is always reported as incomplete — it is never silently treated as done. Sites upgrading from 3.0.x must disable and re-enable the plugin once so the migration route gets registered.

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