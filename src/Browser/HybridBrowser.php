<?php

declare(strict_types=1);

namespace Probe\Browser;

use Probe\Contract\BrowserProbe;
use Probe\Result\Failure;
use Probe\Result\ProbeResult;
use Probe\Support\Process;
use Probe\Support\Stats;

/**
 * probe_browser. Lightpanda is the default light engine.
 * Playwright is the fallback, and the only engine that runs interaction steps.
 */
final class HybridBrowser implements BrowserProbe
{
    private ?PlaywrightSidecar $sidecar = null;

    /** @var list<string>|null */
    private ?array $sidecarCommand = null;

    /** @param  array<string, mixed>  $config */
    public function __construct(
        private readonly array $config = [],
        private readonly string $root = '',
    ) {}

    /** Release the persistent browser, if one was started. */
    public function stop(): void
    {
        $this->sidecar?->stop();
        $this->sidecar = null;
    }

    /** @param  array<string, mixed>  $request */
    public function run(array $request): array
    {
        return $this->check($request)->toArray();
    }

    /** @param  array<string, mixed>  $request */
    public function check(array $request): ProbeResult
    {
        $started = hrtime(true);
        $url = $this->url($request);
        if ($url === null) {
            return ProbeResult::error('url', 'probe_browser needs an http or https url');
        }

        $plan = $this->plan($request);
        if (isset($plan['error'])) {
            return ProbeResult::error('missing_tool', $plan['error']);
        }

        $timeout = (int) ($this->config['browser']['timeout_ms'] ?? 8000);
        if ($plan['engine'] === 'playwright') {
            $payload = json_encode([
                'url' => $url,
                'steps' => is_array($request['steps'] ?? null) ? $request['steps'] : [],
                'timeout_ms' => $timeout,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $result = isset($plan['sidecar'])
                ? $this->sidecar($plan['command'])->request($payload, $timeout)
                : Process::run($plan['command'], $this->root !== '' ? $this->root : null, $payload, $timeout);
        } else {
            $args = $this->config['browser']['lightpanda_args'] ?? ['fetch', '--dump', 'html'];
            if (! is_array($args)) {
                $args = ['fetch', '--dump', 'html'];
            }
            $command = array_merge($plan['command'], array_map('strval', $args), [$url]);
            $result = Process::run($command, $this->root !== '' ? $this->root : null, null, $timeout);
        }

        if ($result['exit'] === 127) {
            return $this->errorResult(
                isset($plan['sidecar']) ? 'browser' : 'missing_tool',
                trim($result['stderr']) !== '' ? $result['stderr'] : $plan['engine'].' was not found',
                $result['duration_ms'],
                $plan,
            );
        }

        if ($result['exit'] === 124) {
            return $this->errorResult('timeout', 'browser probe timed out', $result['duration_ms'], $plan);
        }

        $page = $this->interpret($result['stdout'], $result['exit'], $result['stderr']);
        if ($page['error'] !== null && $page['body'] === '') {
            return $this->errorResult('browser', $page['error'], $result['duration_ms'], $plan);
        }

        if (is_numeric($request['http_status'] ?? null) && $page['http_status'] === null) {
            return new ProbeResult(
                status: 'unsupported',
                durationMs: $result['duration_ms'],
                failures: [new Failure(
                    id: 'http_status',
                    message: $plan['engine'].' does not report the http status; set PROBE_BROWSER=playwright',
                    type: 'browser',
                    file: $url,
                )],
                value: ['engine' => $plan['engine']],
                hasValue: true,
            );
        }

        $failures = $this->assertions($request, $url, $page['body'], $page['http_status']);
        $duration = max($result['duration_ms'], Stats::elapsedMs($started));
        $value = ['engine' => $plan['engine']];
        if (isset($plan['sidecar'])) {
            $value['persistent'] = true;
            $value['pid'] = $page['pid'];
        }

        if ($failures !== []) {
            return new ProbeResult(
                status: 'failed',
                durationMs: $duration,
                failures: $failures,
                value: $value,
                hasValue: true,
            );
        }

        return ProbeResult::passed($duration, $value, true);
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array{engine: string, command: list<string>, sidecar?: true}|array{error: string}
     */
    private function plan(array $request): array
    {
        $engine = isset($request['engine']) && is_string($request['engine']) && $request['engine'] !== ''
            ? $request['engine']
            : (string) ($this->config['browser']['engine'] ?? 'auto');
        $steps = is_array($request['steps'] ?? null) && $request['steps'] !== [];
        $lightpanda = $this->binary((string) ($this->config['browser']['lightpanda'] ?? 'lightpanda'));
        $sidecar = $this->sidecarCommand();
        $playwright = $this->playwrightCommand();
        $persistent = $sidecar === null ? null : ['engine' => 'playwright', 'command' => $sidecar, 'sidecar' => true];

        if ($steps) {
            if ($persistent !== null) {
                return $persistent;
            }

            if ($playwright === null) {
                return ['error' => 'browser steps need playwright: '.$this->playwrightMissing()];
            }

            return ['engine' => 'playwright', 'command' => $playwright];
        }

        if ($engine === 'lightpanda') {
            if ($lightpanda === null) {
                return ['error' => 'lightpanda was not found'];
            }

            return ['engine' => 'lightpanda', 'command' => Process::phpCommand($lightpanda)];
        }

        if ($engine === 'playwright') {
            if ($persistent !== null) {
                return $persistent;
            }

            if ($playwright === null) {
                return ['error' => $this->playwrightMissing()];
            }

            return ['engine' => 'playwright', 'command' => $playwright];
        }

        if ($persistent !== null) {
            return $persistent;
        }

        if ($lightpanda !== null) {
            return ['engine' => 'lightpanda', 'command' => Process::phpCommand($lightpanda)];
        }

        if ($playwright !== null) {
            return ['engine' => 'playwright', 'command' => $playwright];
        }

        return ['error' => 'lightpanda was not found and '.$this->playwrightMissing()];
    }

    private function binary(string $name): ?string
    {
        if ($name === '') {
            return null;
        }

        if (str_contains($name, '/') || str_contains($name, '\\')) {
            return is_file($name) ? $name : null;
        }

        return Process::which($name);
    }

    /** @return list<string>|null */
    private function playwrightCommand(): ?array
    {
        $configured = (string) ($this->config['browser']['playwright'] ?? 'playwright');
        if ($configured !== '' && (str_contains($configured, '/') || str_contains($configured, '\\')) && is_file($configured)) {
            if (str_ends_with($configured, '.mjs')) {
                $node = $this->binary((string) ($this->config['browser']['node'] ?? 'node'));

                return $node !== null ? [$node, $configured] : null;
            }

            return Process::phpCommand($configured);
        }

        $node = $this->binary($this->nodeName());
        $script = dirname(__DIR__, 2).'/resources/playwright-probe.mjs';
        if ($node !== null && is_file($script) && is_dir($this->playwrightModule())) {
            return [$node, $script];
        }

        return null;
    }

    /**
     * An error result that still says which engine answered and whether it is the kept browser.
     *
     * @param  array{engine: string, command: list<string>, sidecar?: true}  $plan
     */
    private function errorResult(string $id, string $message, int $durationMs, array $plan): ProbeResult
    {
        $value = ['engine' => $plan['engine']];
        if (isset($plan['sidecar'])) {
            $value['persistent'] = true;
        }

        return new ProbeResult(
            status: 'error',
            durationMs: $durationMs,
            failures: [new Failure(id: $id, message: self::plain($message))],
            value: $value,
            hasValue: true,
        );
    }

    /** Playwright decorates its messages with ANSI escapes; an agent reads JSON. */
    private static function plain(string $text): string
    {
        $plain = (string) preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $text);

        return trim((string) preg_replace('/\s+/', ' ', $plain));
    }

    /**
     * The persistent browser command: the shipped Playwright server with Node and the
     * npm package in the app, or a configured server script (tests use a PHP one).
     *
     * @return list<string>|null
     */
    private function sidecarCommand(): ?array
    {
        if (! (bool) ($this->config['browser']['persistent'] ?? true)) {
            return null;
        }

        $configured = $this->config['browser']['server'] ?? null;
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return Process::phpCommand($configured);
        }

        $node = $this->binary($this->nodeName());
        $script = dirname(__DIR__, 2).'/resources/playwright-server.mjs';
        if ($node !== null && is_file($script) && is_dir($this->playwrightModule())) {
            return [$node, $script];
        }

        return null;
    }

    /** @param  list<string>  $command */
    private function sidecar(array $command): PlaywrightSidecar
    {
        if ($this->sidecar === null || $this->sidecarCommand !== $command) {
            $this->sidecar?->stop();
            $this->sidecar = new PlaywrightSidecar($command, $this->root !== '' ? $this->root : (string) getcwd());
            $this->sidecarCommand = $command;
        }

        return $this->sidecar;
    }

    private function nodeName(): string
    {
        return (string) ($this->config['browser']['node'] ?? 'node');
    }

    private function playwrightModule(): string
    {
        return ($this->root !== '' ? $this->root : (string) getcwd()).'/node_modules/playwright';
    }

    private function playwrightMissing(): string
    {
        if ($this->binary($this->nodeName()) === null) {
            return 'node was not found';
        }

        return 'playwright npm package is not installed in '.dirname($this->playwrightModule(), 2).' (npm install -D playwright)';
    }

    /** @param  array<string, mixed>  $request */
    private function url(array $request): ?string
    {
        $url = $request['url'] ?? null;
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $url = trim($url);
        if (str_starts_with($url, '/')) {
            $base = rtrim((string) ($this->config['browser']['base_url'] ?? 'http://127.0.0.1:8000'), '/');
            $url = $base.$url;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    /** @return array{body: string, http_status: int|null, error: string|null, pid: int|null} */
    private function interpret(string $stdout, int $exit, string $stderr): array
    {
        $trim = trim($stdout);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $json = json_decode($trim, true);
            if (is_array($json)) {
                return [
                    'body' => (string) ($json['body'] ?? $json['html'] ?? ''),
                    'http_status' => isset($json['http_status']) ? (int) $json['http_status'] : null,
                    'error' => isset($json['error']) && is_string($json['error']) && $json['error'] !== '' ? $json['error'] : null,
                    'pid' => isset($json['pid']) && is_numeric($json['pid']) ? (int) $json['pid'] : null,
                ];
            }
        }

        if ($exit !== 0 && trim($stdout) === '') {
            $message = trim($stderr) !== '' ? trim(preg_replace('/\s+/', ' ', $stderr) ?? $stderr) : 'browser exit '.$exit;

            return ['body' => '', 'http_status' => null, 'error' => $message, 'pid' => null];
        }

        return ['body' => $stdout, 'http_status' => null, 'error' => null, 'pid' => null];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return list<Failure>
     */
    private function assertions(array $request, string $url, string $body, ?int $httpStatus): array
    {
        $failures = [];
        $expectedStatus = $request['http_status'] ?? null;
        if (is_numeric($expectedStatus) && $httpStatus !== null && (int) $expectedStatus !== $httpStatus) {
            $failures[] = new Failure(
                id: '1',
                message: 'http status '.$httpStatus.' at '.$url,
                type: 'browser',
                file: $url,
            );
        }

        foreach ($this->needles($request['contains'] ?? null) as $needle) {
            if (! str_contains($body, $needle)) {
                $failures[] = new Failure(
                    id: (string) (count($failures) + 1),
                    message: 'missing text '.$needle.' at '.$url,
                    type: 'browser',
                    file: $url,
                );
            }
        }

        foreach ($this->needles($request['absent'] ?? null) as $needle) {
            if (str_contains($body, $needle)) {
                $failures[] = new Failure(
                    id: (string) (count($failures) + 1),
                    message: 'unexpected text '.$needle.' at '.$url,
                    type: 'browser',
                    file: $url,
                );
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function needles(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $needles = [];
        foreach ($value as $needle) {
            if (is_string($needle) && $needle !== '') {
                $needles[] = $needle;
            }
        }

        return $needles;
    }
}
