# Database Safety Rules

These rules are mandatory for every automated agent working in this repository.

- Never run `php artisan test`, PHPUnit, Pest, or `RefreshDatabase` against PostgreSQL.
- Tests may run only after the resolved connection is confirmed as `sqlite` with database `:memory:`.
- Never run `db:wipe`, `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, destructive seeders, `DROP`, or `TRUNCATE` against PostgreSQL.
- Never delete or recreate a local, staging, or production database to fix application behavior.
- Before any command that can write to a database, inspect and report the resolved driver, host, database name, and application environment without exposing credentials.
- Production database operations require an explicit user request for that exact operation and a verified recoverable backup created before execution.
- Never change Railway database credentials, roles, volumes, backups, or connection variables without explicit user approval.
- Prefer forward-only migrations. A normal `php artisan migrate --force` is allowed during an explicitly requested production deployment; destructive rollback workflows are not.
