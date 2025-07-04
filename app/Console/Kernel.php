<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
   
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('notify:tasks')->everyMinute(); // or ->everyFiveMinutes()
    }
// $schedule->command('email:reminders')->dailyAt('09:00');
//     $schedule->command('records:clean')->weekly();
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
