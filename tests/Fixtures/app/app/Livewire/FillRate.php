<?php

namespace Atoria\Alfred\Tests\Fixtures\App\Livewire;

use Atoria\Alfred\Tests\Fixtures\App\Models\Shift;
use Livewire\Component;

class FillRate extends Component
{
    public function render()
    {
        $booked = Shift::query()->booked()->count();

        return <<<'BLADE'
            <div><h3>Booked shifts</h3><span>{{ $this->count }}</span></div>
        BLADE;
    }

    public function getCountProperty(): int
    {
        return Shift::query()->booked()->count();
    }
}
