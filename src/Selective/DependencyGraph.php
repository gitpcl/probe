<?php

declare(strict_types=1);

namespace Probe\Selective;

use Closure;
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

    /** @var array<string, array{mtime: int, classes: list<string>, refs: list<string>}> scanned source files */
    private array $scanned = [];

    /** @var array<string, list<string>>|null controller class to route URIs and names */
    private ?array $routes = null;

    public function __construct(
        private readonly string $root,
        private readonly int $depth = 4,
        private readonly ?Closure $routeMap = null,
    ) {}

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

        $impacted = $this->expand(array_keys($classes));
        $needles = $this->routeNeedles($impacted);

        foreach ($this->testFiles() as $test) {
            if (isset($selected[$test])) {
                continue;
            }

            $contents = (string) @file_get_contents($test);
            foreach ($impacted as $class) {
                if ($this->references($contents, $class)) {
                    $selected[$test] = true;
                    break;
                }
            }

            if (! isset($selected[$test]) && $this->hitsRoute($contents, $needles)) {
                $selected[$test] = true;
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
            if (trim($path) === '') {
                continue;
            }

            $absolute = str_starts_with($path, '/') ? $path : $rootReal.'/'.ltrim($path, '/');
            $real = realpath($absolute);
            $out[] = $real !== false ? $real : $absolute;
        }

        return array_values(array_unique($out));
    }

    /**
     * The changed classes plus every project class that reaches one of them through
     * static references (use, new, static calls, type hints) in app/ and database/,
     * up to the configured number of hops. A Service change thus selects the tests of
     * the Controller that news it up, without the test naming the Service.
     *
     * @param  list<string>  $classes
     * @return list<string>
     */
    public function expand(array $classes): array
    {
        $seen = array_fill_keys($classes, true);
        if ($this->depth <= 0 || $classes === []) {
            return array_keys($seen);
        }

        $referrers = $this->referrers();
        $frontier = $classes;
        for ($hop = 0; $hop < $this->depth && $frontier !== []; $hop++) {
            $next = [];
            foreach ($frontier as $class) {
                foreach ($referrers[$class] ?? [] as $referrer) {
                    if (! isset($seen[$referrer])) {
                        $seen[$referrer] = true;
                        $next[] = $referrer;
                    }
                }
            }

            $frontier = $next;
        }

        return array_keys($seen);
    }

    /**
     * The changed PHP files plus the files declaring every class that reaches one of
     * their classes through static references, up to the configured hops: the set a
     * signature change can break, which is what a static analyser should re-read.
     *
     * @param  list<string>  $paths
     * @return list<string> absolute paths, changed files first
     */
    public function affectedSources(array $paths): array
    {
        $changed = array_values(array_filter(
            $this->normalize($paths),
            static fn (string $path): bool => is_file($path) && str_ends_with($path, '.php'),
        ));
        if ($changed === []) {
            return [];
        }

        $classes = [];
        foreach ($changed as $path) {
            foreach ($this->classesIn($path) as $class) {
                $classes[] = $class;
            }
        }

        $files = array_fill_keys($changed, true);
        $defines = $this->definitions();
        foreach ($this->expand($classes) as $class) {
            if (isset($defines[$class])) {
                $files[$defines[$class]] = true;
            }
        }

        return array_keys($files);
    }

    /**
     * Class to the file declaring it, from a scan that re-reads only files whose
     * mtime moved since the last call.
     *
     * @return array<string, string>
     */
    private function definitions(): array
    {
        $files = $this->sourceFiles();
        clearstatcache();
        foreach ($files as $file) {
            $mtime = (int) @filemtime($file);
            if (! isset($this->scanned[$file]) || $this->scanned[$file]['mtime'] !== $mtime) {
                $this->scanned[$file] = ['mtime' => $mtime, 'classes' => $this->classesIn($file), 'refs' => $this->namesIn($file)];
            }
        }

        foreach (array_keys($this->scanned) as $file) {
            if (! in_array($file, $files, true)) {
                unset($this->scanned[$file]);
            }
        }

        $defines = [];
        foreach ($this->scanned as $file => $info) {
            foreach ($info['classes'] as $class) {
                $defines[$class] = $file;
            }
        }

        return $defines;
    }

    /**
     * Class to the classes whose files reference it, from the same scan.
     *
     * @return array<string, list<string>>
     */
    private function referrers(): array
    {
        $defines = $this->definitions();

        $referrers = [];
        foreach ($this->scanned as $file => $info) {
            foreach ($info['refs'] as $name) {
                if (isset($defines[$name]) && $defines[$name] !== $file) {
                    foreach ($info['classes'] as $own) {
                        $referrers[$name][$own] = true;
                    }
                }
            }
        }

        return array_map('array_keys', $referrers);
    }

    /**
     * Fully qualified names a file refers to, resolved through its namespace and imports.
     *
     * @return list<string>
     */
    private function namesIn(string $file): array
    {
        $tokens = token_get_all((string) @file_get_contents($file));
        $count = count($tokens);
        $namespace = '';
        $uses = [];
        $raw = [];
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                }

                continue;
            }

            [$id, $text] = $token;
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($id === T_NAMESPACE) {
                $namespace = $this->statementAfter($tokens, $i);
            } elseif ($id === T_USE && $depth === 0) {
                $this->imports($this->statementAfter($tokens, $i), $uses);
            } elseif ($id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
                $raw[] = $text;
            } elseif ($id === T_STRING && ctype_upper($text[0]) && ! $this->isMemberName($tokens, $i)) {
                $raw[] = $text;
            }
        }

        $names = [];
        foreach ($raw as $name) {
            $names[$this->resolve($name, $namespace, $uses)] = true;
        }

        return array_keys($names);
    }

    /**
     * The text of the statement following token $i, up to its semicolon or brace.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function statementAfter(array $tokens, int $i): string
    {
        $text = '';
        for ($j = $i + 1, $count = count($tokens); $j < $count; $j++) {
            $token = $tokens[$j];
            if ($token === ';' || $token === '{') {
                break;
            }

            $text .= is_array($token) ? $token[1] : $token;
        }

        return trim($text);
    }

    /**
     * use A\B; use A\B as C; use A\{B, C as D}; function and const imports are skipped.
     *
     * @param  array<string, string>  $uses
     */
    private function imports(string $statement, array &$uses): void
    {
        if (preg_match('/^(function|const)\b/', $statement) === 1) {
            return;
        }

        $prefix = '';
        if (preg_match('/^([^{]*)\{(.*)\}$/s', $statement, $group) === 1) {
            $prefix = trim($group[1]);
            $statement = $group[2];
        }

        foreach (explode(',', $statement) as $piece) {
            $piece = trim(preg_replace('/\s+/', ' ', $piece) ?? $piece);
            if ($piece === '') {
                continue;
            }

            [$name, $alias] = array_pad(preg_split('/\s+as\s+/i', $piece) ?: [$piece], 2, null);
            $name = ltrim($prefix.trim((string) $name), '\\');
            $alias = $alias !== null ? trim($alias) : (($pos = strrpos($name, '\\')) !== false ? substr($name, $pos + 1) : $name);
            $uses[$alias] = $name;
        }
    }

    /**
     * A bare name after -> ?-> :: function const or a type keyword is a member or a declaration, not a class reference.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function isMemberName(array $tokens, int $i): bool
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $previous = $tokens[$j];
            if (is_array($previous) && in_array($previous[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($previous) && in_array($previous[0], [
                T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST,
                T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_NAMESPACE, T_GOTO,
            ], true);
        }

        return false;
    }

    /** @param  array<string, string>  $uses */
    private function resolve(string $name, string $namespace, array $uses): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $first = ($pos = strpos($name, '\\')) !== false ? substr($name, 0, $pos) : $name;
        if (isset($uses[$first])) {
            return $uses[$first].substr($name, strlen($first));
        }

        return $namespace === '' ? $name : $namespace.'\\'.$name;
    }

    /**
     * Quoted URIs and route names a test would use to reach a controller in the set.
     *
     * @param  list<string>  $classes
     * @return list<string>
     */
    private function routeNeedles(array $classes): array
    {
        if ($this->routeMap === null) {
            return [];
        }

        $this->routes ??= ($this->routeMap)();
        $needles = [];
        foreach ($classes as $class) {
            foreach ($this->routes[$class] ?? [] as $needle) {
                $needles[$needle] = true;
            }
        }

        return array_keys($needles);
    }

    /** @param  list<string>  $needles */
    private function hitsRoute(string $contents, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($contents, "'".$needle."'") || str_contains($contents, '"'.$needle.'"')) {
                return true;
            }
        }

        return false;
    }

    /**
     * PHP files under app/ and database/: where project classes and their references live.
     *
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $root = realpath($this->root) ?: $this->root;
        $files = [];
        foreach (['app', 'database'] as $directory) {
            if (! is_dir($root.'/'.$directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<string> */
    public function testFiles(): array
    {
        $directory = (realpath($this->root) ?: $this->root).'/tests';
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

        return explode('/', $relative)[0];
    }
}
