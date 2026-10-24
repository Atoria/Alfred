<?php

namespace Atoria\Alfred\Tests\Unit;

use Atoria\Alfred\Explanations\SqlRewriter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SqlRewriterTest extends TestCase
{
    public function test_count_becomes_rows(): void
    {
        $r = (new SqlRewriter)->toRows('select count(*) as aggregate from "shifts" where "status" = ?', ['booked'], 50);

        $this->assertSame('select * from "shifts" where "status" = ? limit 50', $r['sql']);
        $this->assertSame(['booked'], $r['bindings']);
        $this->assertTrue($r['aggregate']);
    }

    public function test_placeholders_in_removed_select_list_are_dropped(): void
    {
        $r = (new SqlRewriter)->toRows(
            'select sum(case when status = ? then 1 else 0 end) as booked from shifts where ward = ?',
            ['booked', 'A'],
            10,
        );

        $this->assertSame('select * from shifts where ward = ? limit 10', $r['sql']);
        $this->assertSame(['A'], $r['bindings']);
    }

    public function test_grouped_aggregates_are_kept(): void
    {
        $r = (new SqlRewriter)->toRows('select ward, count(*) as n from shifts group by ward order by n desc limit 5', [], 20);

        $this->assertSame('select ward, count(*) as n from shifts group by ward order by n desc limit 20', $r['sql']);
        $this->assertFalse($r['aggregate']);
    }

    public function test_subquery_from_does_not_confuse_it(): void
    {
        $r = (new SqlRewriter)->toRows("select count(*) as aggregate from shifts where id in (select shift_id from bookings where note = 'from x')", [], 5);

        $this->assertStringStartsWith('select * from shifts where id in (select shift_id from bookings', $r['sql']);
    }

    public function test_distinct_count_keeps_distinct(): void
    {
        $r = (new SqlRewriter)->toRows('select count(distinct ward) as aggregate from shifts', [], 5);

        $this->assertSame('select * from shifts limit 5', $r['sql']);
    }

    public static function unsafe(): array
    {
        return [
            ['delete from shifts'],
            ['update shifts set status = 1'],
            ['select * from shifts; drop table shifts'],
            ['select * into outfile \'/tmp/x\' from shifts'],
            ['select * from shifts for update'],
            ['select pg_sleep(10) from shifts'],
            ['with x as (delete from shifts returning *) select * from x'],
        ];
    }

    #[DataProvider('unsafe')]
    public function test_rejects_unsafe(string $sql): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SqlRewriter)->toRows($sql, [], 10);
    }

    public function test_keywords_inside_strings_are_fine(): void
    {
        $r = (new SqlRewriter)->toRows("select * from shifts where note = 'please update; delete later'", [], 3);

        $this->assertSame("select * from shifts where note = 'please update; delete later' limit 3", $r['sql']);
    }
}
