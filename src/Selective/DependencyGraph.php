<?php

declare(strict_types=1);

namespace Probe\Selective;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Practical test-impact map: class references, filename stems, and changed tests.
 * It reads the project tree and does not boot the test runner.
 */
final class DependencyGraph
{
    /** @var list<string> */
    private const SKIP = ['vendor', 'node_modules', 'storage', 'bootstrap'];

    public function __construct(private readonly string $root) {}

    /**
     * @param  list<string>  $paths
     * @return list<string> absolute test paths, changed tests first
     */
    public function affectedTests(array $paths): array
    {
        $changed = $this->normalize($paths);
        if ($changed === []) {
            return [];
        }

        $selected = [];
        $classes = [];

        foreach ($changed as $path) {
            if (is_file($path) && $this->isTest($path)) {
                $selected[$path] = true;
            }

            if (is_file($path)) {
                foreach ($this->classesIn($path) as $class) {
                    $classes[$class] = $path;
                }
            }
        }

        foreach ($this->testFiles() as $test) {
            if (isset($selected[$test])) {
                continue;
            }

            $contents = (string) @file_get_contents($test);
            foreach (array_keys($classes) as $class) {
                if ($this->references($contents, $class)) {
                    $selected[$test] = true;
                    break;
                }
            }

            if (isset($selected[$test])) {
                continue;
            }

            foreach ($changed as $path) {
                if ($this->nameMatches($path, $test)) {
                    $selected[$test] = true;
                    break;
                }
            }
        }

        $list = array_keys($selected);
        sort($list);

        return $list;
    }

    /** @return list<string> */
    public function classesIn(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $code = (string) file_get_contents($path);
        $namespace = '';
        if (preg_match('/^\s*namespace\s+([^;]+);/m', $code, $match) === 1) {
            $namespace = trim($match[1]);
        }

        if (preg_match_all('/\b(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/', $code, $names) !== false) {
            $found = $names[1];
        } else {
            $found = [];
        }

        $classes = [];
        foreach ($found as $name) {
            $classes[] = $namespace === '' ? $name : $namespace.'\\'.$name;
        }

        return $classes;
    }

    /** @param  list<string>  $paths
     * @return list<string>
     */
    private function normalize(array $paths): array
    {
        $rootReal = realpath($this->root) ?: $this->root;
        $out = [];

        foreach ($paths as $path) {
            if (! is_string($path) || trim($path) === '') {
                continue;
            }

            $absolute = str_starts_with($path, '/') ? $path : $rootReal.'/'.ltrim($path, '/');
            $real = realpath($absolute);
            $out[] = $real !== false ? $real : $absolute;
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private function testFiles(): array
    {
        $directory = $this->root.'/tests';
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if ($this->isTest($path)) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    private function isTest(string $path): bool
    {
        $name = basename($path);

        return str_ends_with($name, 'Test.php') || str_ends_with($name, '.pest.php');
    }

    private function references(string $contents, string $class): bool
    {
        if ($class !== '' && str_contains($contents, $class)) {
            return true;
        }

        $short = ($pos = strrpos($class, '\\')) !== false ? substr($class, $pos + 1) : $class;
        if ($short === '' || strlen($short) < 3) {
            return false;
        }

        return preg_match('/\b'.preg_quote($short, '/').'\b/', $contents) === 1;
    }

    private function nameMatches(string $source, string $test): bool
    {
        if ($this->topLevel($source) !== '' && in_array($this->topLevel($source), self::SKIP, true)) {
            return false;
        }

        $stem = pathinfo($source, PATHINFO_FILENAME);
        foreach (['Controller', 'Model', 'Service', 'Action', 'Job', 'Policy', 'Request'] as $suffix) {
            if (str_ends_with($stem, $suffix) && strlen($stem) > strlen($suffix) + 2) {
                $stem = substr($stem, 0, -strlen($suffix));
                break;
            }
        }

        if (strlen($stem) < 3 || in_array(strtolower($stem), ['test', 'index', 'show', 'data'], true)) {
            return false;
        }

        return str_contains(pathinfo($test, PATHINFO_FILENAME), $stem);
    }

    private function topLevel(string $path): string
    {
        $root = realpath($this->root) ?: $this->root;
        $prefix = rtrim($root, '/').'/';
        $relative = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;

        return explode('/', $relative)[0] ?? '';
    }
}
