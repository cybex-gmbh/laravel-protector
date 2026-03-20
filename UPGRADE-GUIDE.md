# Upgrade Guide

## v3 to v4

- [Release Notes](CHANGELOG.md#v400---2026-xx-xx)
- [GitHub diff](https://github.com/cybex-gmbh/laravel-protector/compare/v3.2.1...v4.0.0)

#### Overview (see below for details):

- The minimum required PHP version is now 8.2
- Some config and .env keys have been renamed.
- The `Protector` class has been refactored to separate configuration into a new `ProtectorConfig` class.
  Most configuration-related methods have been moved from `Protector` to `ProtectorConfig`.
- The Protector dump endpoint route name has been changed.
- Dump metadata has received a new structure.
  Legacy dumps with old metadata are still supported.
  However, if you have code that relies on the old metadata structure,
  you will need to adjust it to work with the new structure.
- To support config caching, .env key names can no longer be changed during runtime.
  If you previously relied on setting .env key names,
  you will now have to set the values directly instead.
- Some functions throw different or more detailed exceptions.
- The `protector:import` command no longer supports the `--dump` option.

> [!IMPORTANT]
> The `protector.php` config structure and keys have changed.
> If you have previously published the config file, you should re-publish it and adjust the configuration accordingly.

### Minimum PHP version

> [!NOTE]
> Likelihood of impact: high
>
> Impact: Apps running on PHP versions below 8.2 will not be able to use this version of the package.

You need to update your system to PHP 8.2 or higher, as it is now the minimum required version.

### Renamed config keys

> [!NOTE]
> Likelihood of impact: high
>
> Impact: App may crash, published `protector.php` config files will no longer work

Some config keys have been renamed.
If you have previously published the config file,
you should re-publish it and adjust the configuration accordingly.

| Old                                      | New                                     |
|------------------------------------------|-----------------------------------------|
| `protector.fileName`                     | `protector.dump.fileName`               |
| `protector.baseDirectory`                | `protector.dump.baseDirectory`          |
| `protector.diskName`                     | `protector.dump.diskName`               |
| `protector.maxPacketLength`              | `protector.dump.maxPacketLength`        |
| `protector.remoteEndpoint.serverUrl`     | `protector.client.dumpEndpointUrl`      |
| `protector.remoteEndpoint.htaccessLogin` | `protector.client.basicAuthCredentials` |
| `protector.httpTimeout`                  | `protector.client.httpTimeout`          |
| `protector.dumpEndpointRoute`            | `protector.server.dumpEndpointRoute`    |
| `protector.routeMiddleware`              | `protector.server.routeMiddleware`      |
| `protector.chunkSize`                    | `protector.server.chunkSize`            |

### Renamed .env keys

> [!NOTE]
> Likelihood of impact: high
>
> Impact: App may crash, old .env keys will be ignored

The .env keys have changed to be consistent with the config keys:

| Old                             | New                                    |
|---------------------------------|----------------------------------------|
| `PROTECTOR_BASE_DIRECTORY`      | `PROTECTOR_DUMP_BASE_DIRECTORY`        |
| `PROTECTOR_DISK_NAME`           | `PROTECTOR_DUMP_DISK_NAME`             |
| `PROTECTOR_MAX_PACKET_LENGTH`   | `PROTECTOR_DUMP_MAX_PACKET_LENGTH`     |
| `PROTECTOR_AUTH_TOKEN`          | `PROTECTOR_CLIENT_AUTH_TOKEN`          |
| `PROTECTOR_PRIVATE_KEY`         | `PROTECTOR_CLIENT_PRIVATE_KEY`         |
| `PROTECTOR_SERVER_URL`          | `PROTECTOR_CLIENT_DUMP_ENDPOINT_URL`   |
| `PROTECTOR_HTTP_TIMEOUT`        | `PROTECTOR_CLIENT_HTTP_TIMEOUT`        |
| `PROTECTOR_DUMP_ENDPOINT_ROUTE` | `PROTECTOR_SERVER_DUMP_ENDPOINT_ROUTE` |
| `PROTECTOR_CHUNK_SIZE`          | `PROTECTOR_SERVER_CHUNK_SIZE`          |

### Protector configuration refactoring

> [!NOTE]
> Likelihood of impact: high
>
> Impact: Direct calls to configuration methods on the `Protector` instance will fail.

The `Protector` class has been split into `Protector` and `ProtectorConfig`.
Methods that were previously available on the `Protector` instance are now accessible through `Protector::getConfig()`.

The following methods have been moved to `ProtectorConfig`:

- `withAuthToken()`
- `withPrivateKey()`
- `withConnectionName()`
- `getDisk()`
- `getBaseDirectory()`
- `getDatabaseName()`
- `getDumpEndpointUrl()`
- `shouldEncrypt()`
- `getSchemaStateParameters()`
- `getProxyForSchemaState()`

If you were previously calling these methods on a `Protector` instance, you should now call them on the result of `getConfig()`:

```php
// Old
Protector::withAuthToken($token);

// New
Protector::getConfig()->withAuthToken($token);
```

### Protector dump endpoint route name

> [!NOTE]
> Likelihood of impact: low
>
> Impact: Using the route name for calls like `route('protectorDumpEndpointRoute')` will fail

The route name has been changed to `protector.server.dump` to align it with the overall naming scheme and allow wildcard addressing.

### Protector::getMetaData()

> [!NOTE]
> Likelihood of impact: low
>
> Impact: Calls to Protector::getMetaData() will fail

The method was renamed from `getMetaData()` to `getMetadata()`.

Calls to `Protector::getMetadata()` will no longer return a flat metadata array.
Instead, they will return a keyed array based on the configured MetadataProviders.

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

Now, `getMetadata()` returns (assuming the default configuration is used and the project is a git repository):

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

### Protector::getDumpMetaData()

> [!NOTE]
> Likelihood of impact: low
>
> Impact: Calls to Protector::getDumpMetaData() will fail

The method was renamed from `getDumpMetaData()` to `getDumpMetadata()`.

For new dumps, the returned array will no longer contain the `options` key.
Dump parameters are now part of the metadata, accessible under the `meta.database.dumpParameters` key.
Legacy dumps will still contain the `options` key.

### Protector::isUnderGitVersionControl()

> [!NOTE]
> Likelihood of impact: low
>
> Impact: Calls to Protector::isUnderGitVersionControl() will fail

The method is no longer available.

### Setting .env key names during runtime

> [!NOTE]
> Likelihood of impact: low
>
> Impact: Calls to `Protector::withAuthTokenKeyName()` and `Protector::withPrivateKeyName()` will fail

This feature has been removed due to issues with config caching.
Calls to `env()` will return `null` when the config was cached
using `php artisan config:cache`, `php artisan optimize` or similar.

Therefore, the following methods are no longer available:

- Protector::withAuthTokenKeyName()
- Protector::withPrivateKeyName()

If you need to set the values for the auth token or the private key during runtime,
use the following methods instead:

- Protector::withPrivateKey()
- Protector::withAuthToken()

### Protector::getLatestDumpName()

> [!NOTE]
> Likelihood of impact: low
>
> Impact: Error handling based on the `FileNotFoundException` will no longer work

The method now throws a `EmptyDumpDirectoryException` instead of a `FileNotFoundException` when no dumps are found in the base directory.

### protector:import command

> [!NOTE]
> Likelihood of impact: low
>
> Impact: Command calls using the `--dump` option will fail

The `protector:import` command no longer supports the `--dump` option. The `--file` option now accepts both a relative and an absolute path.

### Protector::createDestinationFilePath()

> [!NOTE]
> Likelihood of impact: low
>
> Impact: Calls to `Protector::createDestinationFilePath()` will fail

The `createDestinationFilePath()` method has been removed from the `Protector` class as it was redundant and intended for internal use only.

### HasConfiguration trait

> [!NOTE]
> Likelihood of impact: low
>
> Impact: Trait is no longer available, classes using this trait will fail to work

The `HasConfiguration` trait has been removed. Its functionality is now integrated directly into the `ProtectorConfig` class. If you were using this trait in your own classes, you
will need to refactor them to use `ProtectorConfig` or implement similar logic.

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

- If your app does not explicitly require the laravel/sanctum package, upgrading Protector to version 2.x will also
  upgrade Sanctum to version 3.x. This will require you to follow its
  [upgrade guide](https://github.com/laravel/sanctum/blob/3.x/UPGRADE.md).

Likelihood of impact: low

- Access to the formerly public methods `getGitRevision()`, `getGitHeadDate()` or `getGitBranch()` is now protected.
  You now need to call getMetaData() and extract the information from the returned array.
