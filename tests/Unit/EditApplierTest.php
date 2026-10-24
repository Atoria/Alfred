<?php

namespace Atoria\Alfred\Tests\Unit;

use Atoria\Alfred\Changes\EditApplier;
use Atoria\Alfred\Changes\EditMismatch;
use Atoria\Alfred\PullRequests\PullRequestDraft;
use PHPUnit\Framework\TestCase;

/** Preview, apply and pull requests turn the same edits into the same file. */
class EditApplierTest extends TestCase
{
    private const FILE = "<?php\nreturn [\n    'a' => 1,\n    'b' => 2,\n];\n";

    private const EDITS = [
        ['path' => 'x.php', 'search' => "'a' => 1,", 'replace' => "'a' => 10,"],
        ['path' => 'x.php', 'search' => "'b' => 2,", 'replace' => "'b' => 20,"],
    ];

    public function test_edits_apply_in_order_once_each(): void
    {
        $this->assertSame("<?php\nreturn [\n    'a' => 10,\n    'b' => 20,\n];\n", EditApplier::apply(self::FILE, EditApplier::byFile(self::EDITS)['x.php']));
    }

    public function test_text_found_twice_or_not_at_all_is_refused(): void
    {
        $this->expectException(EditMismatch::class);
        EditApplier::apply("a\na\n", [['search' => 'a', 'replace' => 'b']]);
    }

    public function test_an_empty_search_never_matches(): void
    {
        $this->expectException(EditMismatch::class);
        EditApplier::apply('abc', [['search' => '', 'replace' => 'x']]);
    }

    public function test_pull_requests_produce_the_same_file_as_apply(): void
    {
        $draft = new PullRequestDraft('t', 'b', 'branch', 'msg', EditApplier::byFile(self::EDITS), null, false, [], 1);
        $this->assertSame(EditApplier::apply(self::FILE, EditApplier::byFile(self::EDITS)['x.php']), $draft->apply('x.php', self::FILE));
    }
}
