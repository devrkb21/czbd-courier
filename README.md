# CZBD Courier Checker

Laravel package for checking customer delivery behavior across major Bangladeshi courier services.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/devrkb21/czbd-courier.svg?style=flat-square)](https://packagist.org/packages/devrkb21/czbd-courier)
[![Total Downloads](https://img.shields.io/packagist/dt/devrkb21/czbd-courier.svg?style=flat-square)](https://packagist.org/packages/devrkb21/czbd-courier)
[![License](https://img.shields.io/packagist/l/devrkb21/czbd-courier.svg?style=flat-square)](https://packagist.org/packages/devrkb21/czbd-courier)

## What It Does

- Queries multiple couriers with a single phone number input.
- Returns per-courier success/cancel counts and success ratio.
- Builds an aggregate summary across all couriers.
- Supports account failover pools per courier when one account is limited or fails.
- Supports optional global or per-courier HTTP proxy routing.

Supported couriers:

- Steadfast
- Pathao
- RedX
- Paperfly
- Carrybee

## Requirements

- PHP: `^8.2`
- Laravel: `^8.0|^9.0|^10.0|^11.0|^12.0|^13.0`
- Guzzle: `^7.8`

## Installation

```bash
composer require devrkb21/czbd-courier
```

Publish config:

```bash
php artisan vendor:publish --provider="Czbd\\CourierChecker\\CourierCheckerServiceProvider" --tag="courier-checker-config"
```

This creates `config/courier-checker.php`.

You can still use Laravel's default tag when needed:

```bash
php artisan vendor:publish --tag="config"
```

## Environment Configuration

Add credentials to `.env`.

```dotenv
# Pathao
PATHAO_USER="your_pathao_email@example.com"
PATHAO_PASSWORD="your_pathao_password"
PATHAO_USERS="fallback1@example.com,fallback2@example.com"
PATHAO_PASSWORDS="pass1,pass2"

# Steadfast
STEADFAST_USER="your_steadfast_email@example.com"
STEADFAST_PASSWORD="your_steadfast_password"
STEADFAST_USERS="fallback1@example.com,fallback2@example.com"
STEADFAST_PASSWORDS="pass1,pass2"

# RedX (local 11-digit mobile format)
REDX_PHONE="017XXXXXXXX"
REDX_PASSWORD="your_redx_password"
REDX_PHONES="017XXXXXXXX,018XXXXXXXX"
REDX_PASSWORDS="pass1,pass2"

# Paperfly
PAPERFLY_USER="your_paperfly_username"
PAPERFLY_PASSWORD="your_paperfly_password"
PAPERFLY_USERS="fallback_user_1,fallback_user_2"
PAPERFLY_PASSWORDS="pass1,pass2"

# Carrybee (local 11-digit mobile format)
CARRYBEE_PHONE="017XXXXXXXX"
CARRYBEE_PASSWORD="your_carrybee_password"
CARRYBEE_PHONES="017XXXXXXXX,018XXXXXXXX"
CARRYBEE_PASSWORDS="pass1,pass2"

# Proxy flags (strict yes/no)
COURIER_PROXY_ALL="no"
COURIER_PROXY_PATHAO="no"
COURIER_PROXY_STEADFAST="no"
COURIER_PROXY_REDX="no"
COURIER_PROXY_PAPERFLY="no"
COURIER_PROXY_CARRYBEE="no"

# Shared proxy endpoint
COURIER_PROXY_ADDRESS="http://127.0.0.1:8080"
```

Configuration rules:

- Fallback pools are optional.
- Pool values are parsed from comma-separated or newline-separated lists.
- Fallback lists must be index-aligned. Example: 2 users must have 2 passwords.
- Proxy flags accept only `yes` or `no`. Any other value throws an exception.
- `COURIER_PROXY_ALL=yes` enables proxy for all couriers.
- `COURIER_PROXY_{COURIER}=yes` enables proxy only for that courier.

## Usage

Using the facade:

```php
use CourierChecker;

$report = CourierChecker::check('01712345678');
```

Using dependency injection:

```php
use Czbd\CourierChecker\CourierCheckerManager;

public function show(CourierCheckerManager $manager)
{
  return $manager->check('01712345678');
}
```

Using individual service classes:

```php
use Czbd\CourierChecker\Services\PathaoService;

$result = app(PathaoService::class)->getDeliveryStats('01712345678');
```

## Response Shape

```php
[
  'steadfast' => [
    'success' => 10,
    'cancel' => 2,
    'total' => 12,
    'success_ratio' => 83.33,
  ],
  'pathao' => [
    'success' => 5,
    'cancel' => 1,
    'total' => 6,
    'success_ratio' => 83.33,
    'customer_rating' => 'trusted_customer', // optional
  ],
  'redx' => [...],
  'paperfly' => [...],
  'carrybee' => [...],
  'aggregate' => [
    'total_success' => 0,
    'total_cancel' => 0,
    'total_deliveries' => 0,
    'success_ratio' => 0.0,
    'cancel_ratio' => 0.0,
  ],
]
```

## Validation and Error Behavior

- Phone input is validated with `^01[3-9][0-9]{8}$`.
- Service-level errors are returned as arrays with an `error` key.
- Manager-level exceptions are logged and wrapped per courier.

## Testing

From package root:

```bash
composer install
composer test
```

Tests use mocked HTTP responses; live courier credentials are not required.

## Security Notes

- Do not expose raw courier credentials in logs or frontend output.
- Keep `.env` out of version control.
- Prefer least-privilege accounts for courier API access.

## License

This package is licensed under GPL-3.0-only. See `LICENSE.md`.

## Maintainer

- Author: Rakib Uddin
- GitHub: https://github.com/devrkb21
- Repository: https://github.com/devrkb21/czbd-courier
