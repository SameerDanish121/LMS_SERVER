<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Models\task;
use App\Models\notification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;



class NotifyUpcomingTasks extends Command
{
    protected $signature = 'notify:tasks';
    protected $description = 'Push notifications for tasks due in 1 hour or 24 hours';
    public function handle()
    {
        $now = Carbon::now();
        Log::info('notify:tasks command triggered');
        $this->info('notify:tasks command started');
        // Get tasks due in next 25 hours (we'll filter inside)
        $tasks = task::with(['teacherOfferedCourse.teacher.user', 'teacherOfferedCourse.section'])
            ->where('isMarked', false)
            ->where('due_date', '>=', $now)
            ->where('due_date', '<=', $now->copy()->addHours(25))
            ->get();
        $this->info('Matching tasks: ' . $tasks->count());
        foreach ($tasks as $task) {
            $due = Carbon::parse($task->due_date);
            $diffInMinutes = $now->diffInMinutes($due, false);

            if ($diffInMinutes <= 0) {
                continue; // Already expired
            }
            $notificationsToPush = [];

            // ⏰ Push 24 hour notification
            if ($diffInMinutes <= (24 * 60) && $diffInMinutes > (60)) {
                $notificationsToPush[] = [
                    'title' => "⏰ Task '{$task->title}' due in 24 hours",
                    'description' => "Heads up! Task '{$task->title}' is due tomorrow.",
                    'type' => '24h'
                ];
            }

            // ⚠️ Push 1 hour notification
            if ($diffInMinutes <= 60) {
                $notificationsToPush[] = [
                    'title' => "⚠️ Task '{$task->title}' due in 1 hour",
                    'description' => "Hurry! Task '{$task->title}' is due in an hour.",
                    'type' => '1h'
                ];
            }
            foreach ($notificationsToPush as $item) {
                $alreadySent = notification::where('title', $item['title'])
                    ->exists();
                if ($alreadySent)
                    continue;
                $sectionId = $task->teacherOfferedCourse->section->id ?? null;
                $teacher = $task->teacherOfferedCourse->teacher ?? null;
                $tlSenderId = $teacher?->user?->id;
                if (!$sectionId || !$tlSenderId)
                    continue;
                notification::create([
                    'title' => $item['title'],
                    'description' => $item['description'],
                    'url' => null,
                    'sender' => $task->CreatedBy,
                    'reciever' => 'Student',
                    'Brodcast' => false,
                    'Student_Section' => $sectionId,
                    'TL_sender_id' => $tlSenderId,
                    'notification_date' => now()
                ]);
                $this->info("Notification pushed: {$item['title']}");
            }
        }
        return Command::SUCCESS;
    }
}
