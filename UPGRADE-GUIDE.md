# Upgrade Guide

## v3 to v4

- [Release Notes](CHANGELOG.md#v400---2026-xx-xx)
- [GitHub diff](https://github.com/cybex-gmbh/laravel-protector/compare/v3.2.1...v4.0.0)

> [!IMPORTANT]
> The `protector.php` config structure and keys have changed. The config file has been split into multiple files.
> If you have previously published the config file, you need to re-publish and adjust the configuration files accordingly.
>
> `.env` keys have changed, check the [ProtectorEnv](src/Enums/ProtectorEnv.php) enum for all keys.

### Overview

This upgrade guide will be split into three main sections targeting different user groups:

- General
- Usage only through commands
- Usage of the package through code (e.g., using the `Protector` facade)

#### Table of Contents

- [Overview](#overview)
    - [Table of Contents](#table-of-contents)
    - [Summary](#summary)
- [General](#general)
    - [Minimum PHP and Laravel version](#minimum-php-and-laravel-version)
    - [MySQL support dropped](#mysql-support-dropped)
    - [Renamed or removed config keys](#renamed-or-removed-config-keys)
    - [Disk handling](#disk-handling)
    - [Renamed or removed .env keys](#renamed-or-removed-env-keys)
- [Command Usage](#command-usage)
    - [php artisan protector:import](#php-artisan-protectorimport)
    - [php artisan protector:export](#php-artisan-protectorexport)
- [Code Usage](#code-usage)
    - [Protector configuration refactoring](#protector-configuration-refactoring)
    - [Renamed methods and changed signatures](#renamed-methods-and-changed-signatures)
    - [Removed methods](#removed-methods)
    - [Thrown exceptions](#thrown-exceptions)
    - [Protector::getMetaData()](#protectorgetmetadata)
    - [Protector::getDumpMetaData()](#protectorgetdumpmetadata)
    - [Protector::getLatestDumpName()](#protectorgetlatestdumpname)
    - [Setting .env key names during runtime](#setting-env-key-names-during-runtime)
    - [Protector dump endpoint route name](#protector-dump-endpoint-route-name)
    - [HasConfiguration trait](#hasconfiguration-trait)

#### Summary

System

- The minimum required PHP version is now 8.4.
- The minimum required Laravel version is now 12.1.1.
- MySQL is no longer officially supported.

Configuration

- Config keys and `.env` keys have been renamed and restructured.
- Disk handling has been extended and is now configured via the `filesystems.php` config file.
- `Protector` instances can no longer be reconfigured during runtime. Create new instances using the `ProtectorConfigurator` class.
- The Protector dump endpoint route name has been changed.
- To support config caching, .env key names can no longer be changed during runtime.
  If you previously relied on setting .env key names,
  you will now have to set the values directly instead.

Usage

- Some command options have been removed or have been renamed.
- Some methods throw different or more detailed exceptions.
- Some method names and signatures have changed.
- Some methods have been removed.
- Dump metadata has received a new structure.
  Importing legacy dumps with old metadata is still supported.
  However, if you have code that relies on the old metadata structure,
  you will need to adjust it to work with the new structure.
- The Protector will no longer create dumps in directories.

### General

#### Minimum PHP and Laravel version

> [!NOTE]
> Likelihood of impact: high
>
> Impact: Apps running on PHP versions below 8.4 and Laravel versions below 12.1.1
> will not be able to use this version of the package.

You need to update your system to PHP 8.4 or higher and Laravel 12.1.1 or higher,
as these are now the minimum required versions.

#### MySQL support dropped

> [!NOTE]
> Likelihood of impact: high
>
> Impact: MySQL is no longer supported and might break in the future.

Due to the lack of support for the mysql-client (it's aliasing to mariadb)
in recent Linux distribution versions, official MySQL support has been dropped.

We will no longer run dedicated tests for MySQL.
The package might still work with MySQL databases, but it might break in the future.

Migrate to MariaDB or PostgreSQL to continue receiving updates and support.

#### Renamed or removed config keys

> [!NOTE]
> Likelihood of impact: high
>
> Impact: App will crash, published `protector.php` config files will no longer work

The `protector.php` config file has been split into multiple files.
Config keys have been renamed or removed.
If you have previously published the config file,
you need to re-publish and adjust the configuration files accordingly.

| Old                                      | New                                          |
|------------------------------------------|----------------------------------------------|
| `protector.fileName`                     | `protector.dump.fileName`                    |
| `protector.baseDirectory`                | removed, see [disk handling](#disk-handling) |
| `protector.diskName`                     | removed, see [disk handling](#disk-handling) |
| `protector.maxPacketLength`              | `protector.dump.maxPacketLength`             |
| `protector.remoteEndpoint.serverUrl`     | `protector.client.dumpEndpointUrl`           |
| `protector.remoteEndpoint.htaccessLogin` | `protector.client.basicAuthCredentials`      |
| `protector.httpTimeout`                  | `protector.client.httpTimeout`               |
| `protector.dumpEndpointRoute`            | `protector.server.dumpEndpointRoute`         |
| `protector.routeMiddleware`              | `protector.server.routeMiddleware`           |
| `protector.chunkSize`                    | `protector.server.chunkSize`                 |

#### Disk handling

> [!NOTE]
> Likelihood of impact: high
>
> Impact: Published config files using old dump disk keys will fail.

The dump disk configuration now uses dedicated local and storage disks.

By default, the disks use the `local` driver and write to

- `storage/app/protector` for storing dumps
- `storage/app/protector_local` for local file handling

If you want to overwrite the default, you can add the following disks to your `config/filesystems.php`:

```php
'protector_local' => [
    ...
]

'protector_storage' => [
    ...
]
```

#### Renamed or removed .env keys

> [!NOTE]
> Likelihood of impact: high
>
> Impact: App may crash, old .env keys will be ignored

The .env keys have changed to be consistent with the config keys:

| Old                             | New                                          |
|---------------------------------|----------------------------------------------|
| `PROTECTOR_BASE_DIRECTORY`      | removed, see [disk handling](#disk-handling) |
| `PROTECTOR_DISK_NAME`           | removed, see [disk handling](#disk-handling) |
| `PROTECTOR_MAX_PACKET_LENGTH`   | `PROTECTOR_DUMP_MAX_PACKET_LENGTH`           |
| `PROTECTOR_AUTH_TOKEN`          | `PROTECTOR_CLIENT_AUTH_TOKEN`                |
| `PROTECTOR_PRIVATE_KEY`         | `PROTECTOR_CLIENT_PRIVATE_KEY`               |
| `PROTECTOR_SERVER_URL`          | `PROTECTOR_CLIENT_DUMP_ENDPOINT_URL`         |
| `PROTECTOR_HTTP_TIMEOUT`        | `PROTECTOR_CLIENT_HTTP_TIMEOUT`              |
| `PROTECTOR_DUMP_ENDPOINT_ROUTE` | `PROTECTOR_SERVER_DUMP_ENDPOINT_ROUTE`       |
| `PROTECTOR_CHUNK_SIZE`          | `PROTECTOR_SERVER_CHUNK_SIZE`                |

### Command Usage

#### php artisan protector:import

> [!NOTE]
> Likelihood of impact: high
>
> Impact: Command usage has changed and might behave different

- Dump files will no longer be retained after importing
    - If you want the old behaviour, use `php artisan protector:download --import` instead
- The `--dump` option has been removed
    - Use `--file` instead
- The `--file` option behaves differently
    - Absolute paths are no longer supported
    - It will now only accept file paths in the storage disk, or a disk passed with `--disk`
- The `--i|ignore-connection-filter` option has been removed
    - There is no replacement as of now. This is only relevant for interactive importing.
- The `--flush` option has been removed. The dump file will now always be deleted after importing
    - If you need the old behaviour, use `php artisan protector:download --import --cleanup-storage` instead
    - The new `--cleanup-storage` will only delete files which have a corresponding `.meta` file, and will not delete files inside directories
- The `--no-wipe` option has been renamed and can no longer be called with `-w`
    - Use `--no-wipe-db` instead

#### php artisan protector:export

> [!NOTE]
> Likelihood of impact: low
>
> Impact: Command usage has changed and might behave different

- The `--file` option no longer accepts directories
    - If you need to export a file into a specific directory, configure a disk which uses the directory as root

### Code Usage

#### Protector configuration refactoring

> [!NOTE]
> Likelihood of impact: high
>
> Impact: Calls to configuration methods on the `Protector` instance will fail.

The `Protector` class has been split into `Protector`, `ProtectorConfigurator` and `ProtectorConfig`.
Configuration methods that were previously available on the `Protector` instance are no longer accessible.

All methods of the `HasConfiguration` trait have been moved to `ProtectorConfig` and `ProtectorConfigurator`.
Some methods have been renamed:

- `withAuthToken()` -> `setAuthToken()`
- `withPrivateKey()` -> `setPrivateKey()`
- `withConnectionName()` -> `setConnectionName()`
- `withMaxPacketLength()` -> `setMaxPacketLength()`
- `withDumpEndpointUrl()` -> `setDumpEndpointUrl()`

`Protector` instances can no longer be reconfigured during runtime.
Create new instances using the `ProtectorConfigurator` class.

```php
ProtectorConfigurator::setAuthToken('my-auth-token')->makeProtector();
```

Additionally, these options can now be configured per-instance:

- Chunk Size
- Http Timeout
- Basic Auth Credentials

#### Renamed methods and changed signatures

> [!NOTE]
> Likelihood of impact: high
>
> Impact: Calls to renamed methods will fail

The following methods were renamed and might have changed signatures:

| Old                                         | Replacement                                                                  |
|---------------------------------------------|------------------------------------------------------------------------------|
| `Protector::construct()`                    | Changed signature, use `ProtectorConfigurator`                               |
| `Protector::createDump()`                   | `Protector::export()`, changed signature                                     |
| `Protector::generateFileDownloadResponse()` | Changed signature                                                            |
| `Protector::getDumpFiles()`                 | `Protector::dumpFiles()`                                                     |
| `Protector::getDumpMetaData`                | `Protector::dumpFilesWithMetadata()`, see [below](#protectorgetdumpmetadata) |
| `Protector::getLatestDumpName()`            | `Protector::latestDumpName()`, see [below](#protectorgetlatestdumpname)      |
| `Protector::getMetaData()`                  | `Protector::metadata()`, see [below](#protectorgetmetadata)                  |
| `Protector::getRemoteDump()`                | `Protector::download()`, changed signature                                   |
| `Protector::importDump()`                   | `Protector::import()`, changed signature                                     |

#### Removed methods

> [!NOTE]
> Likelihood of impact: low
>
> Impact: Calls to removed methods will fail

The following methods were removed:

- `Protector::createDestinationFilePath()`
- `Protector::createTempFilePath()`
- `Protector::decryptString()`
- `Protector::flush()`
- `Protector::getBaseDirectory()`
- `Protector::getDisk()`
- `Protector::isUnderGitVersionControl()`
- `Protector::prepareFileDownloadResponse()`

#### Thrown exceptions

> [!NOTE]
> Likelihood of impact: medium
>
> Impact: Exceptions might fail to be caught

Some methods now throw different or more detailed exceptions.

Please refer to the `@throws` section of method docblocks for details on which exceptions are thrown.

#### Protector::getMetaData()

> [!NOTE]
> Likelihood of impact: medium
>
> Impact: Calls to Protector::getMetaData() will fail

The method was renamed from `getMetaData()` to `metadata()`.

Calls to `Protector::metadata()` will no longer return a flat metadata array.
Instead, they will return a keyed array based on the configured [MetadataProviders](README.md#dump-metadata).

Previously, `getMetaData()` returned:

```php
[
    'connection' => ...
    ...
    'gitRevision' => ...,
    'gitBranch' => ...,
    'gitRevisionDate' => ...,
]
```

Now, `metadata()` returns (assuming the default configuration is used and the project is a git repository):

```php
[
    'database' => [
        'connection' => ...,
        ...
    ],
    'git' => [
        'revision' => ...,
        'branch' => ...,
        'revisionDate' => ...,
    ],
]
```

#### Protector::getDumpMetaData()

> [!NOTE]
> Likelihood of impact: low
>
> Impact: Calls to Protector::getDumpMetaData() will fail

The method was removed.
Use `Protector::dumpFilesWithMetadata()` instead, which returns an array of dump files with their corresponding metadata.

This makes use of `.meta` files to prevent downloading whole dumps just to access the metadata.

#### Protector::getLatestDumpName()

> [!NOTE]
> Likelihood of impact: low
>
> Impact: Renamed, error handling based on the `FileNotFoundException` will no longer work

The method has been renamed from `getLatestDumpName()` to `latestDumpName()`.

The method now throws an `EmptyDumpDirectoryException` instead of a `FileNotFoundException` when no dumps are found in the base directory.

#### Setting .env key names during runtime

> [!NOTE]
> Likelihood of impact: low
>
> Impact: Calls to `Protector::withAuthTokenKeyName()` and `Protector::withPrivateKeyName()` will fail

This feature has been removed due to issues with config caching.
Calls to `env()` will return `null` when the config was cached using `php artisan config:cache`, `php artisan optimize` or similar.

Therefore, the following methods are no longer available:

- `Protector::withAuthTokenKeyName()`
- `Protector::withPrivateKeyName()`

If you need to set the values for the auth token or the private key during runtime,
use the following methods on the `ProtectorConfigurator` instead:

- `setPrivateKey()`
- `setAuthToken()`

#### Protector dump endpoint route name

> [!NOTE]
> Likelihood of impact: low
>
> Impact: Using the route name for calls like `route('protectorDumpEndpointRoute')` will fail

The route name has been changed to `protector.server.dump` to align it with the overall naming scheme and allow wildcard addressing.

#### HasConfiguration trait

> [!NOTE]
> Likelihood of impact: low
>
> Impact: Trait is no longer available, classes using this trait will fail to work

The `HasConfiguration` trait has been removed. Its functionality is now integrated directly into the `ProtectorConfig` and `ProtectorConfigurator` classes.

---

## v2 to v3

- [Release Notes](CHANGELOG.md#v300---2024-03-15)
- [GitHub diff](https://github.com/cybex-gmbh/laravel-protector/compare/v2.0.0...v3.0.0)

No breaking changes are expected.

---

## v1 to v2

- [Release Notes](CHANGELOG.md#v200---2023-02-23)
- [GitHub diff](https://github.com/cybex-gmbh/laravel-protector/compare/v1.5.0...v2.0.0)

Likelihood of impact: high

- If your app does not explicitly require the laravel/sanctum package, upgrading Protector to version 2.x will also upgrade Sanctum to version 3.x.
  This will require you to follow its [upgrade guide](https://github.com/laravel/sanctum/blob/3.x/UPGRADE.md).

Likelihood of impact: low

- Access to the formerly public methods `getGitRevision()`, `getGitHeadDate()` or `getGitBranch()` is now protected.
  You now need to call getMetaData() and extract the information from the returned array.
