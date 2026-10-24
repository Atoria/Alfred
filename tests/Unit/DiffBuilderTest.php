<?php

namespace Atoria\Alfred\Tests\Unit;

use Atoria\Alfred\Changes\DiffBuilder;
use PHPUnit\Framework\TestCase;

class DiffBuilderTest extends TestCase
{
    public function test_single_line_change_with_context(): void
    {
        $before = "a\nb\nc\nd\ne\nf\ng\n";
        $after = "a\nb\nc\nD\ne\nf\ng\n";

        $this->assertSame(
            "diff --git a/x.txt b/x.txt\n--- a/x.txt\n+++ b/x.txt\n@@ -1,7 +1,7 @@\n a\n b\n c\n-d\n+D\n e\n f\n g\n",
            (new DiffBuilder)->file('x.txt', $before, $after),
        );
    }

    public function test_distant_changes_make_separate_hunks(): void
    {
        $lines = array_map(fn ($i) => "line {$i}", range(1, 30));
        $before = implode("\n", $lines)."\n";
        $lines[1] = 'changed 2';
        $lines[27] = 'changed 28';
        $diff = (new DiffBuilder)->file('x.txt', $before, implode("\n", $lines)."\n");

        $this->assertSame(2, substr_count($diff, '@@ -'));
        $this->assertStringContainsString('@@ -1,5 +1,5 @@', $diff);
        $this->assertStringContainsString('@@ -25,6 +25,6 @@', $diff);
    }

    public function test_missing_trailing_newline(): void
    {
        $diff = (new DiffBuilder)->file('x.txt', "a\nb", "a\nb\n");

        $this->assertStringContainsString("-b\n\\ No newline at end of file\n+b\n", $diff);
    }

    public function test_identical_is_empty(): void
    {
        $this->assertSame('', (new DiffBuilder)->file('x.txt', "same\n", "same\n"));
    }

    public function test_round_trips_through_git_apply(): void
    {
        $dir = sys_get_temp_dir().'/alfred-diff-'.uniqid();
        mkdir($dir);
        mt_srand(42);
        $builder = new DiffBuilder;

        for ($t = 0; $t < 150; $t++) {
            $lines = array_map(fn () => 'l'.mt_rand(0, 5), range(0, mt_rand(1, 30)));
            $after = $lines;
            for ($e = 0; $e < mt_rand(1, 4); $e++) {
                $pos = mt_rand(0, count($after) - 1);
                match (mt_rand(0, 2)) {
                    0 => array_splice($after, $pos, 0, ['new'.mt_rand(0, 9)]),
                    1 => count($after) > 1 ? array_splice($after, $pos, 1) : null,
                    2 => $after[$pos] = 'chg'.mt_rand(0, 9),
                };
            }
            $before = implode("\n", $lines).(mt_rand(0, 3) ? "\n" : '');
            $afterText = implode("\n", $after).(mt_rand(0, 3) ? "\n" : '');
            $patch = $builder->file('f.txt', $before, $afterText);
            if ($patch === '') {
                $this->assertSame($before, $afterText);

                continue;
            }

            file_put_contents("{$dir}/f.txt", $before);
            file_put_contents("{$dir}/p.diff", $patch);
            exec('cd '.escapeshellarg($dir).' && git apply p.diff 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out)."\n".$patch);
            $this->assertSame($afterText, file_get_contents("{$dir}/f.txt"));
        }

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    }
}
