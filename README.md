# WPML Stubs

PHPStan stubs for WPML Multilingual CMS for local development.

## Requirements

- PHP 8.0 or higher
- PHPStan for static analysis

## Installation

```bash
composer require --dev arts/wpml-stubs
```

## Usage with PHPStan

Add to your `phpstan.neon`:

```yaml
parameters:
    bootstrapFiles:
        - vendor/php-stubs/wordpress-stubs/wordpress-stubs.php
        - vendor/arts/wpml-stubs/wpml-stubs.php
```

## Generating Stubs

1. Copy `.env.example` to `.env`
2. Set `WPML_PATH` to your WPML installation
3. Run: `composer generate`

```bash
cp .env.example .env
# Edit .env with your path
composer generate
```

## Testing

Run tests to validate generated stubs:

```bash
composer test
```

## License

GPL-3.0-or-later
