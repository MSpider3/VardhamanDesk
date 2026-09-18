# VardhamanDesk

VardhamanDesk is an internal sales pipeline tracking and GST invoicing application built with Laravel 13, Filament 5, and MySQL 8.

## Technology Stack

- **PHP**: 8.5.10+ (CLI with `pdo_mysql`, `mbstring`, `curl`, `xml`, `zip`)
- **Framework**: Laravel 13.32+
- **Admin UI**: Filament 5.8+ (Livewire 4.4+)
- **Database**: MySQL 8.0+
- **Test Suite**: Pest 4.7+ (`pestphp/pest-plugin-laravel`)

## Prerequisites & Confirmed Setup

1. **PHP & Composer**:
   Ensure PHP 8.3+ (tested on PHP 8.5) and Composer 2.x are installed.

2. **MySQL 8 Database**:
   A running MySQL 8 instance on `127.0.0.1:3306`.
   For example, via Docker:
   ```bash
   docker run -d --name vardhamandesk-mysql -p 127.0.0.1:3306:3306 \
     -e MYSQL_ROOT_PASSWORD=secret \
     -e MYSQL_DATABASE=vardhamandesk \
     -e MYSQL_USER=vardhaman \
     -e MYSQL_PASSWORD=secret mysql:8
   ```

## Local Installation

1. Install dependencies:
   ```bash
   composer install
   ```

2. Environment configuration:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   Verify database credentials in `.env`:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=vardhamandesk
   DB_USERNAME=vardhaman
   DB_PASSWORD=secret
   ```

3. Run migrations and seeders:
   ```bash
   php artisan migrate:fresh --seed
   ```

4. Run test suite:
   ```bash
   ./vendor/bin/pest
   ```

5. Start the local server:
   ```bash
   php artisan serve
   ```
   Access the Filament panel at [http://localhost:8000/admin](http://localhost:8000/admin).

## Demo Credentials (Development Only)

- **Admin**:
  - Email: `admin@vardhamandesk.local`
  - Password: `password`
- **Sales Rep 1**:
  - Email: `sales1@vardhamandesk.local`
  - Password: `password`
- **Sales Rep 2**:
  - Email: `sales2@vardhamandesk.local`
  - Password: `password`
- **Sales Rep 3**:
  - Email: `sales3@vardhamandesk.local`
  - Password: `password`
