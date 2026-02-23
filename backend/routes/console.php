<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// Jalankan auto-alpa setiap hari jam 17:01 sore
Schedule::command('brijisent:auto-alpa')->dailyAt('17:01');
