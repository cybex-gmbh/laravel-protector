# Laravel Protector

[![Latest Version on Packagist](https://img.shields.io/packagist/v/cybex/laravel-protector.svg?style=flat-square)](https://packagist.org/packages/cybex/laravel-protector)

Allows downloading, importing and exporting database backups.


## Common usage scenarios
- Store your local database in a file
- Non-productive developer machines can download the live server database
- A central backup server can collect backups from multiple live servers


## Feature set

- Download and optionally import databases from a server.
- Import existing database files.
- Export the local database to a file.
- User authentication through Laravel Sanctum tokens.
- Transport encryption using Sodium.


## Table of contents

- [Usage](#Usage)
- [Setup Instructions](#Setup Instructions)
    - [Storing the local database](#Setup for storing the local database)
    - [Importing the database of a remote server](#Setup for importing the database of a remote server)
    - [Collecting backups from multiple servers](#Setup for collecting backups from multiple servers)


## Usage

### Import

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

### Export to file

To save a copy of your local database to your local disk, run
```bash
php artisan protector:export
```

## Setup Instructions

Find below three common scenarios of usage. These are not mutually exclusive.

### Setup for storing the local database

If you only want to store a copy of your local database to a disk, the setup is pretty simple.

#### Installing protector in your Laravel project

Install the package via composer.

```bash
composer require cybex/laravel-protector
```

You can optionally publish the protector config to set the following options
- fileName: the file name of the database dump
- baseDirectory: where files are being stored
- diskName: a dedicated Laravel disk defined in config/filesystems.php. These can point to a specific folder, disk or a  cloud file bucket like AWS S3.

```bash
artisan vendor:publish --tag=protector.config
```

#### Usage

You can now use the artisan command to write a backup to the protector storage folder.

```bash
php artisan protector:export
```

By default the file will be stored in storage/protector and have a timestamp in the name. You can also specify the filename.

You could also automate this by
- installing a cronjob on linux
- running it when you deploy to your server
- creating a Laravel Job and queueing it

```bash
php artisan protector:export --file=storage/database.sql
```

### Setup for importing the database of a remote server

This package can run on both servers and client machines of the same software repository. You set up authorized developers on the server, and give them the key for their local machine.

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

You can optionally publish the protector config to set options regarding the storage, access and transmission of the files. 
```bash
artisan vendor:publish --tag=protector.config
```

Publish the protector database migration, and optionally modify it to work with your project.
```bash
php artisan vendor:publish --tag=protector.migrations
```

Run the migration on the client and server repository.
```bash
php artisan migrate
```

#### On the Client

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

>Do not give your private key to anyone and keep it protected at all time

See [Usage](#Usage) on how to import the remote database.

>Downloaded database dump files are stored unencrypted

#### On the Server

Make sure that the server is accessible to the client machine via HTTPS.

When one of your developers gives you their public key from the previous step, you can authorize them with:

```bash
php artisan protector:token --publicKey=<public key> <user id>
```

You will receive the token and url to give back to the developer, who has to save them in their .env file.

The developer can then download and import the server database on their own.

### Setup for collecting backups from multiple servers

You can develop a custom client that can access and store remote server backups. The servers can be different Laravel projects that have the protector package installed.

See the previous chapter on how to give your backup client access to all servers. The backup client will need an according user on each target server.
* All the backup users on the target servers will have the same public key from the client
* For each target server, the client will store the according url and token 

See [cybex-gmbh/collector](https://github.com/cybex-gmbh/collector) for an example implementation.


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
