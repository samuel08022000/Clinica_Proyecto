<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('clinical:maintenance slots')
    ->dailyAt('00:00')->timezone('America/La_Paz')->withoutOverlapping();
Schedule::command('clinical:maintenance reception')
    ->everyFiveMinutes()->timezone('America/La_Paz')->withoutOverlapping();
