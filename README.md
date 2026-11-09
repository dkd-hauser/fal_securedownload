# FAL Secure Download

This TYPO3 extension (fal_securedownload) aims to be a general solution to
secure your assets.

When your storage is marked as non-public all links to files from that storage
are replaced (also for processed files).

The access to assets can be set on folder/file basis by setting access to
fe_groups in the file module.

## How to use

1. Download and install fal_securedownload
2. Un-check the 'public' checkbox in your file storage
3. Add a .htaccess file with "Require all denied" in your file storage root
   folder or move your storage outside your webroot
4. Go to the file list and add access restrictions on file/folder

## Features

- Restrict FE access on folder level
- Restrict FE access on file level
- Let editor set permissions in file list
- Force download for all files (for protected file storages)
- Force download for specific file extensions (for protected file storages)
- Keep track of requested downloads (count downloads per user and file)

## Requirements

- TYPO3 14.3 LTS
- PHP 8.2 or higher

## Tests

The unit tests use the TYPO3 testing framework. Dependencies are installed to
`.Build/`:

```bash
composer install
composer ci:php:lint
composer ci:php:stan
composer ci:tests:unit
```

`composer ci:tests:unit` runs
`.Build/bin/phpunit -c Build/phpunit/UnitTests.xml`. The same checks run on
GitHub Actions for PHP 8.2 to 8.5 (see `.github/workflows/ci.yml`). PHPStan
runs on level 3 with the configuration in `Build/phpstan/phpstan.neon`.
