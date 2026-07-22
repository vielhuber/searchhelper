<?php
declare(strict_types=1);

use vielhuber\searchhelper\searchhelper;

final class Test extends \PHPUnit\Framework\TestCase
{
    private string $directory;
    private string $path;

    protected function setUp(): void
    {
        $this->path = (string) getenv('PATH');
        $this->directory = sys_get_temp_dir() . '/searchhelper_' . uniqid();
        mkdir($this->directory . '/documents/projects', 0775, true);
        mkdir($this->directory . '/Other', 0775, true);
        file_put_contents($this->directory . '/documents/projects/ProjectPlan.pdf', 'pdf fixture');
        file_put_contents($this->directory . '/documents/projects/ProjectNotes.txt', 'notes fixture');
        file_put_contents($this->directory . '/Other/Unrelated.txt', 'other fixture');
        file_put_contents(
            $this->directory . '/plocate',
            "#!/bin/sh\nprintf '%s\\n' " .
                escapeshellarg($this->directory . '/documents/projects/ProjectPlan.pdf') . ' ' .
                escapeshellarg($this->directory . '/documents/projects/ProjectNotes.txt') . ' ' .
                escapeshellarg($this->directory . '/Other/Unrelated.txt') . "\n"
        );
        chmod($this->directory . '/plocate', 0775);
        putenv('PATH=' . $this->directory . PATH_SEPARATOR . $this->path);
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->path);
        $this->removeDirectory($this->directory);
    }

    public function test__plocate_search_filters_allowed_root(): void
    {
        $search = $this->search();

        $result = $search->searchFiles(
            query: 'project notes',
            root: $this->directory . '/documents/projects',
            limit: 10,
            engine: 'plocate'
        );

        $this->assertSame('plocate', $result['engine']);
        $this->assertCount(2, $result['items']);
        $this->assertSame('ProjectNotes.txt', $result['items'][0]['name']);
    }

    public function test__root_must_be_allowed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->search()->searchFiles(query: 'test', root: '/not-allowed', engine: 'plocate');
    }

    public function test__status_reports_configured_engines(): void
    {
        $status = $this->search()->status();

        $this->assertSame([$this->directory . '/documents'], $status['roots']);
        $this->assertSame(['everything', 'plocate'], array_keys($status['engines']));
        $this->assertSame([
            ['root' => $this->directory . '/documents', 'engine' => 'plocate']
        ], $status['routes']);
    }

    public function test__automatic_search_uses_plocate_for_native_root(): void
    {
        putenv('PATH=' . $this->directory . '/missing');
        try {
            $result = searchhelper::create([
                'roots' => [$this->directory . '/documents'],
                'engines' => ['everything', 'plocate'],
                'everything_url' => '',
                'everything_username' => '',
                'everything_password' => '',
                'path_mappings' => [],
                'command_timeout' => 2
            ])->searchFiles(query: 'project', root: $this->directory . '/documents');
        } finally {
            putenv('PATH=' . $this->directory . PATH_SEPARATOR . $this->path);
        }

        $this->assertNull($result['engine']);
        $this->assertSame(0, $result['count']);
        $this->assertSame(['plocate'], array_keys($result['errors']));
    }

    public function test__automatic_search_uses_everything_for_mapped_root(): void
    {
        $result = searchhelper::create([
            'roots' => [$this->directory . '/documents'],
            'engines' => ['everything', 'plocate'],
            'everything_url' => '',
            'everything_username' => '',
            'everything_password' => '',
            'path_mappings' => [
                'C:\\Documents' => $this->directory . '/documents'
            ],
            'command_timeout' => 2
        ])->searchFiles(query: 'project');

        $this->assertSame(['everything'], array_keys($result['errors']));
        $this->assertSame([], $result['engines']);
    }

    public function test__everything_searches_nested_paths_recursively(): void
    {
        $router = $this->directory . '/everything.php';
        file_put_contents(
            $router,
            <<<'PHP'
<?php
declare(strict_types=1);

file_put_contents(__DIR__ . '/everything-query.txt', (string) ($_GET['search'] ?? ''));
header('Content-Type: application/json');
echo json_encode([
    'results' => [[
        'name' => 'Greenline.pdf',
        'path' => 'C:\\Documents\\Nested',
        'size' => 42,
        'date_modified' => 1
    ]]
]);
PHP
        );
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        $this->assertIsResource($socket, $errorMessage);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr($address, ':'), 1);
        $pipes = [];
        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ],
            $pipes,
            $this->directory
        );
        $this->assertIsResource($server);

        $ready = false;
        set_error_handler(static fn(): bool => true);
        try {
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $connection = stream_socket_client('tcp://127.0.0.1:' . $port, $number, $message, 0.05);
                if (is_resource($connection)) {
                    fclose($connection);
                    $ready = true;
                    break;
                }
                usleep(20_000);
            }
        } finally {
            restore_error_handler();
        }
        $this->assertTrue($ready, 'Everything test server did not start.');

        try {
            $result = searchhelper::create([
                'roots' => [$this->directory . '/documents'],
                'engines' => ['everything'],
                'everything_url' => 'http://127.0.0.1:' . $port,
                'everything_username' => '',
                'everything_password' => '',
                'path_mappings' => [
                    'C:\\Documents' => $this->directory . '/documents'
                ],
                'command_timeout' => 2
            ])->searchFiles(query: 'greenline');
        } finally {
            proc_terminate($server);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($server);
        }

        $this->assertSame('path:"C:\\Documents\\" greenline', file_get_contents($this->directory . '/everything-query.txt'));
        $this->assertSame('everything', $result['engine']);
        $this->assertSame($this->directory . '/documents/Nested/Greenline.pdf', $result['items'][0]['path']);
    }

    public function test__tool_throws_when_all_engines_fail(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('All configured search engines failed:');

        searchhelper::create([
            'roots' => [$this->directory . '/documents'],
            'engines' => ['everything'],
            'everything_url' => '',
            'everything_username' => '',
            'everything_password' => '',
            'path_mappings' => [],
            'command_timeout' => 2
        ])->searchFilesTool(query: 'project');
    }

    private function search(): searchhelper
    {
        return searchhelper::create([
            'roots' => [$this->directory . '/documents'],
            'engines' => ['plocate'],
            'everything_url' => '',
            'everything_username' => '',
            'everything_password' => '',
            'path_mappings' => [
                'C:' => '/host/data'
            ],
            'command_timeout' => 2
        ]);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }
            unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
