<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fixed monthly charges are added on the 1st; running daily also catches up after downtime.
Schedule::command('leases:accrue')->dailyAt('00:15');

// One e-mail per lease a day: everything that changed since the last one, sent after 16:00.
Schedule::command('tenants:notify')->dailyAt('16:00')->timezone('Europe/Warsaw');
