[![build status](https://github.com/vielhuber/searchhelper/actions/workflows/ci.yml/badge.svg)](https://github.com/vielhuber/searchhelper/actions)
[![GitHub Tag](https://img.shields.io/github/v/tag/vielhuber/searchhelper)](https://github.com/vielhuber/searchhelper/tags)
[![Code Style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![License](https://img.shields.io/github/license/vielhuber/searchhelper)](https://github.com/vielhuber/searchhelper/blob/main/LICENSE.md)
[![Last Commit](https://img.shields.io/github/last-commit/vielhuber/searchhelper)](https://github.com/vielhuber/searchhelper/commits)
[![PHP Version Support](https://img.shields.io/packagist/php-v/vielhuber/searchhelper)](https://packagist.org/packages/vielhuber/searchhelper)
[![Packagist Downloads](https://img.shields.io/packagist/dt/vielhuber/searchhelper)](https://packagist.org/packages/vielhuber/searchhelper)

# 🔎 searchhelper 🔎

searchhelper is a small PHP helper and MCP server for indexed file search.

it wraps fast host indexes instead of recursively scanning slow mounts:

- **Everything** on Windows via its HTTP server
- **plocate** on Linux via CLI

## installation

install once with [composer](https://getcomposer.org):

```bash
composer require vielhuber/searchhelper
```

then add this to your files:

```php
require __DIR__ . '/vendor/autoload.php';
use vielhuber\searchhelper\searchhelper;
```

## setup

searchhelper reads configuration from the `.env` in your project root.

```dotenv
SEARCHHELPER_ROOTS=/host/data/documents,/host/data/projects
SEARCHHELPER_ENGINES=everything,plocate
SEARCHHELPER_EVERYTHING_URL=http://host.docker.internal:8081
SEARCHHELPER_EVERYTHING_USERNAME=
SEARCHHELPER_EVERYTHING_PASSWORD=
SEARCHHELPER_PATH_MAPPINGS=C:\Data=>/host/data
MCP_TOKEN=
```

`SEARCHHELPER_ROOTS` is the final search allowlist. Everything and plocate may index more files, but searchhelper only returns results below these roots. use focused roots like `/host/data/documents`.

`SEARCHHELPER_PATH_MAPPINGS` is only needed for Everything. Everything returns Windows paths, while code running in Docker/WSL usually needs Linux paths.

## usage

```php
$search = searchhelper::create();

$result = $search->searchFiles(
    query: 'project notes',
    root: '/host/data/documents',
    limit: 10
);

print_r($result['items']);
```

## everything

1. install [Everything](https://www.voidtools.com).
2. settings > `HTTP-Server` > enable, Port: `8081`
3. set `SEARCHHELPER_EVERYTHING_URL=http://host.docker.internal:8081` when searchhelper runs in Docker.
4. set `SEARCHHELPER_PATH_MAPPINGS` so Windows result paths can be converted to mounted Linux paths.

when Everything returns Windows paths, `SEARCHHELPER_PATH_MAPPINGS` maps them back into the container. example:

```dotenv
SEARCHHELPER_PATH_MAPPINGS=C:\Data=>/host/data
```

then a result like `C:\Data\documents\file.pdf` becomes `/host/data/documents/file.pdf`.

## plocate

`plocate` is mainly useful when searchhelper runs directly on the Linux system that owns the index, for example in WSL. for Docker setups with slow Windows/OneDrive mounts, prefer Everything on the Windows host.

short setup in WSL/Linux:

```bash
apt-get update
apt-get install -y plocate
updatedb
```

searchhelper executes `plocate` where PHP runs. if the MCP server runs directly in WSL, WSL's `plocate` is used. installing `plocate` inside Docker only helps when the relevant files are on fast container-local or Linux-native mounts.

## mcp server

searchhelper ships as a standalone MCP server:

```bash
vendor/bin/mcp-server.php
```

available tools:

- `search_files(query, root?, limit?, engine?)`
- `status()`

## tests

```bash
vendor/bin/phpunit
```
