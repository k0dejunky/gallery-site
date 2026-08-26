# Database Migrations

Migration files are numbered SQL files in this directory, for example
`001_add_example.sql`. The runner applies them in filename order and records
each filename, SHA-256 checksum, and application time in `schema_migrations`.
An applied migration is never run again. If its contents change later, the
runner stops with an error rather than silently accepting the drift.

The migration runner uses PDO and has no Composer dependency:

```bash
php scripts/migrate.php --status
php scripts/migrate.php
```

Each migration is executed as one SQL statement batch. The runner attempts a
transaction for each migration, but MySQL/MariaDB DDL can cause implicit
commits, and some DDL is not transactional. Keep migrations small, take the
normal database backup before production changes, and make schema changes
backward-compatible with the application version being deployed.

## Production workflow

1. Review the SQL and run `php scripts/migrate.php --status` against the target
   environment.
2. Deploy the application files and migration files.
3. Run `php scripts/migrate.php` on the production host as the application
   user (or an account with the required database privileges).
4. Confirm the output and application health, then retain the database backup
   and deployment snapshot according to the site's rollback policy.

`000_baseline.sql` is an idempotent no-op marker. The current production
schema was already bootstrapped by `schema.sql`, so the baseline initializes
the migration history without replaying that schema. New schema changes must
be added as numbered migration files; do not edit the baseline.

`001_operational_indexes.sql` uses `information_schema` guards and prepared
statements because MySQL/MariaDB do not support `ADD INDEX IF NOT EXISTS`.
The migration runner is preferred. If a restricted SQL client rejects the
prepared batch, verify the target tables and existing indexes, run only the
missing `ALTER TABLE ... ADD INDEX ...` statements manually, then verify the
result with `php scripts/migrate.php --status`.
