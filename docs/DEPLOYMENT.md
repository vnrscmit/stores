# Deployment Guide (Windows / XAMPP)

## Requirements

- PHP 8.2+ (XAMPP 8.2 ships 8.2.x) with pdo_mysql, mbstring, openssl, zip, gd
- MariaDB 10.4+ / MySQL 8 (XAMPP bundled MariaDB is supported)
- Composer 2.x

## First install

```bash
composer install --no-dev --optimize-autoloader
copy .env.example .env
php artisan key:generate
# configure DB_DATABASE (stores_laravel), DB_LEGACY_DATABASE (storesd) in .env
php artisan migrate
php artisan legacy:schema-audit        # only when (re)importing legacy data
php artisan legacy:stage-import
php artisan legacy:migrate-data
php artisan legacy:integrity-fix --strategy=placeholder
php artisan legacy:add-constraints
php artisan legacy:verify
php artisan phase1:smoke
php artisan storage:link
```

## Web server

Point the vhost DOCUMENT ROOT at `public/` (never the project root):

```apache
<VirtualHost *:80>
    DocumentRoot "D:/VNR_Projects/stores/public"
    ServerName stores.local
    <Directory "D:/VNR_Projects/stores/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

## Production settings (.env)

- `APP_ENV=production`, `APP_DEBUG=false`
- Dedicated DB user with privileges only on `stores_laravel`
  (the legacy `storesd` user can be dropped after cutover verification)

## Caches (production)

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Scheduled tasks (Windows Task Scheduler)

1. Scheduler heartbeat (every minute):
   `C:\xampp\php\php.exe D:\VNR_Projects\stores\artisan schedule:run`
2. Nightly backup 02:00 — add to `routes/console.php` schedule:
   `Schedule::command('db:backup')->dailyAt('02:00');`
   (Back up BOTH databases; the legacy `storesd` must remain recoverable.)

## Rollback

- The legacy application and its `storesd` database are never modified by
  this system. Rollback = point the vhost DOCUMENT ROOT back at the legacy
  folder. No data migration is required to go back.
- The Laravel `stores_laravel` database can be dropped and re-created from
  the pipeline at any time; `storesd` is the single source of truth until
  the business signs off the cutover.
