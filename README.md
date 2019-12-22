# Composerize WordPress

_Composerize WordPress_ is a Composer plugin that converts a non-Composer-managed WordPress application (e.g., one created via tarball) to a Composer-managed WordPress application. It is based on [Composerize Drupal](https://github.com/grasmash/composerize-drupal) by [Grasmash](https://github.com/grasmash/).

It is not for creating new WordPress applications.

## Functionality

The `composerize-wordpress` command will perform the following operations:

* Remove all `composer.json` and `composer.lock` files, should they exist
* Generate a new `composer.json` in the `[composer-root]` directory based on [template.composer.json](template.composer.json).
    * Populate `require` with an entry for `pantheon-systems/wordpress-composer`
    * Populate `require` with an entry for each project in:
        * `[core-root]/wp-content/plugins`
        * `[core-root]/wp-content/themes`
* Create or modify `[composer-root]/.gitignore` with entries for Composer-managed contributed projects as [per best practices](https://getcomposer.org/doc/faqs/should-i-commit-the-dependencies-in-my-vendor-directory.md). You can modify `.gitignore` after composerization if you'd prefer not to follow this practice.
* Execute `composer update` to generate `composer.lock`, autoload files, and install all dependencies in the correct locations.

It will NOT add any contributed projects in `docroot/libraries` to `composer.json`. You must add those to your `composer.json` file manually. In addition to [packagist](https://packagist.org/) and Wpackagist.org packages, you may also use any package from [asset packagist](https://asset-packagist.org/), which makes NPM packages available to Composer.

## Installation

```
composer global require rvtraveller/composerize-wordpress
```

## Usage:
```
cd path/to/wordpress/project/repo
composer composerize-wordpress --composer-root=[repo-root] --core-root=[core-root]
```

The `[composer-root]` should be the root directory of your project, where `.git` is located.

The `[core-root]` should be the WordPress root, where `wp-load.php` is located.

Examples:
```
# WordPress is located in a `docroot` subdirectory.
composer composerize-wordpress --composer-root=. --core-root=./docroot

# WordPress is located in a `web` subdirectory.
composer composerize-wordpress --composer-root=. --core-root=./web

# WordPress is located in a `public_html` subdirectory (cPanel compatible).
composer composerize-wordpress --composer-root=. --core-root=./public_html

# WordPress is located in the repository root, not in a subdirectory.
composer composerize-wordpress --composer-root=. --core-root=.
```

## Options

* `--composer-root`: Specifies the root directory of your project where `composer.json` will be generated. This should be the root of your Git repository, where `.git` is located.
* `--core-root`: Specifies the WordPress root directory where `wp-load.php` is located.
* `--no-update`: Prevents `composer update` from being automatically run after `composer.json` is generated.
* `--no-gitignore`: Prevents modification of the root .gitignore file. 
* `--exact-versions`: Will cause WordPress core and contributed projects (plugins, themes) to be be required with exact verions constraints in `composer.json`, rather than using the default caret operator. E.g., a `wordpress/core` would be required as `5.0` rather than `^5.0`. This prevents projects from being updated. It is not recommended as a long-term solution, but may help you convert to using Composer more easily by reducing the size of the change to your project.
