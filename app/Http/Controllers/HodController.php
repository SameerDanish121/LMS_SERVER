<?php

namespace App\Http\Controllers;
use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\Course;
use App\Models\coursecontent;
use App\Models\coursecontent_topic;
use App\Models\datacell;
use App\Models\Director;
use App\Models\exam;
use App\Models\FileHandler;
use App\Models\Hod;
use App\Models\juniorlecturer;
use App\Models\offered_courses;
use App\Models\options;
use App\Models\program;
use App\Models\question;
use App\Models\quiz_questions;
use App\Models\section;
use App\Models\session;
use App\Models\student;
use App\Models\student_exam_result;
use App\Models\student_offered_courses;
use App\Models\StudentManagement;
use App\Models\teacher;
use App\Models\teacher_juniorlecturer;
use App\Models\teacher_offered_courses;
use App\Models\topic;
use App\Models\user;
use DateTime;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class HodController extends Controller
{
    public function getAllCourseContents()
    {
        try {
            $result = [];
            $offeredCourses = offered_courses::with(['session', 'course'])
                ->whereHas('session') 
                ->get()
                ->sortByDesc(fn($course) => $course->session->start_date) // Sort by session start_date descending
                ->values(); // Reset keys

            foreach ($offeredCourses as $offeredCourse) {
                if (!$offeredCourse->course || !$offeredCourse->session) {
                    continue;
                }

                $sessionName = $offeredCourse->session->name . '-' . $offeredCourse->session->year;
                $courseName = $offeredCourse->course->name;

                if (!isset($result[$sessionName])) {
                    $result[$sessionName] = [];
                }

                if (!isset($result[$sessionName][$courseName])) {
                    $result[$sessionName][$courseName] = [];
                }

                $courseContents = coursecontent::where('offered_course_id', $offeredCourse->id)
                    ->orderBy('week')
                    ->get();

                if ($courseContents->isEmpty()) {
                    // Mark empty to show it's ready for content addition
                    $result[$sessionName][$courseName] = [];
                    continue;
                }

                foreach ($courseContents as $courseContent) {
                    $week = (int) $courseContent->week;

                    if (!isset($result[$sessionName][$courseName][$week])) {
                        $result[$sessionName][$courseName][$week] = [];
                    }

                    if ($courseContent->type === 'Notes') {
                        $courseContentTopics = coursecontent_topic::where('coursecontent_id', $courseContent->id)->get();
                        $topics = [];

                        foreach ($courseContentTopics as $courseContentTopic) {
                            $topic = topic::find($courseContentTopic->topic_id);
                            if ($topic) {
                                $topics[] = [
                                    'topic_id' => $topic->id,
                                    'topic_name' => $topic->title,
                                ];
                            }
                        }

                        $result[$sessionName][$courseName][$week][] = [
                            'course_content_id' => $courseContent->id,
                            'title' => $courseContent->title,
                            'type' => $courseContent->type,
                            'week' => $courseContent->week,
                            'File' => $courseContent->content ? asset($courseContent->content) : null,
                            'topics' => $topics,
                        ];
                    } else {
                        $result[$sessionName][$courseName][$week][] = [
                            'course_content_id' => $courseContent->id,
                            'title' => $courseContent->title,
                            'type' => $courseContent->content == 'MCQS' ? 'MCQS' : $courseContent->type,
                            'week' => $courseContent->week,
                            $courseContent->content == 'MCQS' ? 'MCQS' : 'File' =>
                                $courseContent->content == 'MCQS'
                                ? Action::getMCQS($courseContent->id)
                                : ($courseContent->content ? asset($courseContent->content) : null),
                        ];
                    }
                }
                ksort($result[$sessionName][$courseName]); // Sort weeks
            }
            return response()->json([
                'status' => true,
                'message' => 'Course content fetched successfully.',
                'data' => $result,
            ], 200);

        } catch (Exception $e) {
            Log::error('Error fetching course content: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while fetching course content.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function addCourse(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'code' => 'required|string|max:20',
                'name' => 'required|string|max:100',
                'credit_hours' => 'required|integer|min:1',
                'program_name' => 'required|string',
                'type' => 'nullable|string|max:50',
                'description' => 'nullable|string|max:255',
                'lab' => 'required|boolean',
                'pre_req_code' => 'nullable|string|max:20',
            ]);

            // Check if course with the exact code + name already exists
            $exists = Course::where('code', $validated['code'])
                ->where('name', $validated['name'])
                ->exists();

            if ($exists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Course already exists with the same code and name.',
                ], 409);
            }

            // Get program ID from name
            $program = program::where('name', $validated['program_name'])->first();
            if (!$program) {
                return response()->json([
                    'status' => false,
                    'message' => 'Program not found.',
                ], 404);
            }

            // Get prerequisite course ID if valid code is provided
            $preReqId = null;
            if (!empty($validated['pre_req_code'])) {
                $preReqId = Course::where('code', $validated['pre_req_code'])->value('id');
            }

            // Create new course
            $course = Course::create([
                'code' => $validated['code'],
                'name' => $validated['name'],
                'credit_hours' => $validated['credit_hours'],
                'pre_req_main' => $preReqId,
                'program_id' => $program->id,
                'type' => $validated['type'] ?? 'Core',
                'description' => $validated['description'] ?? null,
                'lab' => $validated['lab'],
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Course added successfully.',
                'data' => $course,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $ve->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error adding course: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Internal server error.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function AddSingleTeacher(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'date_of_birth' => 'required|date',
                'gender' => 'required|string',
                'cnic' => 'required',
                'email' => 'nullable',
                'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048'
            ]);

            $name = trim($request->input('name'));
            $dateOfBirth = $request->input('date_of_birth');
            $gender = $request->input('gender');
            $cnic = $request->input('cnic');
            $email = $request->input('email') ?? null;
            $username = strtolower(str_replace(' ', '', $name)) . '@biit.edu';
            if (teacher::where('cnic', $cnic)->exists()) {
                return response()->json([
                    'status' => 'failed',
                    'message' => "The teacher with cnic: {$cnic} already exists."
                ], 409);
            }

            $existingUser = user::where('username', $username)->first();
            if ($existingUser) {
                return response()->json([
                    'status' => 'failed',
                    'message' => "The teacher with username: {$username} already exists."
                ], 409);
            }

            $formattedDOB = (new DateTime($dateOfBirth))->format('Y-m-d');
            $password = Action::generateUniquePassword($name);
            $userId = Action::addOrUpdateUser($username, $password, $email, 'Teacher');

            if (!$userId) {
                return response()->json([
                    'status' => 'failed',
                    'message' => "Failed to create or update user for {$name}."
                ], 500);
            }

            $teacher = Teacher::create([
                'user_id' => $userId,
                'name' => $name,
                'date_of_birth' => $formattedDOB,
                'gender' => $gender,
                'email' => $email,
                'cnic' => $cnic
            ]);
            // Handle image upload only if it's provided
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $directory = 'Images/Teacher';
                $storedFilePath = FileHandler::storeFile($teacher->user_id, $directory, $image);
                $teacher->update(['image' => $storedFilePath]);
            }

            return response()->json([
                'status' => 'success',
                'message' => "The teacher with Name: {$name} was added.",
                'username' => $username,
                'password' => $password
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data not found'
            ], 404);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function AddSingleJunior(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'date_of_birth' => 'required|date',
                'gender' => 'required|string',
                'cnic' => 'required',
                'email' => 'nullable',
                'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048'
            ]);

            $name = trim($request->input('name'));
            $dateOfBirth = $request->input('date_of_birth');
            $gender = $request->input('gender');
            $cnic = $request->input('cnic');
            $email = $request->input('email') ?? null;
            $username = strtolower(str_replace(' ', '', $name)) . '_jl@biit.edu';
            if (juniorlecturer::where('cnic', $cnic)->exists()) {
                return response()->json([
                    'status' => 'failed',
                    'message' => "The J-LECTURER with cnic: {$cnic} already exists."
                ], 409);
            }

            $existingUser = user::where('username', $username)->first();
            if ($existingUser) {
                return response()->json([
                    'status' => 'failed',
                    'message' => "The teacher with username: {$username} already exists."
                ], 409);
            }

            $formattedDOB = (new DateTime($dateOfBirth))->format('Y-m-d');
            $password = Action::generateUniquePassword($name);
            $userId = Action::addOrUpdateUser($username, $password, $email, 'Teacher');

            if (!$userId) {
                return response()->json([
                    'status' => 'failed',
                    'message' => "Failed to create or update user for {$name}."
                ], 500);
            }

            $teacher = juniorlecturer::create([
                'user_id' => $userId,
                'name' => $name,
                'date_of_birth' => $formattedDOB,
                'gender' => $gender,
                'email' => $email,
                'cnic' => $cnic
            ]);
            // Handle image upload only if it's provided
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $directory = 'Images/JuniorLecturer';
                $storedFilePath = FileHandler::storeFile($teacher->user_id, $directory, $image);
                $teacher->update(['image' => $storedFilePath]);
            }

            return response()->json([
                'status' => 'success',
                'message' => "The teacher with Name: {$name} was added.",
                'username' => $username,
                'password' => $password
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data not found'
            ], 404);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function linkTopicsToCourseContent(Request $request)
    {
        try {
            // Validate request
            $request->validate([
                'coursecontent_id' => 'required',
                'topics' => 'required|array',
                'topics.*' => 'required|string'
            ]);
            $coursecontentId = $request->coursecontent_id;
            $topics = $request->topics;
            $linkedCount = 0;
            $alreadyLinked = [];
            foreach ($topics as $topicTitle) {
                $topicTitle = trim($topicTitle);
                if ($topicTitle == '')
                    continue;
                $topic = topic::firstOrCreate(['title' => $topicTitle]);
                $exists = coursecontent_topic::where('coursecontent_id', $coursecontentId)
                    ->where('topic_id', $topic->id)
                    ->exists();
                if (!$exists) {
                    // Link topic to course content
                    coursecontent_topic::create([
                        'coursecontent_id' => $coursecontentId,
                        'topic_id' => $topic->id
                    ]);
                    $linkedCount++;
                } else {
                    $alreadyLinked[] = $topicTitle;
                }
            }
            $message = "{$linkedCount} Topic" . ($linkedCount !== 1 ? 's' : '') . " linked with Course Content.";
            if (!empty($alreadyLinked)) {
                $message .= " And " . count($alreadyLinked) . " Topic" . (count($alreadyLinked) !== 1 ? 's are' : ' is') . " already linked: " . implode(", ", $alreadyLinked) . ".";
            }
            return response()->json(['message' => $message], 200);
        } catch (Exception $e) {
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }
    public function getGroupedOfferedCoursesBySession()
    {
        try {
            $groupedCourses = offered_courses::with(['course', 'session'])
                ->whereHas('course') // Ensure course exists
                ->whereHas('session') // Ensure session exists
                ->get()
                ->groupBy(function ($offeredCourse) {
                    $session = $offeredCourse->session;
                    return $session ? $session->name . '-' . $session->year : 'Unknown Session';
                });
            $sortedGroupedCourses = $groupedCourses->sortByDesc(function ($courses, $sessionName) {
                return optional($courses->first()->session)->start_date;
            });

            $result = [];

            foreach ($sortedGroupedCourses as $sessionName => $courses) {
                $courseList = [];
                $firstsession = '';

                foreach ($courses as $course) {
                    $NotesCount = coursecontent::where('offered_course_id', $course->id)->where('type', 'Notes')->count();
                    $TaskCount = coursecontent::where('offered_course_id', $course->id)->whereIn('type', ['Assignment', 'Quiz', 'LabTask'])->count();
                    $sections = student_offered_courses::where('offered_course_id', $course->id)
                        ->select('section_id')
                        ->distinct()
                        ->get()
                        ->map(function ($item) {
                            return [
                                'section_id' => $item->section_id,
                                'section_name' => (new section())->getNameByID($item->section->id),
                            ];
                        });
                    if ($course->course && $course->session) {
                        $firstsession = $course->session->id;
                        $courseList[] = [
                            'course' => $course->course->name . ' (' . $course->course->code . ')',
                            'offered_course_id' => $course->id,
                            'isLab' => $course->course->lab ? "Yes" : "No",
                            'course_id' => $course->course->id,
                            'Notes_Count' => $NotesCount,
                            'Task_Count' => $TaskCount,
                            'Enroll_Sections' => $sections
                        ];
                    }
                }

                if (!empty($courseList)) {
                    $result[] = [
                        'session_id' => $firstsession,
                        'session' => $sessionName,
                        'courses' => $courseList
                    ];
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Offered courses grouped by session retrieved successfully.',
                'data' => $result
            ], 200);
        } catch (Exception $e) {
            Log::error('Error fetching grouped offered courses: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch offered courses.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function AddMultipleCourseContents(Request $request)
    {
        $results = [];
        $errors = [];

        foreach ($request->all() as $index => $item) {
            try {
                $validated = Validator::make($item, [
                    'week' => 'required|integer',
                    'offered_course_id' => 'required|exists:offered_courses,id',
                    'type' => 'required|in:Quiz,Assignment,Notes,LabTask,MCQS',
                    'file' => 'nullable|file',
                    'MCQS' => 'nullable|array'
                ])->validate();

                $week = $item['week'];
                $type = $item['type'];
                $offered_course_id = $item['offered_course_id'];
                $offeredCourse = offered_courses::with(['course', 'session'])->find($offered_course_id);

                if (!$offeredCourse) {
                    throw new Exception("Offered course not found.");
                }

                $weekNo = (int) $week;
                $title = $offeredCourse->course->description . '-Week' . $weekNo;

                if (in_array($type, ['Quiz', 'Assignment', 'Notes', 'LabTask'])) {
                    if ($type === 'Notes') {
                        if (
                            coursecontent::where('type', $type)
                                ->where('offered_course_id', $offered_course_id)
                                ->where('week', $week)
                                ->whereNotNull('content')
                                ->exists()
                        ) {
                            throw new Exception("Week Notes already uploaded for Week $week by another teacher.");
                        }

                        $lecNo = ($weekNo * 2) - 1;
                        $lecNo1 = $weekNo * 2;
                        $title .= '-Lec-' . $lecNo . '-' . $lecNo1;
                    } else {
                        $existingCount = coursecontent::where('week', $weekNo)
                            ->where('offered_course_id', $offeredCourse->id)
                            ->where('type', $type)
                            ->count();
                        $title .= '-' . $type . '-' . ($existingCount + 1) . ')';
                    }

                    $title = preg_replace('/[^A-Za-z0-9._-]/', '_', $title);

                    if (!isset($item['file'])) {
                        throw new Exception("File is required for type $type.");
                    }

                    $file = $item['file'];

                    if (!in_array($file->getClientOriginalExtension(), ['pdf', 'doc', 'docx'])) {
                        throw new Exception("Invalid file format. Only pdf, doc, docx allowed.");
                    }

                    $directory = $offeredCourse->session->name . '-' . $offeredCourse->session->year . '/CourseContent/' . $offeredCourse->course->description;
                    $filePath = FileHandler::storeFile($title, $directory, $file);

                    coursecontent::updateOrCreate(
                        [
                            'week' => $weekNo,
                            'offered_course_id' => $offeredCourse->id,
                            'type' => $type,
                            'title' => $title,
                        ],
                        [
                            'content' => $filePath,
                        ]
                    );

                    $results[] = "[$index] Success: $type uploaded for Week $week with title: $title";
                } else if ($type == 'MCQS') {
                    if (!isset($item['MCQS']) || !is_array($item['MCQS'])) {
                        throw new Exception("MCQS array is missing or invalid.");
                    }

                    $type = 'Quiz';
                    $content = 'MCQS';
                    $existingCount = coursecontent::where('week', $weekNo)
                        ->where('offered_course_id', $offeredCourse->id)
                        ->where('type', $type)
                        ->count();
                    $title .= '-' . $type . '-(' . ($existingCount + 1) . ')';

                    $courseContent = coursecontent::updateOrCreate(
                        [
                            'week' => $weekNo,
                            'offered_course_id' => $offeredCourse->id,
                            'type' => $type,
                            'title' => $title,
                            'content' => $content,
                        ]
                    );

                    foreach ($item['MCQS'] as $mcq) {
                        $questionNo = $mcq['qNO'] ?? null;
                        $questionText = $mcq['question_text'] ?? null;
                        $points = $mcq['points'] ?? null;
                        $options = [
                            $mcq['option1'] ?? null,
                            $mcq['option2'] ?? null,
                            $mcq['option3'] ?? null,
                            $mcq['option4'] ?? null,
                        ];
                        $answer = $mcq['Answer'] ?? null;

                        if (!$questionNo || !$questionText || !$points || !$answer) {
                            throw new Exception("Incomplete MCQ data in record $index.");
                        }

                        $quizQuestion = quiz_questions::create([
                            'question_no' => $questionNo,
                            'question_text' => $questionText,
                            'points' => $points,
                            'coursecontent_id' => $courseContent->id,
                        ]);

                        foreach ($options as $optionText) {
                            if ($optionText) {
                                \App\Models\options::create([
                                    'quiz_question_id' => $quizQuestion->id,
                                    'option_text' => $optionText,
                                    'is_correct' => trim($optionText) === trim($answer),
                                ]);
                            }
                        }
                    }

                    $results[] = "[$index] Success: MCQS Quiz added for Week $week with title: $title";
                }

            } catch (ValidationException $e) {
                $errors[] = "[$index] Validation Error: " . implode(', ', array_map(function ($m) {
                    return implode(', ', $m);
                }, $e->errors()));
            } catch (Exception $e) {
                $errors[] = "[$index] Failed: " . $e->getMessage();
            }
        }

        return response()->json([
            'status' => 'completed',
            'success_messages' => $results,
            'error_messages' => $errors,
        ]);
    }
    public function updateContent(Request $request)
    {
        try {
            $request->validate([
                'coursecontent_id' => 'required',
                'file' => 'nullable|file',
                'MCQS' => 'nullable|string',
            ]);

            $courseContent = coursecontent::with(['offeredCourse.session', 'offeredCourse.course'])
                ->findOrFail($request->coursecontent_id);
            $type = $courseContent->type;

            if ($courseContent->content !== 'MCQS') {
                // Handle file-based update (non-MCQS)
                if (!$request->hasFile('file')) {
                    throw new Exception('File is required for update');
                }

                $file = $request->file('file');

                if (!in_array($file->getClientOriginalExtension(), ['pdf', 'doc', 'docx'])) {
                    throw new Exception('Unsupported file format. Allowed formats: pdf, doc, docx');
                }

                // Keep same file name and directory
                $directory = $courseContent->offeredCourse->session->name . '-' .
                    $courseContent->offeredCourse->session->year . '/CourseContent' . '/' .
                    $courseContent->offeredCourse->course->description;
                $title = $courseContent->title;

                // Delete old file if exists
                if ($courseContent->content && Storage::exists($courseContent->content)) {
                    Storage::delete($courseContent->content);
                }

                $newPath = FileHandler::storeFile($title, $directory, $file);

                $courseContent->update([
                    'content' => $newPath,
                ]);
            } else {
                // Handle MCQS update
                $mcqs = [];
                if (!empty($request->MCQS)) {
                    $mcqs = json_decode($request->MCQS, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new Exception('Invalid MCQS data format. Expected valid JSON.');
                    }
                }


                $quizQuestions = quiz_questions::where('coursecontent_id', $courseContent->id)->get();

                foreach ($quizQuestions as $question) {
                    // Delete related options manually
                    options::where('quiz_question_id', $question->id)->delete();

                    // Delete the question
                    $question->delete();
                }


                // Insert new MCQS if any
                if (!empty($mcqs)) {
                    foreach ($mcqs as $mcq) {
                        $questionNo = $mcq['qNO'] ?? null;
                        $questionText = $mcq['question_text'] ?? null;
                        $points = $mcq['points'] ?? null;
                        $options = [
                            'option1' => $mcq['option1'] ?? null,
                            'option2' => $mcq['option2'] ?? null,
                            'option3' => $mcq['option3'] ?? null,
                            'option4' => $mcq['option4'] ?? null,
                        ];
                        $answer = $mcq['Answer'] ?? null;

                        // Validate required fields
                        if (empty($questionNo) || empty($questionText) || empty($points) || empty($answer)) {
                            throw new Exception('Question number, text, points and answer are required for all questions');
                        }

                        // Validate at least 2 options are provided
                        $providedOptions = array_filter($options);
                        if (count($providedOptions) < 2) {
                            throw new Exception('At least two options are required for each question');
                        }

                        $quizQuestion = quiz_questions::create([
                            'question_no' => $questionNo,
                            'question_text' => $questionText,
                            'points' => $points,
                            'coursecontent_id' => $courseContent->id,
                        ]);

                        // Create options and mark correct one
                        foreach ($options as $key => $optionText) {
                            if (!empty($optionText)) {
                                options::create([
                                    'quiz_question_id' => $quizQuestion->id,
                                    'option_text' => $optionText,
                                    'is_correct' => ($key === $answer),
                                ]);
                            }
                        }
                    }
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => "Course content updated successfully.",
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'error' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function CopySemester(Request $request)
    {
        try {
            $validated = $request->validate([
                'course_id' => 'required',
                'source_session_id' => 'required',
                'destination_session_id' => 'required',
            ]);
            $courseName = $validated['course_id'];
            $sourceSessionName = $validated['source_session_id'];
            $destinationSessionName = $validated['destination_session_id'];
            $course = new Course();
            $course = course::find($courseName);
            if (!$course) {
                return response()->json(['error' => 'Course not found.'], 404);
            }
            $courseId = $course->id;
            $session = new Session();
            $sourceSessionId = $session->find($sourceSessionName)->id;
            $destinationSessionId = $session->find($destinationSessionName)->id;
            $destinationSessionName = $session->getSessionNameByID($destinationSessionId);
            $sourceSessionName = $session->getSessionIdByName($sourceSessionId);
            if (!$sourceSessionId || !$destinationSessionId) {
                return response()->json(['error' => 'Source or Destination session not found.'], 404);
            }
            $sourceOfferedCourse = offered_courses::where('course_id', $courseId)->where('session_id', $sourceSessionId)->first();
            $destinationOfferedCourse = offered_courses::where('course_id', $courseId)->where('session_id', $destinationSessionId)->first();

            if (!$sourceOfferedCourse || !$destinationOfferedCourse) {
                return response()->json(['error' => 'Course not offered in the source or destination session.'], 404);
            }
            $courseContents = coursecontent::where('offered_course_id', $sourceOfferedCourse->id)->get();
            $successfullyCopied = [];
            $errors = [];
            foreach ($courseContents as $content) {
                $newContentData = $content->toArray();
                unset($newContentData['id'], $newContentData['offered_course_id']);
                $newContentData['offered_course_id'] = $destinationOfferedCourse->id;
                $existingContent = coursecontent::where('title', $content->title)
                    ->where('type', $content->type)
                    ->where('week', $content->week)
                    ->where('offered_course_id', $destinationOfferedCourse->id)
                    ->first();

                if ($existingContent) {
                    $successfullyCopied[] = [
                        'status' => 'skipped',
                        'message' => "Content '{$content->title}' (Type: {$content->type}, Week: {$content->week}) already exists in the destination session."
                    ];
                    continue;
                }
                try {
                    $newContent = coursecontent::create($newContentData);
                    $newContentId = $newContent->id;
                    if ($content->type === 'Quiz' && $content->content === 'MCQS') {
                        $mcqs = quiz_questions::where('coursecontent_id', $content->id)->get();
                        foreach ($mcqs as $mcq) {
                            $newQuizQuestion = quiz_questions::create([
                                'question_no' => $mcq->question_no,
                                'question_text' => $mcq->question_text,
                                'points' => $mcq->points,
                                'coursecontent_id' => $newContentId,
                            ]);
                            foreach ($mcq->options as $option) {
                                options::create([
                                    'quiz_question_id' => $newQuizQuestion->id,
                                    'option_text' => $option->option_text,
                                    'is_correct' => $option->is_correct,
                                ]);
                            }

                        }
                        $successfullyCopied[] = [
                            'status' => 'success',
                            'message' => "Successfully copied content '{$content->title}' (ID: {$content->id})."
                        ];
                        continue;
                    }
                    if (in_array($content->type, ['Notes', 'Quiz', 'Assignment', 'LabTask']) && $content->content) {
                        $directory = "{$destinationSessionName}/CourseContent/{$course->description}";
                        $newContentPath = FileHandler::copyFileToDestination($content->content, $content->title, $directory);
                        if (!$newContentPath) {
                            $errors[] = [
                                'status' => 'error',
                                'message' => "Failed to copy file for content ID {$content->id}."
                            ];
                            continue;
                        }
                        $newContent->content = $newContentPath;
                        $newContent->save();
                        if ($content->type === 'Notes') {
                            $existingTopics = coursecontent_topic::where('coursecontent_id', $content->id)->get();
                            foreach ($existingTopics as $topic) {
                                $data = [
                                    'coursecontent_id' => $newContent->id,
                                    'topic_id' => $topic->topic_id,
                                ];
                                $updatedTopic = coursecontent_topic::updateOrCreate(
                                    [
                                        'coursecontent_id' => $newContent->id,
                                        'topic_id' => $topic->topic_id
                                    ]
                                );
                            }
                        }
                    }
                    $successfullyCopied[] = [
                        'status' => 'success',
                        'message' => "Successfully copied content '{$content->title}' (ID: {$content->id})."
                    ];
                } catch (Exception $e) {
                    $errors[] = [
                        'status' => 'error',
                        'message' => "Failed to copy content ID {$content->id}: {$e->getMessage()}."
                    ];
                }
            }
            return response()->json([
                'message' => 'Course content duplication completed.',
                'success' => $successfullyCopied,
                'errors' => $errors,
            ], 200);
        } catch (Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function getExamGroupedBySession()
    {
        try {
            $offeredCourses = offered_courses::with([
                'course:id,name,code,description,lab',
                'session:id,name,year,start_date',
                'exams.questions' // Note: must match the relationship name
            ])->get();

            if ($offeredCourses->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No offered courses found.',
                    'data' => []
                ], 404);
            }

            $grouped = [];

            foreach ($offeredCourses as $course) {
                if (!$course->session)
                    continue;

                $sessionFullName = $course->session->name . '-' . $course->session->year;

                if (!isset($grouped[$sessionFullName])) {
                    $grouped[$sessionFullName] = [
                        'session_id' => $course->session->id,
                        'start_date' => $course->session->start_date,
                        'courses' => []
                    ];
                }

                $midExam = $course->exams->where('type', 'Mid')->first();
                $finalExam = $course->exams->where('type', 'Final')->first();

                // Helper function to transform the exam structure
                $transformExam = function ($exam, $type) {
                    if (!$exam) {
                        return null;
                    }

                    $examArray = [
                        'exam_id' => $exam->id,
                        'type' => $exam->type,
                        'total_marks' => $exam->total_marks,
                        'Solid_marks' => $exam->Solid_marks,
                        'QuestionPaper' => $exam->QuestionPaper ? asset($exam->QuestionPaper) : null,
                        'questions' => $exam->questions->map(function ($q) {
                            return [
                                'question_id' => $q->id,
                                'q_no' => $q->q_no,
                                'marks' => $q->marks
                            ];
                        })->toArray()
                    ];

                    // If all fields are null/empty, return null
                    if (
                        is_null($examArray['exam_id']) &&
                        is_null($examArray['total_marks']) &&
                        is_null($examArray['Solid_marks']) &&
                        is_null($examArray['QuestionPaper']) &&
                        empty($examArray['questions'])
                    ) {
                        return null;
                    }

                    return $examArray;
                };

                $grouped[$sessionFullName]['courses'][] = [
                    'offered_course_id' => $course->id,
                    'course_name' => $course->course->name ?? '',
                    'course_code' => $course->course->code ?? '',
                    'lab' => isset($course->course->lab) && $course->course->lab ? 'Yes' : 'No',
                    'description' => $course->course->description ?? '',
                    'Mid' => $transformExam($midExam, 'Mid'),
                    'Final' => $transformExam($finalExam, 'Final'),
                ];
            }

            $sorted = collect($grouped)->sortByDesc('start_date')->map(function ($group, $key) {
                unset($group['start_date']);
                return [
                    'session_name' => $key,
                    'session_id' => $group['session_id'],
                    'courses' => $group['courses']
                ];
            })->values();

            return response()->json([
                'status' => true,
                'message' => 'Courses grouped by session',
                'data' => $sorted
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error occurred while fetching data.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function UpdateExam(Request $request)
    {
        try {
            $request->validate([
                'exam_id' => 'required|exists:exam,id',
                'Solid_marks' => 'required|integer',
                'QuestionPaper' => 'nullable|file|mimes:pdf',
                'questions' => 'nullable|array',
                'questions.*.q_no' => 'required_with:questions|integer',
                'questions.*.marks' => 'required_with:questions|integer'
            ]);

            $exam = exam::with(['offeredCourse.course', 'offeredCourse.session'])->find($request->exam_id);

            if (!$exam) {
                throw new Exception('Exam not found');
            }

            // Check if exam already has student results
            $hasResults = student_exam_result::where('exam_id', $exam->id)->exists();
            if ($hasResults) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot update exam. Student results already uploaded.',
                ], 403);
            }
            $exam->Solid_marks = $request->Solid_marks;
            if ($request->hasFile('QuestionPaper')) {
                $questionPaperFile = $request->file('QuestionPaper');

                $course_name = $exam->offered_course->course->name;
                $session_name = $exam->offered_course->session->name . '-' . $exam->offered_course->session->year;
                $directory = $session_name . '/Exam/' . $exam->type;
                $madeupname = $course_name . '_' . $session_name . '_' . $exam->type;

                $path = FileHandler::storeFile($madeupname, $directory, $questionPaperFile);
                $exam->QuestionPaper = $path;
            }

            // Update questions if provided
            $totalMarks = 0;
            if ($request->has('questions') && is_array($request->questions)) {
                // Delete old questions
                question::where('exam_id', $exam->id)->delete();

                // Insert new questions
                $questionsData = [];
                foreach ($request->questions as $q) {
                    $questionsData[] = [
                        'q_no' => $q['q_no'],
                        'marks' => $q['marks'],
                        'exam_id' => $exam->id
                    ];
                    $totalMarks += $q['marks'];
                }

                if (!empty($questionsData)) {
                    question::insert($questionsData);
                }
            } else {
                // Keep the existing total marks if questions are not updated
                $totalMarks = question::where('exam_id', $exam->id)->sum('marks');
            }

            $exam->total_marks = $totalMarks;
            $exam->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Exam updated successfully!',
                'Exam' => $exam
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getCourseAllocationHistory(Request $request)
    {
        try {
            // Fetch offered courses with related course and session data
            $offeredCourses = offered_courses::with([
                'course:id,name,code,description,lab',
                'session:id,name,year,start_date',
                'teacherOfferedCourses.teacher:id,name,image',
                'teacherOfferedCourses.section:id,program,semester,group',
                'teacherOfferedCourses.teacherJuniorLecturer.juniorLecturer:id,name,image',
                'studentOfferedCourses',
            ])->get();

            // Group offered courses by session (name-year), sorted by session start_date descending
            $groupedData = $offeredCourses->groupBy(function ($offeredCourse) {
                $session = $offeredCourse->session;
                return $session ? "{$session->name}-{$session->year}" : 'Unknown Session';
            })->sortByDesc(function ($group, $key) use ($offeredCourses) {
                [$name, $year] = explode('-', $key) + [null, null];

                $matchingCourse = $offeredCourses->first(function ($course) use ($name, $year) {
                    return $course->session &&
                        $course->session->name === $name &&
                        $course->session->year == $year;
                });

                return $matchingCourse && $matchingCourse->session
                    ? $matchingCourse->session->start_date
                    : now();
            });


            $response = [];

            foreach ($groupedData as $sessionKey => $courses) {
                $sessionCourses = [];

                foreach ($courses as $offeredCourse) {
                    $course = $offeredCourse->course;
                    $enrollmentCount = $offeredCourse->studentOfferedCourses->count();
                    $teacherAllocations = [];
                    foreach ($offeredCourse->teacherOfferedCourses as $teacherOfferedCourse) {
                        $teacher = $teacherOfferedCourse->teacher;
                        $section = $teacherOfferedCourse->section;

                        $allocation = [
                            'id' => $teacherOfferedCourse->id,
                            'section_name' => $section ? (new section())->getNameByID($section->id) : null,
                            'enrollment_in_section' => $section ? (student_offered_courses::where('section_id', $section->id)->where('offered_course_id', $offeredCourse->id)->count()) : 0,
                            'teacher_name' => $teacher ? $teacher->name : null,
                            'teacher_image' => $teacher ? ($teacher->image ? asset($teacher->image) : null) : null,
                            'isLab' => $course->lab ? 'Lab' : 'Non-Lab',
                            'SessionName' => $offeredCourse->session->name . '-' . $offeredCourse->session->year,
                            'CourseName' => $course->name,
                            'CourseCode' => $course->code,
                        ];
                        if ($course && $course->lab) {
                            $juniorLecturer = optional($teacherOfferedCourse->teacherJuniorLecturer)->juniorLecturer;
                            $allocation['junior_allocation'] = $juniorLecturer ? [
                                'teacher_juniorlecturer_id' => optional($teacherOfferedCourse->teacherJuniorLecturer)->id ?: null,
                                'name' => $juniorLecturer->name,
                                'image' => $juniorLecturer->image,
                            ] : null;
                        }

                        $teacherAllocations[] = $allocation;
                    }

                    $sessionCourses[] = [
                        'course' => [
                            'id' => $offeredCourse->id,
                            'name' => $course ? $course->name : null,
                            'code' => $course ? $course->code : null,
                            'description' => $course ? $course->description : null,
                            'lab' => $course ? $course->lab : null,
                        ],
                        'enrollment_count' => $enrollmentCount,
                        'teacher_allocations' => $teacherAllocations,
                    ];
                }

                $response[$sessionKey] = $sessionCourses;
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Course allocation history retrieved successfully!',
                'data' => $response,
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function addTeacherOfferedCourse(Request $request)
    {
        try {
            $validated = $request->validate([
                'offered_course_id' => 'required|integer|exists:offered_courses,id',
                'teacher_id' => 'required|integer|exists:teacher,id',
                'section_id' => 'required|integer|exists:section,id',
            ]);
            $offeredCourseId = $validated['offered_course_id'];
            $teacherId = $validated['teacher_id'];
            $sectionId = $validated['section_id'];
            $duplicateFull = teacher_offered_courses::where([
                'offered_course_id' => $offeredCourseId,
                'teacher_id' => $teacherId,
                'section_id' => $sectionId,
            ])->first();
            if ($duplicateFull) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Duplicate entry: This teacher is already assigned to this course-section.',
                ], 409);
            }
            $duplicateSectionCourse = teacher_offered_courses::where([
                'offered_course_id' => $offeredCourseId,
                'section_id' => $sectionId,
            ])->first();
            if ($duplicateSectionCourse) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Duplicate entry: This course is already assigned to the selected section.',
                ], 409);
            }
            $newEntry = teacher_offered_courses::create([
                'offered_course_id' => $offeredCourseId,
                'teacher_id' => $teacherId,
                'section_id' => $sectionId,
            ]);
            return response()->json([
                'status' => 'success',
                'message' => 'Teacher assigned to course-section successfully.',
                'data' => $newEntry,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function updateTeacher(Request $request)
    {
        $validated = $request->validate([
            'teacher_offered_course_id' => 'required|exists:teacher_offered_courses,id',
            'teacher_id' => 'required|exists:teacher,id',
        ]);
        try {
            $record = teacher_offered_courses::find($validated['teacher_offered_course_id']);
            $duplicate = teacher_offered_courses::where('offered_course_id', $record->offered_course_id)
                ->where('section_id', $record->section_id)
                ->where('teacher_id', $validated['teacher_id'])
                ->where('id', '!=', $record->id)
                ->exists();
            if ($duplicate) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Duplicate entry: This teacher is already assigned to this course-section.'
                ], 409);
            }

            $record->teacher_id = $validated['teacher_id'];
            $record->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Teacher updated successfully.',
                'data' => $record
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while updating teacher.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function assignOrUpdateJuniorLecturer(Request $request)
    {
        $validated = $request->validate([
            'teacher_offered_course_id' => 'required|exists:teacher_offered_courses,id',
            'junior_id' => 'required|exists:juniorlecturer,id',
        ]);

        try {
            $record = teacher_juniorlecturer::where('teacher_offered_course_id', $validated['teacher_offered_course_id'])->first();
            if ($record) {
                $record->juniorlecturer_id = $validated['junior_id'];
                $record->save();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Junior Lecturer updated successfully.',
                    'data' => $record
                ]);
            } else {
                $newRecord = teacher_juniorlecturer::create([
                    'teacher_offered_course_id' => $validated['teacher_offered_course_id'],
                    'juniorlecturer_id' => $validated['junior_id']
                ]);
                return response()->json([
                    'status' => 'success',
                    'message' => 'Junior Lecturer assigned successfully.',
                    'data' => $newRecord
                ]);
            }
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while assigning Junior Lecturer.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function updateHODInfo(Request $request, $hod_id)
    {
        try {
            // Fetch HOD with linked user
            $hod = Hod::with('user')->find($hod_id);

            if (!$hod || !$hod->user) {
                return response()->json(['error' => 'HOD or associated user not found'], 404);
            }

            // Validate input
            $validated = $request->validate([
                'email' => 'nullable|email|unique:user,email,',
                'password' => 'nullable',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,svg|max:2048',
            ]);

            // Update email
            if ($request->filled('email')) {
                $hod->user->email = $request->email;
            }

            // Update password
            if ($request->filled('password')) {
                $hod->user->password = $request->password; // secure hash
            }

            // Update image
            if ($request->hasFile('image')) {
                if ($hod->image && Storage::exists($hod->image)) {
                    Storage::delete($hod->image);
                }

                $directory = 'Images/HOD';
                $storedFilePath = FileHandler::storeFile($hod->user_id, $directory, $request->file('image'));
                $hod->image = $storedFilePath;
            }
            $hod->user->save();
            $hod->save();
            return response()->json(['message' => 'HOD info updated successfully'], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => 'Validation error', 'messages' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json(['error' => 'An error occurred', 'details' => $e->getMessage()], 500);
        }
    }
}

