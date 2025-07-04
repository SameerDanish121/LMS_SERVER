<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Models\task;
use App\Models\notification;
use App\Models\student_task_result;
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
         $this->autoMarkMcqsTasks();
        return Command::SUCCESS;
    }
    public function autoMarkMcqsTasks()
    {
        $now = Carbon::now();
        $this->info('Started Auto-Marking Quizes ( MCQS ) , that are Not ATTEMPTED BY STUDENT ');
        $tasks = task::where('isMarked', 0)
            ->where('due_date', '<', $now)
            ->whereHas('courseContent', function ($query) {
                $query->where('content', 'MCQS');
            })
            ->get();
        $this->info('Matching tasks: ' . $tasks->count());
        foreach ($tasks as $task) {
            try {
                $sectionId = $task->getSectionIdByTaskId($task->id);

                if (!$sectionId) {
                    Log::warning("No section found for task ID {$task->id}");
                    continue;
                }

                $students = \App\Models\Student::select('student.id', 'student.name', 'student.RegNo')
                    ->join('student_offered_courses', 'student.id', '=', 'student_offered_courses.student_id')
                    ->join('offered_courses', 'student_offered_courses.offered_course_id', '=', 'offered_courses.id')
                    ->where('student_offered_courses.section_id', $sectionId)
                    ->where('offered_courses.session_id', (new \App\Models\session())->getCurrentSessionId())
                    ->get();

                $markedCount = 0;

                foreach ($students as $student) {
                    $exists = student_task_result::where('Task_id', $task->id)
                        ->where('Student_id', $student->id)
                        ->exists();

                    if (!$exists) {
                        student_task_result::updateOrInsert(
                            ['Task_id' => $task->id, 'Student_id' => $student->id],
                            ['ObtainedMarks' => 0]
                        );
                        $markedCount++;
                    }
                }

                $task->isMarked = 1;
                $task->save();

                $this->info("✅ Task '{$task->title}' marked 0 for $markedCount student(s).");

            } catch (\Exception $e) {
                Log::error("❌ Error handling task ID {$task->id}: " . $e->getMessage());
            }
        }
    }
}
