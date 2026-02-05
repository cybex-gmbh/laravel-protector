# Example App

This is a Laravel 12 Scaffold.

Adjustments:

- Added the `cybex/laravel-protector` package using symlink (see `composer.json` config below).
  - The User model has been updated to use the `HasApiTokens` trait.
  - The .env file has been configured with the necessary `Protector` settings (`PROTECTOR_SERVER_URL`, `PROTECTOR_AUTH_TOKEN`, `PROTECTOR_PRIVATE_KEY`).
  - The Laravel Sanctum migrations have been published.
  - The package migrations have been published.
- The DatabaseSeeder has been updated to provide a user with a `Protector Public Key` and a `Protector Auth Token`, matching the provided .env values.
- The package's test database is used as the default database for this scaffold.

The app is able to execute all package-related commands, including "downloading" a dump from a "remote" server (the `Protector Server URL` points to localhost).

## Composer Configuration

```json
{
    ...
    "require": {
        ...
        "cybex/laravel-protector": "@dev",
        ...
    },
    ...
    "repositories": [
        {
            "type": "path",
            "url": "../package"
        }
    ],
    ...
}
```
