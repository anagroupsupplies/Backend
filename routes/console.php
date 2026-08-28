<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Release escrowed funds once each buyer's inspection window has elapsed.
| withoutOverlapping keeps a slow run from being started twice.
*/
Schedule::command('escrow:release-due')->hourly()->withoutOverlapping();
