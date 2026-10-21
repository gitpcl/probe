<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Worker\SourceWatch;

final class SourceWatchTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/probe-watch-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/app', 0775, true);
        mkdir($this->root.'/vendor/acme', 0775, true);
    }

    public function test_a_changed_included_file_is_named_and_vendor_is_ignored(): void
    {
        $thing = $this->file('app/Thing.php', "<?php\nreturn 'thing';\n");
        $lib = $this->file('vendor/acme/Lib.php', "<?php\nreturn 'lib';\n");
        include $thing;
        include $lib;
        $watch = new SourceWatch($this->root);

        $this->assertNull($watch->changed());

        touch($lib, time() + 5);
        $this->assertNull($watch->changed());

        touch($thing, time() + 5);
        $this->assertSame('app/Thing.php', $watch->changed());
    }

    public function test_env_file_and_deletions_count_as_changes(): void
    {
        $env = $this->file('.env', "APP_ENV=local\n");
        $gone = $this->file('app/Gone.php', "<?php\nreturn 'gone';\n");
        include $gone;

        $watch = new SourceWatch($this->root);
        touch($env, time() + 5);
        $this->assertSame('.env', $watch->changed());

        $watch = new SourceWatch($this->root);
        unlink($gone);
        $this->assertSame('app/Gone.php', $watch->changed());
    }

    public function test_a_file_loaded_after_the_last_check_is_compared_to_that_check(): void
    {
        $old = $this->file('app/Old.php', "<?php\nreturn 'old';\n");
        $fresh = $this->file('app/Fresh.php', "<?php\nreturn 'fresh';\n");
        $watch = new SourceWatch($this->root);

        include $old;
        $this->assertNull($watch->changed(), 'a file written before the check and loaded after it is current');

        touch($fresh, time() + 5);
        include $fresh;
        $this->assertSame('app/Fresh.php', $watch->changed(), 'a file edited after the check may be newer than what was loaded');
    }

    private function file(string $path, string $contents): string
    {
        $absolute = $this->root.'/'.$path;
        file_put_contents($absolute, $contents);
        touch($absolute, time() - 10);

        return $absolute;
    }
}
