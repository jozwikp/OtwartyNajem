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

// Queue worker for shared hosting, where nothing can supervise a long-running process: cron starts
// one every minute and it works for up to 55 s. The lock makes sure only one runs at a time, even
// while a slow job (e.g. reading an invoice) keeps it busy past the minute; it's released after
// 10 minutes should the worker die.
Schedule::command('queue:work --max-time=55 --sleep=3 --memory=128')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();
