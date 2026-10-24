<?php

namespace Atoria\Alfred\Tests\Fixtures\App\Http\Controllers;

use Atoria\Alfred\Tests\Fixtures\App\Models\Shift;

class DashboardController
{
    public function index()
    {
        $booked = Shift::query()->booked()->count();
        $total = Shift::query()->count();

        return view('fixture::dashboard', [
            'fillRate' => $total ? round($booked / $total * 100) : 0,
        ]);
    }

    public function json()
    {
        return response()->json(['booked' => Shift::query()->booked()->count()]);
    }
}
