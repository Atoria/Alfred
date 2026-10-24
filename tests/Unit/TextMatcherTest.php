<?php

namespace Atoria\Alfred\Tests\Unit;

use Atoria\Alfred\Changes\MatchProblem;
use Atoria\Alfred\Changes\TextMatcher;
use PHPUnit\Framework\TestCase;

class TextMatcherTest extends TestCase
{
    private string $file = "<?php\n\nclass A\n{\n    public function x()\n    {\n        return [\n            'a' => 1,\n            'b' => 2,\n        ];\n    }\n}\n";

    public function test_exact_match_is_used_as_is(): void
    {
        $r = (new TextMatcher)->locate($this->file, "'a' => 1,", "'a' => 10,");

        $this->assertSame(["'a' => 1,", "'a' => 10,", false], [$r['search'], $r['replace'], $r['fuzzy']]);
    }

    public function test_wrong_indentation_is_placed_and_the_replacement_reindented(): void
    {
        $r = (new TextMatcher)->locate($this->file, "return [\n    'a' => 1,", "return [\n    'z' => 0,\n    'a' => 1,");

        $this->assertTrue($r['fuzzy']);
        $this->assertSame("        return [\n            'a' => 1,", $r['search']);
        $this->assertSame("        return [\n            'z' => 0,\n            'a' => 1,", $r['replace']);
    }

    public function test_too_much_indentation_is_removed(): void
    {
        $r = (new TextMatcher)->locate($this->file, "                'b' => 2,", "                'b' => 3,");

        $this->assertSame("            'b' => 3,", $r['replace']);
    }

    public function test_crlf_files(): void
    {
        $crlf = str_replace("\n", "\r\n", $this->file);
        $r = (new TextMatcher)->locate($crlf, "'a' => 1,\n'b' => 2,", "'a' => 1,\n'b' => 3,");

        $this->assertSame("            'a' => 1,\r\n            'b' => 2,", $r['search']);
        $this->assertSame("            'a' => 1,\r\n            'b' => 3,", $r['replace']);
        $this->assertSame(1, substr_count($crlf, $r['search']));
    }

    public function test_line_number_prefixes_are_stripped(): void
    {
        $r = (new TextMatcher)->locate($this->file, "    8              'a' => 1,", "    8              'a' => 5,");

        $this->assertSame("            'a' => 5,", $r['replace']);
    }

    public function test_ambiguous_and_missing_explain_themselves(): void
    {
        $file = "a\n  x\nb\n  x\n";

        try {
            (new TextMatcher)->locate($file, 'x', 'y');
            $this->fail();
        } catch (MatchProblem $e) {
            $this->assertStringContainsString('occurs 2 times (line 2, line 4)', $e->getMessage());
        }

        try {
            (new TextMatcher)->locate($this->file, "'c' => 1,", 'x');
            $this->fail();
        } catch (MatchProblem $e) {
            $this->assertStringContainsString('closest lines', $e->getMessage());
            $this->assertStringContainsString("    8              'a' => 1,", $e->getMessage());
        }
    }

    public function test_spaces_in_the_edit_become_the_files_tabs(): void
    {
        $file = "<?php\nclass A\n{\n\tpublic function x()\n\t{\n\t\treturn 1;\n\t}\n}\n";

        $r = (new TextMatcher)->locate($file, '        return 1;', "        if (\$y) {\n            return 2;\n        }\n        return 1;");

        $this->assertSame("\t\treturn 1;", $r['search']);
        $this->assertSame("\t\tif (\$y) {\n\t\t    return 2;\n\t\t}\n\t\treturn 1;", $r['replace']);
    }
}
