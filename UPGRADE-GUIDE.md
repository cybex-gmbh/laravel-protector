# Upgrade Guide

## v3 to v4

- [Release Notes](CHANGELOG.md#v400---2026-xx-xx)
- [GitHub diff](https://github.com/cybex-gmbh/laravel-protector/compare/v3.2.1...v4.0.0)

Dump metadata has received a new structure. Legacy dumps with old metadata are still supported. However, if you have code that relies on the old metadata structure, you will need
to adjust it to work with the new structure. See below for details.

### Protector dump metadata

> [!NOTE]
> Impact: Dump metadata may be wrong

Dump metadata can now be configured using metadata providers in the config.

The implementation expects the `Protector` to be used as a singleton (which was always intended). It makes use of the Laravel Service Container to inject the `Protector` instance.

If you have code which uses the `Protector` without using the Service Container or the Facade, you will need to adjust your code.

For example, instead of using `new Protector()`, you should be using either of the following options:

- `app(Protector::class)`
- `app('protector')`
- `Protector` facade.

### Protector::getMetaData()

> [!NOTE]
> Impact: Calls to Protector::getMetaData() will fail

The method was renamed from `getMetaData()` to `getMetadata()`.

Calls to `Protector::getMetadata()` will no longer return a flat metadata array. Instead, they will return a keyed array based on the configured MetadataProviders.

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
> Impact: Calls to Protector::getDumpMetaData() will fail

The method was renamed from `getDumpMetaData()` to `getDumpMetadata()`.

For new dumps, the returned array will no longer contain the `options` key.
Dump parameters are now part of the metadata, accessible under the `meta.database.dumpParameters` key.
Legacy dumps will still contain the `options` key.

### Protector::isUnderGitVersionControl()

> [!NOTE]
> Impact: Calls to Protector::isUnderGitVersionControl() will fail

The method is no longer available.

---

## v2 to v3

- [Release Notes](CHANGELOG.md#v300---2024-03-15)
- [GitHub diff](https://github.com/cybex-gmbh/laravel-protector/compare/v2.0.0...v3.0.0)

No breaking changes are expected.

---

## v1 to v2

- [Release Notes](CHANGELOG.md#v040)
- [GitHub diff](https://github.com/cybex-gmbh/laravel-protector/compare/v1.5.0...v2.0.0)

Likelihood of impact: high

- If your app does not explicitly require the laravel/sanctum package, upgrading Protector to version 2.x will also
  upgrade Sanctum to version 3.x. This will require you to follow its
  [upgrade guide](https://github.com/laravel/sanctum/blob/3.x/UPGRADE.md).

Likelihood of impact: low

- Access to the formerly public methods `getGitRevision()`, `getGitHeadDate()` or `getGitBranch()` is now protected.
  You now need to call getMetaData() and extract the information from the returned array.
