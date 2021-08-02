# Laravel Protector

[![Latest Version on Packagist](https://img.shields.io/packagist/v/cybex/protector.svg?style=flat-square)](https://packagist.org/packages/cybex/protector)

Protect Databases by generating Backups and Import those on non-productive Environments.
Authenticate users with the usage of Laravel Sanctum Tokens.
Encrypt your database dumps with Sodium Encryption.

## Installation

You can install the package via composer:

```bash
composer require cybex/laravel-protector
```

## Usage

``` php
To generate a sodium encryption key pair use
    protector:keys

To generate a Laravel Sanctum authentication token for a user use
    protector:token <userID>
    
You can import a database dump by using
    protector:import
    
You can export a database dump by using 
    protector:export
```

### Testing

``` bash
composer test
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

### Security

If you discover any security related issues, please email webdevelopment@cybex-online.com instead of using the issue tracker.

## Credits

- [Marco Szulik](https://github.com/cybex-gmbh)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Laravel Package Boilerplate

This package was generated using the [Laravel Package Boilerplate](https://laravelpackageboilerplate.com).
