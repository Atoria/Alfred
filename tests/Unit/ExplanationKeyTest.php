<?php

namespace Atoria\Alfred\Tests\Unit;

use Atoria\Alfred\Context\ElementDescriptor;
use Atoria\Alfred\Context\PageContext;
use Atoria\Alfred\Explanations\ExplanationKey;
use PHPUnit\Framework\TestCase;

class ExplanationKeyTest extends TestCase
{
    protected function el(array $o = []): ElementDescriptor
    {
        return ElementDescriptor::fromArray(array_merge(['kind' => 'table-cell', 'tag' => 'td', 'labels' => ['Bookings this week'], 'column' => 'Hours', 'text' => '37.5'], $o));
    }

    protected function page(string $route = 'bookings.index'): PageContext
    {
        return new PageContext(str_repeat('a', 40), 'u:1', 'https://x.test/bookings', $route, 'bookings');
    }

    public function test_same_column_shares_one_explanation_regardless_of_value(): void
    {
        $a = ExplanationKey::for($this->page(), $this->el(['text' => '37.5']));
        $b = ExplanationKey::for($this->page(), $this->el(['text' => '12', 'labels' => ['Bookings this week']]));

        $this->assertSame($a, $b);
    }

    public function test_numbers_in_labels_do_not_split_the_cache(): void
    {
        $a = ExplanationKey::for($this->page(), $this->el(['labels' => ['Week 42 bookings']]));
        $b = ExplanationKey::for($this->page(), $this->el(['labels' => ['Week 43 bookings']]));

        $this->assertSame($a, $b);
    }

    public function test_different_pages_differ(): void
    {
        $this->assertNotSame(
            ExplanationKey::for($this->page('a'), $this->el()),
            ExplanationKey::for($this->page('b'), $this->el()),
        );
    }

    public function test_explicit_key_survives_wording_but_not_another_page(): void
    {
        $this->assertSame(
            ExplanationKey::for($this->page('a'), $this->el(['explain_key' => 'fill-rate'])),
            ExplanationKey::for($this->page('a'), $this->el(['explain_key' => 'fill-rate', 'column' => 'Other', 'labels' => ['Renamed']])),
        );
        // Built from one page, it can't become another page's explanation.
        $this->assertNotSame(
            ExplanationKey::for($this->page('a'), $this->el(['explain_key' => 'fill-rate'])),
            ExplanationKey::for($this->page('b'), $this->el(['explain_key' => 'fill-rate'])),
        );
    }
}
