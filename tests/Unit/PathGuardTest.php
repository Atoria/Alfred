<?php

namespace Atoria\Alfred\Tests\Unit;

use Atoria\Alfred\Security\AccessDenied;
use Atoria\Alfred\Security\PathGuard;
use Atoria\Alfred\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PathGuardTest extends TestCase
{
    protected function guard(): PathGuard
    {
        return PathGuard::fromConfig();
    }

    public function test_reads_application_code(): void
    {
        $abs = $this->guard()->file('app/Models/Shift.php');

        $this->assertSame('app/Models/Shift.php', $this->guard()->relative($abs));
    }

    public static function forbidden(): array
    {
        return [
            'env file' => ['.env'],
            'traversal' => ['app/../.env'],
            'outside project' => ['../../../TestCase.php'],
            'storage' => ['storage/secret.php'],
            'config is not a readable root by default' => ['config/services.php'],
            'absolute path elsewhere' => ['/etc/passwd'],
            'missing' => ['app/Nope.php'],
            'null byte' => ["app/Models/Shift.php\0.txt"],
        ];
    }

    #[DataProvider('forbidden')]
    public function test_denies(string $path): void
    {
        $this->expectException(AccessDenied::class);
        $this->guard()->file($path);
    }

    public function test_deny_patterns_win_over_roots(): void
    {
        config()->set('alfred.paths', ['app', 'storage', '.']);

        $this->expectException(AccessDenied::class);
        $this->guard()->file('storage/secret.php');
    }

    public function test_directory_listing_hides_denied_entries(): void
    {
        config()->set('alfred.paths', ['.']);
        $guard = $this->guard();

        $this->assertTrue($guard->allows(self::fixturePath().'/app/Models/Shift.php'));
        $this->assertFalse($guard->allows(self::fixturePath().'/.env'));
        $this->assertFalse($guard->allows(self::fixturePath().'/storage/secret.php'));
    }
}
