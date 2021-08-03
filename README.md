# Laravel Protector

[![Latest Version on Packagist](https://img.shields.io/packagist/v/cybex/laravel-protector.svg?style=flat-square)](https://packagist.org/packages/cybex/laravel-protector)

Allows a client to download and import a live server database backup.

Common usage scenarios
- Non-productive developer machines download the live server database. 
- A central backup server collects backups from multiple live servers.

Feature set:
 
- Download and import databases from a server.
- Export the local database to a file.
- User authentication through Laravel Sanctum tokens.
- Transport encryption using Sodium.

## Installation

You can install the package via composer, and run its database migration:

```bash
composer require cybex/laravel-protector
artisan migrate
```

In your User model class, add the following trait, then commit and publish the change.
```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    ...
}
```

## Setup

This package would usually run on both a server and a client machine of the same software repository.
You set up authorized developers on the server, and give them the key for their local machine.

### On the Client

As a developer, run the following command
```bash
php artisan protector:keys
```

You will receive
- the public key to give to your server admin
- the private key to save in your .env file

Your server admin will then give you the token and server-url to save in your .env file.
Unless specified otherwise in your software, the .env keys are

```bash
PROTECTOR_AUTH_TOKEN=
PROTECTOR_SERVER_URL=
```

See the Usage chapter on how to download and import the current server database.

>Do not give your private key to anyone and keep it protected at all time

>Downloaded database dump files are stored unencrypted

### On the Server

Please make sure that the server is accessible to the client machine via HTTPS.

When one of your developers gives you their public key from the previous step, you can authorize them with: 

```bash
php artisan protector:token --publicKey=<public key> <user id>
```

You will receive the token and url to give back to the developer, who has to save them in their .env file.

The developer can then download the server database on their own.

## Usage

#### Import

Run the following command for an interactive shell
```bash
php artisan protector:import
```

To download and import the server database in one go, run
```bash
php artisan protector:import --remote
```

To import a database you downloaded earlier, run
```bash
php artisan protector:import --file=<your backup file>
```

To learn more about import options run
```bash
php artisan protector:import --help
```

>By default dumps are stored in `storage/app/protector`

#### Export

To save a copy of your local database to your local disk, run
```bash
php artisan protector:export
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

### Security

If you discover any security related issues, please email webdevelopment@cybex-online.com instead of using the issue tracker.

## Credits

- [Web Development team at Cybex GmbH - cybex-online.com](https://github.com/cybex-gmbh)
- [Marco Szulik](https://github.com/mszulik)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Laravel Package Boilerplate

This package was generated using the [Laravel Package Boilerplate](https://laravelpackageboilerplate.com).
