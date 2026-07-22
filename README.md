# Laravel Protector

[![Latest Version on Packagist](https://img.shields.io/packagist/v/cybex/laravel-protector.svg?style=flat-square)](https://packagist.org/packages/cybex/laravel-protector)

This package allows you to download, export and import your application's database.

> [!IMPORTANT]
> This package will not work if you have disabled "proc_open" in your PHP configuration.

## Table of contents

* [Introduction](#introduction)
    * [Common usage scenarios](#common-usage-scenarios)
    * [Feature set](#feature-set)
    * [Disks](#disks)
* [Database Support](#database-support)
* [Setup](#setup)
    * [General](#general)
    * [Local usage](#local-usage)
    * [Importing or storing the database of a remote server](#importing-or-storing-the-database-of-a-remote-server)
* [Usage](#usage)
    * [General information](#general-information)
    * [Export](#export)
    * [Import](#import)
    * [Importing or downloading remote databases](#importing-or-downloading-remote-databases)
* [Configuration](#configuration)
    * [Protector instances](#protector-instances)
    * [Disks](#disks-1)
    * [Dump metadata](#dump-metadata)
* [Development](#development)
    * [Testing](#testing)

## Introduction

### Common usage scenarios

- Export your local database to a file
- Developer machines can download the live server database
- A central backup server can collect backups from multiple live servers

### Feature set

- Download and optionally import databases from a server
- Import existing database files
- Export the local database to a file
- User authentication through Laravel Sanctum tokens
- Transport encryption using Sodium
- Operates fully on Laravel disks

### Disks

The Protector always operates on two local disks, which can be configured separately:

All operations that require file handling, such as creating or importing a database dump,
will create a local copy on the `protector_local` disk for processing, and delete it afterwards.

See the [Configuration](#disks-1) section for more details on disk configuration.

## Database Support

Protector supports the following databases:

| Database   | Driver    | Dump tool      | Import tool |
|------------|-----------|----------------|-------------|
| MariaDB    | `mariadb` | `mariadb-dump` | `mariadb`   |
| PostgreSQL | `pgsql`   | `pg_dump`      | `psql`      |

MySQL is no longer officially supported, but the Protector still has capabilities to work with Laravel's `mysql` driver.
If this should break in the future, feel free to submit a PR.

> [!NOTE]
> - Source and destination databases are not validated. Make sure you run compatible software versions to prevent issues.
> - Because of different dump formats, dumps will not able to be imported into a different database engine,
    e.g. a MariaDB dump will fail to be imported into PostgreSQL, and vice versa.

## Setup

There are two setup scenarios, depending on your use case.

- if you only want to operate locally, the General section is sufficient
- if you want to import or download the database of a remote server, follow the additional setup

### General

Install the package via composer.

```bash
composer require cybex/laravel-protector
```

Almost all config options can be set via environment variables. Take a look at the [ProtectorEnv](src/Enums/ProtectorEnv.php) class for all available options.

You can optionally publish the Protector config files to have more fine-grained control over config settings:

```bash
php artisan vendor:publish --tag=protector.config
```

See the [Configuration](#configuration) section for specific details for certain config options.

### Local usage

There is no additional setup required for using the package locally.

See the [Usage](#usage) section for how to export your local database to a file or import existing database dumps.

### Importing or storing the database of a remote server

This package can run on both servers and client machines of the same software repository.
You set up authorized developers on the server and give them the key for their local machine.

In your User model class, add the following trait:

```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    ...
}
```

Publish the Protector database migration and optionally modify it to work with your project.

```bash
php artisan vendor:publish --tag=protector.migrations
```

Publish the [Laravel Sanctum](https://laravel.com/docs/master/sanctum) migration, to make the `personal_access_tokens` table available.

```bash
php artisan vendor:publish --tag=sanctum-migrations
```

Run the migrations on the client and server repository.

```bash
php artisan migrate
```

#### On the client machine

Run the following command to receive

- the public key to give to your server admin
- the private key to save in your .env file

```bash
php artisan protector:keys
```

> [!IMPORTANT]
> Do not give your private key to anyone and keep it protected at all times!

Your server admin will then give you the token and dump endpoint URL to save in your .env file.

```dotenv
PROTECTOR_CLIENT_AUTH_TOKEN=
PROTECTOR_CLIENT_DUMP_ENDPOINT_URL=
```

See [Usage](#usage) on how to import the remote database.

> [!NOTE]
> Downloaded database dump files are stored unencrypted.

#### On the server

Make sure that the server is accessible to the client machine via HTTPS.

When one of your developers gives you their public key, you can authorize them with:

```bash
php artisan protector:token --publicKey=<public key> <user id>
```

You will receive the token and dump endpoint URL to give back to the developer, who has to save them in their .env file.

The developer can then download and import the server database on their own.

### Setup for collecting backups from multiple servers

You can develop a custom client that can access and store remote server backups.
The servers can be different Laravel projects that have the Protector package installed.

See the previous chapter on how to give your backup client access to all servers.

- The backup client will need an according user on each target server.
- All the backup users on the target servers will have the same public key from the client
- For each target server, the client will store the according url and token

## Usage

### General information

Each stored dump also has a matching metadata file with the `.meta` suffix (for example `dump.sql.meta`).
The metadata file stores the same metadata object that is embedded in the SQL dump footer under `meta`.

Interactive import reads metadata from these metadata files to prevent downloading the whole dump file.
If a metadata file is missing, the dump can still be selected, and the import command will group it as an unknown connection.

### Export

To write a dump to the Protector storage folder:

```bash
php artisan protector:export
```

To write the dump to a different location, you can specify a custom file name and disk:

```bash
php artisan protector:export --file='custom_filename.sql' --disk='custom_disk'
```

For more information on the available options:

```bash
php artisan protector:export --help
```

You could also automate this by

- installing a cronjob on linux
- running it when you deploy to your server
- creating a Laravel Job and queueing it

### Import

To import a dump file interactively:

```bash
php artisan protector:import
```

To import a specific dump file (optionally on a specific disk):

```bash
php artisan protector:import --file='custom_filename.sql' --disk='custom_disk'
```

You could also automate this similar to the export command,
for this you want to use the `--force` option to bypass user interaction.

For example, to import the latest dump without interaction and migrate afterwards:

```bash
php artisan protector:import --latest --migrate --force
```

For more information on the available options:

```bash
php artisan protector:import --help
```

### Importing or downloading remote databases

To import a remote dump either run the command interactively or use the `--remote` option:

```bash
php artisan protector:import --remote
```

When used with other options, remote will serve as fallback behaviour.

> [!NOTE]
> Importing remote dumps will not leave any files on storage disk.

If you need to store the remote dump on the storage disk:

```bash
php artisan protector:download
```

To store and import in one step:

```bash
php artisan protector:download --import
```

To store the dump on a different disk with a specific file name:

```bash
php artisan protector:download --file='custom_filename.sql' --disk='custom_disk'
```

If you want to delete all files on the Protector storage disk except the newly stored dump, use the `--cleanup-storage` option.
The cleanup will only delete files with existing `.meta` files, and will not delete files in subdirectories of the storage disk.

```bash
php artisan protector:download --cleanup-storage
```

For more information on the available options:

```bash
php artisan protector:download --help
```

Like the import and export commands, a `--force` option is available to bypass user interaction, e.g. to automate this process.

## Configuration

### Protector instances

The Protector config files set initial settings for the `Protector` instance.

Generally, you should keep the `Protector` singleton instance as is.
To create a new instance with different settings, use the `ProtectorConfigurator` class.
For all available configuration options, take a look at the [ProtectorConfiguratorContract](src/Contracts/ProtectorConfiguratorContract.php).

For example, to configure a specific auth token and dump endpoint URL:

```php
$protector = ProtectorConfigurator::setAuthToken($authToken)->setDumpEndpointUrl($dumpEndpointUrl)->makeProtector();
```

### Disks

There are two disks, which use the `local` driver by default:

- [protector_local](config/filesystems/local.php) is used for temporary files which are deleted after use
    - writes to `storage/app/private/protector_local` by default
- [protector_storage](config/filesystems/storage.php) is used for storing dumps and their metadata files
    - writes to `storage/app/private/protector` by default

> [!IMPORTANT]
>
> The `protector_local` disk must be a local disk, as certain operations require a local filesystem, such as creating or importing a database dump.
>
> All operations go through the local disk by creating a local copy first. Some commands offer a --no-copy option to skip the local copy.

If you want to override the disk configuration, add the following to your `config/filesystems.php` file:

```php
'protector_local' => [
    ...
],

'protector_storage' => [
    ...
],
```

You could for example use S3 for the storage disk.

### Dump metadata

Customize the metadata appended to a dump by adding providers to the `metadata.providers` array in your `config/protector/dump.php` file:

```php
'providers' => [
    \Cybex\Protector\Classes\Metadata\Providers\EnvMetadataProvider::class,
    \Cybex\Protector\Classes\Metadata\Providers\GitMetadataProvider::class,
    \Path\To\Your\CustomMetadataProvider::class,
],
```

Available metadata providers:

1. `DatabaseMetadataProvider`: Will always be appended. Adds general information about the dump, such as the database connection and dumped at date.
2. `ProtectorMetadataProvider`: Adds information about the settings set on the Protector's config.
3. `EnvMetadataProvider`: Adds information based on an .env value. The default .env key used for this is `PROTECTOR_METADATA`.
4. `GitMetadataProvider`: Adds information about the Git repository, such as the current branch and revision.
5. `JsonMetadataProvider`: Adds information from a JSON file. The default file path used for this is `protector_metadata.json`.

> [!NOTE]
> You can create your own metadata providers by implementing the `Cybex\Protector\Contracts\MetadataProvider` interface.
> Duplicate provider keys will be merged in the final metadata array, so choose a unique key.

> [!TIP]
> An example of using the JsonMetadataProvider would be to add custom metadata from a CI/CD pipeline.
> For example, in a GitHub Actions workflow, you could add a step that writes Git information to `protector_metadata.json`
>
> ```bash
> - name: Protector Metadata
>   shell: bash
>   run: >
>     jq -n \
>       --arg repo ${{ github.repository }} \
>       --arg branch ${{ github.ref_name }} \
>       --arg revision ${{ github.sha }} \
>       --arg buildDate "$(date --iso-8601=seconds --utc)" \
>       '{gitRepo: $repo, gitBranch: $branch, gitRevision: $revision, buildDate: $buildDate}' > protector_metadata.json
> ```

### Cleanup of Protector disks

#### protector_local

Normally there should be no remnants of temporary files.
In case of unexpected errors, such as when the PHP process is killed, temporary files might remain on the disk.

The Protector will automatically delete these files on every operation involving the local disk.
Due to this running synchronously, performance might be impacted.

> [!NOTE]
> Only files older than 1 day will be deleted, to prevent deleting files that are currently being processed.

To run this asynchronously instead, you can set

```env
CLEANUP_LOCAL_DISK_MODE=schedule
```

This will schedule the deletion based on a cron expression defined with `CLEANUP_LOCAL_DISK_SCHEDULE`, which defaults to `0 0 * * *` (every day at midnight).

> [!NOTE]
> You need to run the [Laravel Scheduler](https://laravel.com/docs/master/scheduling#running-the-scheduler) for this.

To manually delete all temporary files older than 1 day on the `protector_local` disk:

```bash
php artisan protector:cleanup-local
```

#### protector_storage

Either use the dedicated command

```bash
php artisan protector:cleanup-storage
```

or cleanup when downloading a new dump

```bash
php artisan protector:download --cleanup-storage
```

## Development

There is an example app with the Laravel Protector package installed.

The file structure in the container is as follows:

- /var/www: example app
- /var/package: Protector package

```bash
docker compose up -d
```

```bash
docker compose exec app shell
```

Make sure to run composer install in both the example app and the package to install dependencies:

```bash
composer install
```

> [!NOTE]
> We disable composer security checking for this package, as vulnerabilities would block the development.
> The project requiring our package should be responsible for evaluating possible vulnerabilities.
> For more information, see the [composer documentation](https://getcomposer.org/doc/06-config.md#block-insecure).

Specific to the example app, for demo data:

```bash
php artisan migrate:fresh --seed
```

> [!NOTE]
> The example app uses the same database as the Unit tests, which might pollute the DB with data.
> For a reproducible environment, always run the above command before executing commands in the example app.

### Testing

Run tests on the MariaDB database:

```bash
composer test
```

Run tests on the PostgreSQL database:

```bash
composer test-postgres
```

Run tests on the MySQL database:

> [!NOTE]
> Running MySQL tests on the current alpine image will not work, as the MySQL CLI command is only an alias to mariadb and does not fully support the MySQL server.
>
> If you need to run MySQL tests, use a different image.
> To start up the mysql server, use `docker compose --profile mysql up -d`

```bash
composer test-mysql
```

To test scheduling functionalities, run the Laravel Scheduler:

```bash
php artisan schedule:work
```

#### Test coverage

To generate coverage, you need to run the tests from the package directory.

```bash
cd ../package
```

```bash
composer test
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

### Security

If you discover any security-related issues, please email webdevelopment@cybex-online.com instead of using the issue tracker.

## Credits

- [Web Development team at CYBEX GmbH - cybex-online.com](https://github.com/cybex-gmbh)
- [Gael Connan](https://github.com/gael-connan-cybex)
- [Jörn Heusinger](https://github.com/jheusinger)
- [Fabian Holy](https://github.com/holyfabi)
- [Oliver Matla](https://github.com/lupinitylabs)
- [Marco Szulik](https://github.com/mszulik)
- [All Contributors](https://github.com/cybex-gmbh/laravel-protector/graphs/contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
