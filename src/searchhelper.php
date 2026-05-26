<?php
declare(strict_types=1);

namespace vielhuber\searchhelper;

use RuntimeException;
use Throwable;
use vielhuber\simplemcp\Attributes\McpTool;

final class searchhelper
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->loadEnvFile();
        $this->config = $config ?? $this->envConfig();
    }

    public static function create(?array $config = null): self
    {
        return new self(config: $config);
    }

    #[McpTool(name: 'search_files')]
    public function searchFilesTool(string $query, ?string $root = null, ?int $limit = null, ?string $engine = null): array
    {
        return $this->searchFiles(query: $query, root: $root, limit: $limit ?? 100, engine: $engine);
    }

    #[McpTool(name: 'status')]
    public function statusTool(): array
    {
        return $this->status();
    }

    public function searchFiles(string $query, ?string $root = null, int $limit = 100, ?string $engine = null): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new RuntimeException('searchhelper: query must not be empty.');
        }

        $limit = $this->normalizeLimit($limit);
        $roots = $this->resolveRoots($root);
        $engines = $engine === null || $engine === '' ? $this->config['engines'] : [$engine];
        $started = microtime(true);
        $errors = [];

        foreach ($engines as $engine_name) {
            $engine_name = strtolower(trim((string) $engine_name));
            if ($engine_name === '') {
                continue;
            }

            $engine_started = microtime(true);
            try {
                $items = match ($engine_name) {
                    'everything' => $this->searchEverything(query: $query, roots: $roots, limit: $limit),
                    'plocate' => $this->searchPlocate(query: $query, roots: $roots, limit: $limit),
                    default => throw new RuntimeException('Unknown search engine: ' . $engine_name)
                };
                if ($items !== []) {
                    return [
                        'engine' => $engine_name,
                        'items' => array_slice($items, 0, $limit),
                        'count' => min(count($items), $limit),
                        'elapsed_ms' => $this->elapsedMs($started),
                        'engine_elapsed_ms' => $this->elapsedMs($engine_started),
                        'errors' => $errors
                    ];
                }
            } catch (Throwable $exception) {
                $errors[$engine_name] = $exception->getMessage();
            }
        }

        return [
            'engine' => null,
            'items' => [],
            'count' => 0,
            'elapsed_ms' => $this->elapsedMs($started),
            'errors' => $errors
        ];
    }

    public function status(): array
    {
        return [
            'roots' => $this->config['roots'],
            'engines' => [
                'everything' => [
                    'configured' => $this->config['everything_url'] !== '',
                    'url' => $this->config['everything_url']
                ],
                'plocate' => [
                    'configured' => $this->commandExists('plocate'),
                    'command' => 'plocate'
                ]
            ],
            'path_mappings' => $this->config['path_mappings']
        ];
    }

    private function searchEverything(string $query, array $roots, int $limit): array
    {
        if ($this->config['everything_url'] === '') {
            throw new RuntimeException('Everything URL is not configured.');
        }

        $items = [];
        foreach ($roots as $root) {
            $everything_query = $query;
            $windows_root = $this->containerPathToWindows($root);
            if ($windows_root !== null) {
                $everything_query = 'parent:"' . $windows_root . '" ' . $everything_query;
            }

            $url = rtrim($this->config['everything_url'], '/') . '/?' . http_build_query([
                'search' => $everything_query,
                'json' => 1,
                'path_column' => 1,
                'size_column' => 1,
                'date_modified_column' => 1,
                'count' => $limit
            ]);
            $json = $this->httpGet($url);
            $data = json_decode($json, true);
            if (!is_array($data)) {
                throw new RuntimeException('Everything returned invalid JSON.');
            }

            foreach (($data['results'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $path = $this->everythingRowPath($row);
                if ($path === null) {
                    continue;
                }
                $container_path = $this->windowsPathToContainer($path) ?? $path;
                if (!$this->isWithinAnyRoot($container_path, $roots)) {
                    continue;
                }
                $items[$container_path] = $this->resultItem($container_path, 'everything', $query, [
                    'windows_path' => $path,
                    'size' => isset($row['size']) ? (int) $row['size'] : null,
                    'modified_at' => isset($row['date_modified']) ? (int) $row['date_modified'] : null
                ]);
            }
        }

        return $this->sortResults(array_values($items));
    }

    private function searchPlocate(string $query, array $roots, int $limit): array
    {
        if (!$this->commandExists('plocate')) {
            throw new RuntimeException('plocate command not found.');
        }

        $items = [];
        foreach ($this->tokens($query) as $token) {
            $command = [
                'timeout',
                (string) $this->config['command_timeout'],
                'plocate',
                '--ignore-case',
                '--limit',
                (string) max($limit * 10, 100),
            ];
            $command[] = $token;

            foreach ($this->runCommand($command) as $line) {
                $path = trim($line);
                if ($path === '' || !$this->isWithinAnyRoot($path, $roots)) {
                    continue;
                }
                $items[$path] = $this->resultItem($path, 'plocate', $query);
            }
        }

        return array_slice($this->sortResults(array_values($items)), 0, $limit);
    }

    private function resultItem(string $path, string $engine, string $query, array $extra = []): array
    {
        $item = [
            'path' => $path,
            'name' => basename($path),
            'directory' => dirname($path),
            'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'engine' => $engine,
            'score' => $this->scorePath($path, $query)
        ];

        return array_filter($item + $extra, fn(mixed $value): bool => $value !== null);
    }

    private function scorePath(string $path, string $query): float
    {
        $path_normalized = $this->normalizeText($path);
        $name_normalized = $this->normalizeText(basename($path));
        $score = 0.0;

        foreach ($this->tokens($query) as $token) {
            $token = $this->normalizeText($token);
            if ($token === '') {
                continue;
            }
            if ($name_normalized === $token) {
                $score += 8;
                continue;
            }
            if (str_contains($name_normalized, $token)) {
                $score += 5;
                continue;
            }
            if (str_contains($path_normalized, $token)) {
                $score += 2;
            }
        }

        return $score;
    }

    private function sortResults(array $items): array
    {
        usort($items, function (array $a, array $b): int {
            $score = ($b['score'] <=> $a['score']);
            if ($score !== 0) {
                return $score;
            }
            return strcmp((string) $a['path'], (string) $b['path']);
        });
        return $items;
    }

    private function resolveRoots(?string $root): array
    {
        if ($root === null || trim($root) === '') {
            return $this->config['roots'];
        }

        $root = rtrim(trim($root), '/');
        if ($root === '') {
            throw new RuntimeException('searchhelper: root must not be empty.');
        }

        foreach ($this->config['roots'] as $allowed_root) {
            if ($root === $allowed_root || str_starts_with($root . '/', rtrim($allowed_root, '/') . '/')) {
                return [$root];
            }
        }

        throw new RuntimeException('searchhelper: root is outside allowed roots: ' . $root);
    }

    private function isWithinAnyRoot(string $path, array $roots): bool
    {
        $path = str_replace('\\', '/', $path);
        foreach ($roots as $root) {
            $root = rtrim(str_replace('\\', '/', $root), '/');
            if ($path === $root || str_starts_with($path, $root . '/')) {
                return true;
            }
        }
        return false;
    }

    private function everythingRowPath(array $row): ?string
    {
        $name = (string) ($row['name'] ?? '');
        $path = (string) ($row['path'] ?? '');
        if (($row['full_path'] ?? '') !== '') {
            return (string) $row['full_path'];
        }
        if ($path === '') {
            return $name === '' ? null : $name;
        }
        if ($name === '') {
            return $path;
        }
        return rtrim($path, '\\/') . '\\' . $name;
    }

    private function windowsPathToContainer(string $path): ?string
    {
        foreach ($this->config['path_mappings'] as $windows => $container) {
            if (stripos($path, $windows) === 0) {
                return rtrim($container, '/') . '/' . ltrim(str_replace('\\', '/', substr($path, strlen($windows))), '/');
            }
        }
        return null;
    }

    private function containerPathToWindows(string $path): ?string
    {
        foreach ($this->config['path_mappings'] as $windows => $container) {
            $container = rtrim($container, '/');
            if ($path === $container || str_starts_with($path, $container . '/')) {
                return rtrim($windows, '\\/') . '\\' . str_replace('/', '\\', ltrim(substr($path, strlen($container)), '/'));
            }
        }
        return null;
    }

    private function httpGet(string $url): string
    {
        $headers = [];
        if ($this->config['everything_username'] !== '' || $this->config['everything_password'] !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->config['everything_username'] . ':' . $this->config['everything_password']);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->config['command_timeout'],
                'header' => implode("\r\n", $headers)
            ]
        ]);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            throw new RuntimeException('HTTP request failed: ' . $url);
        }
        return $result;
    }

    private function runCommand(array $command): array
    {
        $escaped = implode(' ', array_map('escapeshellarg', $command));
        $output = [];
        $exit_code = 0;
        exec($escaped . ' 2>/dev/null', $output, $exit_code);
        if ($exit_code !== 0 && $exit_code !== 1) {
            throw new RuntimeException('Command failed with exit code ' . $exit_code . ': ' . implode(' ', $command));
        }
        return $output;
    }

    private function commandExists(string $command): bool
    {
        if ($command === '') {
            return false;
        }
        if (str_contains($command, '/') && is_executable($command)) {
            return true;
        }
        $output = [];
        $exit_code = 0;
        exec('command -v ' . escapeshellarg($command) . ' >/dev/null 2>&1', $output, $exit_code);
        return $exit_code === 0;
    }

    private function envConfig(): array
    {
        return [
            'roots' => $this->stringList($this->env('SEARCHHELPER_ROOTS', '/host/data')),
            'engines' => $this->stringList($this->env('SEARCHHELPER_ENGINES', 'everything,plocate')),
            'everything_url' => rtrim($this->env('SEARCHHELPER_EVERYTHING_URL', ''), '/'),
            'everything_username' => $this->env('SEARCHHELPER_EVERYTHING_USERNAME', ''),
            'everything_password' => $this->env('SEARCHHELPER_EVERYTHING_PASSWORD', ''),
            'path_mappings' => $this->parsePathMappings($this->env('SEARCHHELPER_PATH_MAPPINGS', 'C:\\Data=>/host/data')),
            'command_timeout' => max(1, (int) $this->env('SEARCHHELPER_COMMAND_TIMEOUT', '10'))
        ];
    }

    private function loadEnvFile(): void
    {
        $cwd = getcwd();
        $paths = array_unique(array_filter([
            is_string($cwd) ? $cwd . '/.env' : null,
            dirname(__DIR__) . '/.env'
        ]));

        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                if ($key !== '' && getenv($key) === false) {
                    putenv($key . '=' . $value);
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }
    }

    private function env(string $key, string $default): string
    {
        $value = getenv($key);
        return $value === false ? $default : (string) $value;
    }

    private function stringList(string $value): array
    {
        return array_values(array_filter(array_map(
            fn(string $item): string => rtrim(trim($item), '/'),
            explode(',', $value)
        ), fn(string $item): bool => $item !== ''));
    }

    private function parsePathMappings(string $value): array
    {
        $items = [];
        foreach (explode(';', $value) as $mapping) {
            if (!str_contains($mapping, '=>')) {
                continue;
            }
            [$windows, $container] = explode('=>', $mapping, 2);
            $windows = rtrim(trim($windows), '\\/');
            $container = rtrim(trim($container), '/');
            if ($windows !== '' && $container !== '') {
                $items[$windows] = $container;
            }
        }
        return $items;
    }

    private function normalizeLimit(int $limit): int
    {
        return min(max($limit, 1), 200);
    }

    private function tokens(string $query): array
    {
        $tokens = preg_split('/[^\pL\pN._-]+/u', mb_strtolower($query)) ?: [];
        return array_values(array_filter($tokens, fn(string $token): bool => mb_strlen($token) >= 2));
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower(str_replace('\\', '/', $value));
        $value = strtr($value, [
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'ß' => 'ss'
        ]);
        return $value;
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
