# Laravel Protector

[![Latest Version on Packagist](https://img.shields.io/packagist/v/cybex/laravel-protector.svg?style=flat-square)](https://packagist.org/packages/cybex/laravel-protector)

This package allows you to download, export and import your application's database backups.

## Common usage scenarios

- Store your local database in a file
- Non-productive developer machines can download the live server database
- A central backup server can collect backups from multiple live servers

## Feature set

- Download and optionally import databases from a server
- Import existing database files
- Export the local database to a file
- User authentication through Laravel Sanctum tokens
- Transport encryption using Sodium

## Supported databases

- Protector only supports MySQL, MariaDB and PostgreSQL databases at the moment.
- Source and destination databases are currently not checked. Please make sure you run the same software in the same
  versions to prevent issues.
- Because of different dump formats, pulling dumps from MariaDB and restoring them to MySQL will not work. The same
  of course applies to cross-database operations between MySQL and PostgreSQL.

## Notes

- Enabling Laravel Telescope will prevent remote files from being downloaded, as it opens and discards the HTTP stream!

## Table of contents

* [Usage](#usage)
  * [Export to file](#export-to-file)
  * [Import](#import)
* [Setup instructions](#setup-instructions)
  * [Setup for storing the local database](#setup-for-storing-the-local-database)
  * [Setup for importing the database of a remote server](#setup-for-importing-the-database-of-a-remote-server)
  * [Setup for collecting backups from multiple servers](#setup-for-collecting-backups-from-multiple-servers)
* [Migration guide from Protector v1.x to v2.x](#migration-guide-from-protector-v1x-to-v2x)

## Usage

### Export to file

To save a copy of your local database, run

```bash
php artisan protector:export
```

By default, dumps are stored in `storage/app/protector` on your default project disk.
You can configure the target disk, filename, etc. by publishing the protector config file to your project

```bash
artisan vendor:publish --tag=protector.config
```

### Import

Run the following command for an interactive shell

```bash
php artisan protector:import
```

#### Importing a specific source

To download and import the server database in one go, run

```bash
php artisan protector:import --remote
```

When used with other options, remote will serve as fallback behavior.

To import a specific database file that you downloaded earlier, run

```bash
php artisan protector:import --file=<absolute path to database file>
```

Or just reference the database file name in the protector folder (default folder is storage/app/protector).

```bash
php artisan protector:import --dump=<name of database file>
```

To import the latest existing database file, run

```bash
php artisan protector:import --latest
```

#### Options

If you want to run migrations after the import of the database file, run

```bash
php artisan protector:import --migrate
```

For automation, also consider the flush option to clean up older database files, and the force option to bypass user
interaction.

```bash
php artisan protector:import --remote --migrate --flush --force
```

To learn more about import options, run

```bash
php artisan protector:import --help
```

## Setup instructions

Find below three common scenarios of usage. These are not mutually exclusive.

### Setup for storing the local database

If you only want to store a copy of your local database to a disk, the setup is pretty simple.

#### Installing protector in your local Laravel project

Install the package via composer.

```bash
composer require cybex/laravel-protector
```

You can optionally publish the protector config to set the following options

- `fileName`: the file name of the database dump
- `baseDirectory`: where files are being stored
- `diskName`: a dedicated Laravel disk defined in config/filesystems.php. These can point to a specific local folder or
  a cloud file bucket like AWS S3

```bash
artisan vendor:publish --tag=protector.config
```

#### Local usage

You can now use the artisan command to write a backup to the protector storage folder.

```bash
php artisan protector:export
```

By default, the file will be stored in storage/protector and have a timestamp in the name. You can also specify the
filename.

You could also automate this by

- installing a cronjob on linux
- running it when you deploy to your server
- creating a Laravel Job and queueing it

```bash
php artisan protector:export --file=storage/database.sql
```

### Setup for importing the database of a remote server

This package can run on both servers and client machines of the same software repository. You set up authorized
developers on the server, and give them the key for their local machine.

#### Installing protector in your Laravel project

Install the package via composer.

```bash
composer require cybex/laravel-protector
```

In your User model class, add the following trait.

```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    ...
}
```

Publish the protector database migration, and optionally modify it to work with your project.

```bash
php artisan vendor:publish --tag=protector.migrations
```

Run the migration on the client and server repository.

```bash
php artisan migrate
```

You can optionally publish the protector config to set options regarding the storage, access and transmission of the
files.

```bash
php artisan vendor:publish --tag=protector.config
```

#### On the client machine

Run the following command to receive

- the public key to give to your server admin
- the private key to save in your .env file

```bash
php artisan protector:keys
```

Your server admin will then give you the token and server-url to save in your .env file.
Unless specified otherwise in your software, the .env keys are

```bash
PROTECTOR_AUTH_TOKEN=
PROTECTOR_SERVER_URL=
```

> Do not give your private key to anyone and keep it protected at all time

See [Usage](#usage) on how to import the remote database.

> Downloaded database dump files are stored unencrypted

#### On the server

Make sure that the server is accessible to the client machine via HTTPS.

When one of your developers gives you their public key from the previous step, you can authorize them with:

```bash
php artisan protector:token --publicKey=<public key> <user id>
```

You will receive the token and url to give back to the developer, who has to save them in their .env file.

The developer can then download and import the server database on their own.

### Setup for collecting backups from multiple servers

You can develop a custom client that can access and store remote server backups. The servers can be different Laravel
projects that have the protector package installed.

See the previous chapter on how to give your backup client access to all servers. The backup client will need an
according user on each target server.

- All the backup users on the target servers will have the same public key from the client
- For each target server, the client will store the according url and token

See [cybex-gmbh/collector](https://github.com/cybex-gmbh/collector) for an example implementation.

## Migration guide from Protector v1.x to v2.x

Likelihood of impact: high

- If your app does not explicitly require the laravel/sanctum package, upgrading Protector to version 2.x will also 
upgrade Sanctum to version 3.x. This will require you to follow its 
[upgrade guide](https://github.com/laravel/sanctum/blob/3.x/UPGRADE.md).

Likelihood of impact: low

- Access to the formerly public methods `getGitRevision()`, `getGitHeadDate()` or `getGitBranch()` is now protected.
You now need to call getMetaData() and extract the information from the returned array.

## Migration guide from Protector v2.x to v3.x

No breaking changes are expected.

## Development

```bash
sail up -d
```

```bash
composer install
```

### Testing

Run tests on MySQL database: 

```bash
vendor/bin/phpunit
```

Run tests on PostgreSQL database: 

```bash
vendor/bin/phpunit -c phpunit-postgres.xml.dist
```

```bash

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

### Security

If you discover any security related issues, please email webdevelopment@cybex-online.com instead of using the issue
tracker.

## Credits

- [Web Development team at Cybex GmbH - cybex-online.com](https://github.com/cybex-gmbh)
- [Gael Connan](https://github.com/gael-connan-cybex)
- [Jörn Heusinger](https://github.com/jheusinger)
- [Fabian Holy](https://github.com/holyfabi)
- [Oliver Matla](https://github.com/lupinitylabs)
- [Marco Szulik](https://github.com/mszulik)
- [All Contributors](https://github.com/cybex-gmbh/laravel-protector/graphs/contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Laravel Package Boilerplate

This package was generated using the [Laravel Package Boilerplate](https://laravelpackageboilerplate.com).
