<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fixed monthly charges are added on the 1st; running daily also catches up after downtime.
Schedule::command('leases:accrue')->dailyAt('00:15');
