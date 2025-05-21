<?php

namespace App\Http\Controllers;

use App\Models\attendance;
use App\Models\contested_attendance;
use App\Models\excluded_days;
use App\Models\grader_requests;
use App\Models\notification;
use App\Models\offered_courses;
use App\Models\program;
use App\Models\role;
use App\Models\section;
use App\Models\session;
use App\Models\student;
use App\Models\student_exam_result;
use App\Models\student_offered_courses;
use App\Models\task;
use App\Models\teacher_grader;
use App\Models\teacher_offered_courses;
use DateTime;
use Exception;
use App\Models\user;
use App\Models\admin;
use App\Models\Action;
use App\Models\Course;
use App\Models\teacher;
use App\Models\datacell;
use App\Models\Director;
use App\Models\FileHandler;
use App\Models\Hod;
use Illuminate\Http\Request;
use App\Models\juniorlecturer;
use App\Models\StudentManagement;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\subjectresult;
class SingleInsertionController extends Controller
{
        public function storeOfferedCourse(Request $request)
    {
        $request->validate([
            'session_id' => 'required|exists:session,id',
            'course_id' => 'required|exists:course,id',
        ]);

        try {
            // Check if already offered in this session
            $exists = offered_courses::where('session_id', $request->session_id)
                ->where('course_id', $request->course_id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This course is already offered in the selected session.'
                ], 409); // 409 Conflict
            }

            // Create the offered course
            $offered = offered_courses::create([
                'session_id' => $request->session_id,
                'course_id' => $request->course_id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Course offered successfully.',
                'data' => $offered
            ],200);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to offer course.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getUnOfferedCoursesAcrossAllSessions()
    {
        try {
            $sessions = Session::orderByDesc('start_date')->get();
            $result = [];

            foreach ($sessions as $session) {
                $sessionKey = $session->name . '-' . $session->year;

                $offeredCourseIds = offered_courses::where('session_id', $session->id)
                    ->pluck('course_id');

                $unofferedCourses = Course::whereNotIn('id', $offeredCourseIds)->get();
                $totalOfferedCount = $offeredCourseIds->count();

                $sessionEntry = [
                    'session_id' => $session->id,
                    'session_name' => $sessionKey,
                    'session_date_start' => $session->start_date,
                    'total_offered_courses' => $totalOfferedCount,
                    'courses' => []
                ];

                foreach ($unofferedCourses as $course) {
                    $courseEntry = [
                        'course_id' => $course->id,
                        'course_name' => $course->name,
                        'course_code' => $course->code,
                        'isLab' => $course->lab ? 'Yes' : 'No',
                        'credit_hours' => $course->credit_hours,
                        'short_form' => $course->description,
                        'sections' => [] // Not offered, so no sections
                    ];
                    $sessionEntry['courses'][] = $courseEntry;
                }

                $result[] = $sessionEntry;
            }

            return response()->json([
                'status' => 'success',
                'data' => $result
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve unoffered courses.',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    public function deleteEnrollment(Request $request)
    {
        $request->validate([
            'student_offered_course_id' => 'required|integer',
        ]);

        try {
            $enrollment = student_offered_courses::findOrFail($request->student_offered_course_id);
            $student_id = $enrollment->student_id;
            $offered_course_id = $enrollment->offered_course_id;

            // Try to get teacher_offered_course
            $teacherOffered = teacher_offered_courses::where('offered_course_id', $offered_course_id)->first();
            $teacher_offered_course_id = $teacherOffered ? $teacherOffered->id : null;

            DB::beginTransaction();

            // 1. Delete student exam results
            DB::table('student_exam_result')
                ->where('student_id', $student_id)
                ->whereIn('exam_id', function ($q) use ($offered_course_id) {
                    $q->select('id')->from('exam')->where('offered_course_id', $offered_course_id);
                })->delete();

            // 2. Delete attendance sheet sequence (if teacher offered course found)
            if ($teacher_offered_course_id) {
                DB::table('attendance_sheet_sequence')
                    ->where('student_id', $student_id)
                    ->where('teacher_offered_course_id', $teacher_offered_course_id)
                    ->delete();
            }

            // 3. Delete contested attendance (through attendance)
            $attendanceIds = attendance::where('student_id', $student_id)
                ->when($teacher_offered_course_id, fn($q) => $q->where('teacher_offered_course_id', $teacher_offered_course_id))
                ->pluck('id');

            if ($attendanceIds->count()) {
                contested_attendance::whereIn('Attendance_id', $attendanceIds)->delete();
            }

            // 4. Delete attendance
            attendance::where('student_id', $student_id)
                ->when($teacher_offered_course_id, fn($q) => $q->where('teacher_offered_course_id', $teacher_offered_course_id))
                ->delete();

            // 5. Delete task results and submissions
            if ($teacher_offered_course_id) {
                $taskIds = task::where('teacher_offered_course_id', $teacher_offered_course_id)->pluck('id');

                DB::table('student_task_result')
                    ->where('Student_id', $student_id)
                    ->whereIn('Task_id', $taskIds)
                    ->delete();

                DB::table('student_task_submission')
                    ->where('Student_id', $student_id)
                    ->whereIn('Task_id', $taskIds)
                    ->delete();
            }

            // 6. Delete the actual enrollment
            $enrollment->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Student enrollment and related records Removed successfully.',
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Deletion failed: ' . $e->getMessage(),
            ], 500);
        }
    }
    public function updateEnrollmentSection(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required|integer',
                'section_id' => 'required|integer',
            ]);
            $record = student_offered_courses::find($request->id);

            if (!$record) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Enrollment record not found.'
                ], 404);
            }

            if ($record->section_id == $request->section_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Student is already in this section.'
                ], 409);
            }

            $record->section_id = $request->section_id;
            $record->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Enrollment section updated successfully.',
                'data' => [
                    'id' => $record->id,
                    'student_id' => $record->student_id,
                    'offered_course_id' => $record->offered_course_id,
                    'new_section_id' => $record->section_id,
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function enrollStudent(Request $request)
    {
        try {
            $request->validate([
                'student_id' => 'required|integer',
                'offered_course_id' => 'required|integer',
                'section_id' => 'required|integer',
            ]);

            $studentId = $request->student_id;
            $offeredCourseId = $request->offered_course_id;
            $sectionId = $request->section_id;

            // Fetch course_id from offered_course
            $offeredCourse = offered_courses::find($offeredCourseId);
            if (!$offeredCourse) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Offered course not found.'
                ], 404);
            }

            $courseId = $offeredCourse->course_id;

            // Scenario 1: Same student, course and section exists
            $existsExact = student_offered_courses::where('student_id', $studentId)
                ->where('offered_course_id', $offeredCourseId)
                ->where('section_id', $sectionId)
                ->first();

            if ($existsExact) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Student is already enrolled in this subject.'
                ], 409);
            }

            // Scenario 2: Same student & course, different section
            $existsDiffSection = student_offered_courses::where('student_id', $studentId)
                ->where('offered_course_id', $offeredCourseId)
                ->where('section_id', '!=', $sectionId)
                ->first();

            if ($existsDiffSection) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Student is already enrolled in this subject in a different section.'
                ], 409);
            }

            // Scenario 3 & 4: Check by course_id (previous attempts)
            $existingAttempt = student_offered_courses::where('student_id', $studentId)
                ->whereHas('offeredCourse', function ($query) use ($courseId) {
                    $query->where('course_id', $courseId);
                })
                ->first();

            if ($existingAttempt) {
                $grade = $existingAttempt->grade;

                if (in_array($grade, ['A', 'B', 'C'])) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Student previously passed this subject and cannot re-enroll.'
                    ], 409);
                }

                if (in_array($grade, ['D', 'F'])) {
                    $existingAttempt->offered_course_id = $offeredCourseId;
                    $existingAttempt->section_id = $sectionId;
                    $existingAttempt->grade = null;
                    $existingAttempt->attempt_no = $existingAttempt->attempt_no + 1;
                    $existingAttempt->save();
                    subjectresult::where('student_offered_course_id', $existingAttempt->id)->delete();
                    return response()->json([
                        'status' => 'success',
                        'message' => 'Student has been re-enrolled. Previous attempt was updated.'
                    ]);
                }
            }
            $newEnrollment = new student_offered_courses();
            $newEnrollment->student_id = $studentId;
            $newEnrollment->offered_course_id = $offeredCourseId;
            $newEnrollment->section_id = $sectionId;
            $newEnrollment->attempt_no = 1;
            $newEnrollment->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Student successfully enrolled in the subject.'
            ],200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getEnrollmentsRecord()
    {
        try {
            $offeredCourses = offered_courses::with([
                'course:id,name,code,description,credit_hours,lab',
                'session:id,name,year,start_date,end_date',
                'exams.questions',
                'studentOfferedCourses.student:id,RegNo,name',
                'studentOfferedCourses.section:id',
                'teacherOfferedCourses.section:id',
            ])->get();
           

            $groupedBySession = $offeredCourses->groupBy(function ($course) {
                return $course->session->name . '-' . $course->session->year;
            });
            $result = [];

            foreach ($groupedBySession as $sessionKey => $courses) {
                $sessionData = $courses->first()->session;
                $sessionEntry = [
                    'session_name' => $sessionKey,
                    'session_date_start' => $sessionData->start_date,
                    'courses' => []
                ];

                foreach ($courses as $course) {
                    $courseEntry = [
                        'course_id' => $course->course->id,
                        'course_name' => $course->course->name,
                        'course_code' => $course->course->code,
                        'isLab' => $course->course->lab ? 'Yes' : 'No',
                        'credit_hours' => $course->course->credit_hours,
                        'short_form' => $course->course->description,
                        'sections' => []
                    ];
                    $studentSectionIds = $course->studentOfferedCourses->pluck('section_id');
                    $teacherSectionIds = $course->teacherOfferedCourses->pluck('section_id');
                    $uniqueSections = $studentSectionIds->merge($teacherSectionIds)->unique()->values();
                    foreach ($uniqueSections as $sectionId) {
                        $sectionName = (new Section())->getNameByID($sectionId);
                        $sectionEntry = [
                            'section_id' => $sectionId,
                            'section_name' => $sectionName,
                            'total_enrollments' => 0,
                            'enrollments' => []
                        ];
                        $students = student_offered_courses::where('offered_course_id', $course->id)
                            ->where('section_id', $sectionId)
                            ->with([
                                'student:id,RegNo,name,section_id',
                                'student.section'
                            ])
                            ->get();
                        $studentResults = [];

                        foreach ($students as $studentCourse) {
                            $studentResults[] = [
                                'student_offered_course_id' => $studentCourse->id,
                                'student_id' => $studentCourse->student->id,
                                'image' => $studentCourse->student->image ? asset($studentCourse->student->image) : null,
                                'name' => $studentCourse->student->name,
                                'regNo' => $studentCourse->student->RegNo,
                                'semester' => $studentCourse->student->section->semester ?? 0,
                            ];
                        }

                        $sectionEntry['total_enrollments'] = count($students);
                        $sectionEntry['enrollments'] = $studentResults;
                        $courseEntry['sections'][] = $sectionEntry;
                    }
                    $sessionEntry['courses'][] = $courseEntry;
                }
                $result[] = $sessionEntry;
            }
            $result = collect($result)->sortByDesc('session_date_start')->values();

            return response()->json([
                'status' => 'success',
                'data' => $result
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve exam marks.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function storeOrUpdateSubjectResult(Request $request)
    {
        // Validate only student_offered_course_id is required
        $validator = Validator::make($request->all(), [
            'student_offered_course_id' => 'required|integer|exists:student_offered_courses,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = [
            'mid' => $request->input('mid', 0),
            'final' => $request->input('final', 0),
            'internal' => $request->input('internal', 0),
            'lab' => $request->input('lab', 0),
            'quality_points' => $request->input('quality_points', 0),
            'grade' => $request->input('grade', 'Z'),
        ];

        $subjectResult = subjectresult::updateOrCreate(
            ['student_offered_course_id' => $request->student_offered_course_id],
            $data
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Subject result saved successfully.',
            'data' => $subjectResult
        ], 200);
    }
    public static function populateExamResultValues($islab = false, $credit_hours = 1, $student_offered_course_id = null)
    {
        // Get the template structure with default marks
        $template = self::giveExamHeader($islab, $credit_hours);

        // If no ID is provided, set all except total_marks to null (including overriding total_marks)
        if (is_null($student_offered_course_id)) {
            foreach ($template as $key => $value) {
                $template[$key] = null;
            }
            return $template;
        }

        // Fetch subjectresult by student_offered_course_id
        $result = \App\Models\subjectresult::where('student_offered_course_id', $student_offered_course_id)->first();

        // If no result found, set all values to null
        if (!$result) {
            foreach ($template as $key => $value) {
                $template[$key] = null;
            }
            return $template;
        }

        // Populate individual fields
        $template['mid'] = $result->mid ?? null;
        $template['final'] = $result->final ?? null;
        $template['internal'] = $result->internal ?? null;
        $template['quality_points'] = $result->quality_points ?? null;
        $template['grade'] = $result->grade ?? null;

        if (array_key_exists('lab', $template)) {
            $template['lab'] = $result->lab ?? null;
        }

        // Calculate obtained marks
        $components = ['mid', 'final', 'internal'];
        if (array_key_exists('lab', $template)) {
            $components[] = 'lab';
        }

        $sum = 0;
        foreach ($components as $part) {
            if (!is_null($template[$part])) {
                $sum += $template[$part];
            } else {
                // If any part is null, mark total_marks as null
                $template['total_marks'] = null;
                return $template;
            }
        }

        // Replace total_marks with obtained marks
        $template['total_marks'] = $sum;

        return $template;
    }
    public static function giveExamHeader($islab = false, $credit_hours = 1)
    {
        $credit_hours = max(1, min(4, $credit_hours));
        $result = [
            'mid' => 0,
            'final' => 0,
            'internal' => 0,
            'total_marks' => 0,
            'quality_points' => 0,
            'grade' => 'N/A'
        ];
        if ($islab && $credit_hours >= 3) {
            $result['lab'] = 0;
        }
        switch ($credit_hours) {
            case 1:
                $result['mid'] = 20;
                $result['final'] = 20;
                $result['internal'] = 20;
                $result['total_marks'] = 20;
                $result['quality_points'] = 4;
                break;
            case 2:
                $result['mid'] = 12;
                $result['final'] = 20;
                $result['internal'] = 8;
                $result['total_marks'] = 40;
                $result['quality_points'] = 8;
                break;

            case 3:
                $result['quality_points'] = 12;
                $result['total_marks'] = 60;
                if ($islab) {
                    $result['mid'] = 12;
                    $result['final'] = 20;
                    $result['internal'] = 8;
                    $result['lab'] = 20;
                } else {
                    $result['mid'] = 18;
                    $result['final'] = 30;
                    $result['internal'] = 12;
                    // no lab key
                    unset($result['lab']);
                }
                break;

            case 4:
                $result['mid'] = 18;
                $result['final'] = 30;
                $result['internal'] = 12;
                $result['lab'] = 20;
                $result['total_marks'] = 80;
                $result['quality_points'] = 16;
                break;
        }

        return $result;
    }

    public function getSubjectResultReport()
    {
        try {
            $offeredCourses = offered_courses::with([
                'course:id,name,code,description,credit_hours,lab',
                'session:id,name,year,start_date,end_date',
                'exams.questions',
                'studentOfferedCourses.student:id,RegNo,name',
                'studentOfferedCourses.section:id',
            ])->whereHas('session', function ($query) {
                $query->where('end_date', '<', Carbon::today());
            })->get();
            $groupedBySession = $offeredCourses->groupBy(function ($course) {
                return $course->session->name . '-' . $course->session->year;
            });
            $result = [];

            foreach ($groupedBySession as $sessionKey => $courses) {
                $sessionData = $courses->first()->session;
                $sessionEntry = [
                    'session_name' => $sessionKey,
                    'session_date_start' => $sessionData->start_date,
                    'courses' => []
                ];

                foreach ($courses as $course) {
                    $courseEntry = [
                        'course_id' => $course->course->id,
                        'course_name' => $course->course->name,
                        'course_code' => $course->course->code,
                        'isLab' => $course->course->lab ? 'Yes' : 'No',
                        'credit_hours' => $course->course->credit_hours,
                        'short_form' => $course->course->description,
                        'restul_header' => self::giveExamHeader($course->course->lab, $course->course->credit_hours),
                        'sections' => []
                    ];
                    $uniqueSections = $course->studentOfferedCourses->pluck('section_id')->unique();

                    foreach ($uniqueSections as $sectionId) {
                        $sectionName = (new Section())->getNameByID($sectionId);
                        $sectionEntry = [
                            'section_id' => $sectionId,
                            'section_name' => $sectionName,
                            'result' => []
                        ];
                        $students = student_offered_courses::where('offered_course_id', $course->id)
                            ->where('section_id', $sectionId)
                            ->with('student:id,RegNo,name')
                            ->get();
                        $studentResults = [];

                        foreach ($students as $studentCourse) {

                            $student_result = self::populateExamResultValues($course->course->lab, $course->course->credit_hours, $studentCourse->id);
                            $studentResults[] = [
                                'student_offered_course_id' => $studentCourse->id,
                                'student_id' => $studentCourse->student->id,
                                'name' => $studentCourse->student->name,
                                'regNo' => $studentCourse->student->RegNo,
                                'result' => $student_result,
                            ];
                        }

                        $sectionEntry['result'] = $studentResults;


                        $courseEntry['sections'][] = $sectionEntry;
                    }

                    $sessionEntry['courses'][] = $courseEntry;
                }
                $result[] = $sessionEntry;
            }
            $result = collect($result)->sortByDesc('session_date_start')->values();

            return response()->json([
                'status' => 'success',
                'data' => $result
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve exam marks.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function updateStudentQuestionMarks(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'student_id' => 'required|integer',
                'exam_id' => 'required|integer',
                'question_id' => 'required|integer',
                'obtained_marks' => 'required|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $updated = DB::table('student_exam_result')->updateOrInsert(
                [
                    'student_id' => $request->student_id,
                    'exam_id' => $request->exam_id,
                    'question_id' => $request->question_id,
                ],
                ['obtained_marks' => $request->obtained_marks]
            );

            return response()->json([
                'status' => true,
                'message' => $updated ? 'Marks updated or inserted successfully.' : 'No changes made.',
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function getExamMarksReport()
    {
        try {
            $offeredCourses = offered_courses::with([
                'course:id,name,code,description',
                'session:id,name,year,start_date,end_date',
                'exams.questions',
                'studentOfferedCourses.student:id,RegNo,name',
                'studentOfferedCourses.section:id',
            ])->whereHas('session', function ($query) {
                $query->where('start_date', '<=', Carbon::today());
            })->get();


            $groupedBySession = $offeredCourses->groupBy(function ($course) {
                return $course->session->name . '-' . $course->session->year;
            });

            $result = [];

            foreach ($groupedBySession as $sessionKey => $courses) {
                $sessionData = $courses->first()->session;
                $sessionEntry = [
                    'session_name' => $sessionKey,
                    'session_date_start' => $sessionData->start_date,
                    'courses' => []
                ];

                foreach ($courses as $course) {
                    $courseEntry = [
                        'course_id' => $course->course->id,
                        'course_name' => $course->course->name,
                        'course_code' => $course->course->code,
                        'course_description' => $course->course->description,
                        'sections' => []
                    ];
                    $uniqueSections = $course->studentOfferedCourses->pluck('section_id')->unique();

                    foreach ($uniqueSections as $sectionId) {
                        $sectionName = (new Section())->getNameByID($sectionId);
                        $sectionEntry = [
                            'section_id' => $sectionId,
                            'section_name' => $sectionName,
                            'exams' => []
                        ];

                        foreach (['Mid', 'Final'] as $examType) {
                            $exam = $course->exams->firstWhere('type', $examType);
                            if (!$exam) {
                                $sectionEntry['exams'][$examType] = null;
                                continue;
                            }

                            $questions = $exam->questions;
                            $students = student_offered_courses::where('offered_course_id', $course->id)
                                ->where('section_id', $sectionId)
                                ->with('student:id,RegNo,name')
                                ->get();

                            $studentResults = [];

                            foreach ($students as $studentCourse) {
                                $student = $studentCourse->student;

                                $questionMarks = [];

                                $total = 0;


                                foreach ($questions as $question) {
                                    $studentId = $student->id;
                                    $obtained = null;
                                    $obtained = student_exam_result::where('question_id', $question->id)
                                        ->where('student_id', $studentId)
                                        ->where('exam_id', $exam->id)
                                        ->value('obtained_marks');
                                    if ($obtained) {
                                        $total += $obtained;
                                    }




                                    $questionMarks[] = [
                                        'student_id' => $studentId,
                                        'exam_id' => $exam->id,
                                        'question_id' => $question->id,
                                        'q_no' => $question->q_no,
                                        'marks' => $question->marks,
                                        'obtained_marks' => $obtained
                                    ];

                                }
                                $total_obtained = $total; // already calculated from question loop
                                $exam_total_marks = $exam->total_marks; // assumes total marks of the exam
                                $solid_marks = $exam->Solid_marks;

                                $evaluated_score = 0;
                                if ($exam_total_marks > 0 && $total_obtained > 0 && $solid_marks > 0) {
                                    $evaluated_score = round(($total_obtained / $exam_total_marks) * $solid_marks, 2); // round to 2 decimal places
                                }
                                $studentResults[] = [
                                    'student_id' => $student->id,
                                    'name' => $student->name,
                                    'regNo' => $student->RegNo,
                                    'total_obtained' => $total,
                                    'solid_obtained' => $evaluated_score,
                                    'question_marks' => $questionMarks
                                ];
                            }

                            $sectionEntry['exams'][$examType] = [
                                'exam_id' => $exam->id,
                                'type' => $exam->type,
                                'total_marks' => $exam->total_marks,
                                'solid_marks' => $exam->Solid_marks,
                                'question_paper' => $exam->QuestionPaper,
                                'questions' => $questions->map(function ($q) {
                                    return [
                                        'id' => $q->id,
                                        'q_no' => $q->q_no,
                                        'marks' => $q->marks
                                    ];
                                }),
                                'students' => $studentResults
                            ];
                        }

                        $courseEntry['sections'][] = $sectionEntry;
                    }

                    $sessionEntry['courses'][] = $courseEntry;
                }

                $result[] = $sessionEntry;
            }

            // Sort by session start date DESC
            $result = collect($result)->sortByDesc('session_date_start')->values();

            return response()->json([
                'status' => 'success',
                'data' => $result
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve exam marks.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getAllSectionsWithStudents()
    {
        try {
            $sections = section::all();
            $regularPrograms = ['BCS', 'BAI', 'BSE', 'BIT'];
            $structured = [];
            $overallTotalStudents = 0;

            foreach ($sections as $sec) {
                $studentCount = student::where('section_id', $sec->id)->count();
                $overallTotalStudents += $studentCount;
                $formattedName = $sec->getNameByID($sec->id);

                $programKey = in_array($sec->program, $regularPrograms) ? $sec->program : 'Extra';
                $semesterKey = in_array($sec->program, $regularPrograms) ? $sec->semester : 'Extra';

                if (!isset($structured[$programKey])) {
                    $structured[$programKey] = [
                        'total_students' => 0,
                        'data' => []
                    ];
                }

                if (!isset($structured[$programKey]['data'][$semesterKey])) {
                    $structured[$programKey]['data'][$semesterKey] = [];
                }

                $structured[$programKey]['data'][$semesterKey][] = [
                    'name' => $formattedName,
                    'student_count' => $studentCount
                ];

                $structured[$programKey]['total_students'] += $studentCount;
            }

            // Sort semester keys numerically for each program
            foreach ($structured as $program => &$programData) {
                if (!empty($programData['data'])) {
                    uksort($programData['data'], function ($a, $b) {
                        // If both are numbers, compare as integers
                        if (is_numeric($a) && is_numeric($b)) {
                            return intval($a) - intval($b);
                        }
                        // Force Extra or non-numeric semesters to come last
                        if (is_numeric($a))
                            return -1;
                        if (is_numeric($b))
                            return 1;
                        return strcmp($a, $b);
                    });
                }
            }

            return response()->json([
                'status' => 'success',
                'total_students' => $overallTotalStudents,
                'sections' => $structured
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while fetching sections.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function insertSection(Request $request)
    {
        $name = $request->input('name');

        if (!$name) {
            return response()->json([
                'status' => 'error',
                'message' => 'Section name is required.'
            ], 400);
        }

        $sectionId = section::addNewSection($name);

        if ($sectionId) {
            return response()->json([
                'status' => 'success',
                'message' => 'Section inserted or already exists.',
                'section_id' => $sectionId
            ], 200);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid section name format.'
            ], 422);
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

            $existingUser = User::where('username', $username)->first();
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
    public function AddSingleJuniorLecturer(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'date_of_birth' => 'required|date',
                'gender' => 'required|string',
                'cnic' => 'required',
                'email' => 'nullable', // Email is optional
                'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048' // Image is optional
            ]);

            $name = trim($request->input('name'));
            $dateOfBirth = $request->input('date_of_birth');
            $gender = $request->input('gender');
            $cnic = $request->input('cnic');
            $email = $request->input('email') ?? null; // Default to null if not provided
            $username = strtolower(str_replace(' ', '', $name)) . '@biit.edu';
            if (juniorlecturer::where('cnic', $cnic)->exists()) {
                return response()->json([
                    'status' => 'failed',
                    'message' => "The JuniorLecturer with cnic: {$cnic} already exists."
                ], 409);
            }
            // Check if the user already exists
            $existingUser = User::where('username', $username)->first();
            if ($existingUser) {
                return response()->json([
                    'status' => 'failed',
                    'message' => "The Junior Lecturer with username: {$username} already exists."
                ], 409);
            }

            $formattedDOB = (new DateTime($dateOfBirth))->format('Y-m-d');
            $password = Action::generateUniquePassword($name);
            $userId = Action::addOrUpdateUser($username, $password, $email, 'JuniorLecturer');

            if (!$userId) {
                return response()->json([
                    'status' => 'failed',
                    'message' => "Failed to create or update user for {$name}."
                ], 500);
            }
            $juniorLecturer = juniorlecturer::create([
                'user_id' => $userId,
                'name' => $name,
                'date_of_birth' => $formattedDOB,
                'gender' => $gender,
                'email' => $email,
                'cnic' => $cnic
            ]);
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $directory = 'Images/JuniorLecturer';
                $storedFilePath = FileHandler::storeFile($juniorLecturer->user_id, $directory, $image);
                $juniorLecturer->update(['image' => $storedFilePath]);
            }
            return response()->json([
                'status' => 'success',
                'message' => "The Junior Lecturer with Name: {$name} was added.",
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
    public function AddSingleUser(Request $request, $role)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'phone_number' => 'required|string',
                'Designation' => 'required|string',
                'email' => 'nullable|email',
                'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048'
            ]);

            $name = trim($request->input('name'));
            $phone = $request->input('phone_number');
            $designation = $request->input('Designation');
            $email = $request->input('email') ?? null;
            if ($role == 'Admin') {
                $postfix = '.admin@biit.edu';
            } else {
                $postfix = '.datacell@biit.edu';
            }
            $username = strtolower(str_replace(' ', '', $name)) . $postfix;

            // Determine model and image path based on role
            $model = $role === 'Admin' ? admin::class : datacell::class;
            $directory = $role === 'Admin' ? 'Images/Admin' : 'Images/DataCell';

            // Check if user already exists
            $existingUser = User::where('username', $username)->first();
            if ($existingUser) {
                return response()->json([
                    'status' => 'failed',
                    'message' => "The user with username: {$username} already exists."
                ], 409);
            }

            $password = Action::generateUniquePassword($name);
            $userId = Action::addOrUpdateUser($username, $password, $email, $role);

            if (!$userId) {
                return response()->json([
                    'status' => 'failed',
                    'message' => "Failed to create or update user for {$name}."
                ], 500);
            }

            $user = $model::create([
                'user_id' => $userId,
                'name' => $name,
                'phone_number' => $phone,
                'Designation' => $designation,
                'email' => $email
            ]);
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $storedFilePath = FileHandler::storeFile($user->user_id, $directory, $image);
                $user->update(['image' => $storedFilePath]);
            }

            return response()->json([
                'status' => 'success',
                'message' => "The {$role} with Name: {$name} was added.",
                'username' => $username,
                'password' => $password
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => implode(', ', collect($e->errors())->flatten()->toArray())
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
                'errors' => $e->getMessage()
            ], 500);
        }
    }
    public function AddDirector(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'Designation' => 'required|string',
                'email' => 'nullable|email',
                'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048'
            ]);

            $name = trim($request->input('name'));
            $designation = $request->input('Designation');
            $email = $request->input('email') ?? null;
            $postfix = '.director@biit.edu';
            $username = strtolower(str_replace(' ', '', $name)) . $postfix;
            $model = Director::class;
            $directory = 'Images/Director';
            $existingUser = User::where('username', $username)->first();
            if ($existingUser) {
                return response()->json([
                    'status' => 'failed',
                    'message' => "The user with username: {$username} already exists."
                ], 409);
            }

            $password = Action::generateUniquePassword($name);
            $userId = Action::addOrUpdateUser($username, $password, $email, 'Director');
            if (!$userId) {
                return response()->json([
                    'status' => 'failed',
                    'message' => "Failed to create or update user for {$name}."
                ], 500);
            }

            $user = $model::create([
                'user_id' => $userId,
                'name' => $name,
                'designation' => $designation,
            ]);
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $storedFilePath = FileHandler::storeFile($user->user_id, $directory, $image);
                $user->update(['image' => $storedFilePath]);
            }

            return response()->json([
                'status' => 'success',
                'message' => "The Director with Name: {$name} was added.",
                'username' => $username,
                'password' => $password
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => implode(', ', collect($e->errors())->flatten()->toArray())
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
                'errors' => $e->getMessage()
            ], 500);
        }
    }
    public function AddHod(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'department' => 'required|string',
                'Designation' => 'required|string',
                'email' => 'nullable|email',
                'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048'
            ]);

            $name = trim($request->input('name'));
            $department = $request->input('department');
            $designation = $request->input('Designation');
            $email = $request->input('email') ?? null;
            $postfix = '.hod@biit.edu';
            $username = strtolower(str_replace(' ', '', $name)) . $postfix;
            $model = Hod::class;
            $directory = 'Images/HOD';
            $existingUser = User::where('username', $username)->first();
            if ($existingUser) {
                return response()->json([
                    'status' => 'failed',
                    'message' => "The user with username: {$username} already exists."
                ], 409);
            }

            $password = Action::generateUniquePassword($name);
            $userId = Action::addOrUpdateUser($username, $password, $email, 'HOD');
            if (!$userId) {
                return response()->json([
                    'status' => 'failed',
                    'message' => "Failed to create or update user for {$name}."
                ], 500);
            }

            $user = $model::create([
                'user_id' => $userId,
                'name' => $name,
                'designation' => $designation,
                'department' => $department
            ]);
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $storedFilePath = FileHandler::storeFile($user->user_id, $directory, $image);
                $user->update(['image' => $storedFilePath]);
            }

            return response()->json([
                'status' => 'success',
                'message' => "The Director with Name: {$name} was added.",
                'username' => $username,
                'password' => $password
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => implode(', ', collect($e->errors())->flatten()->toArray())
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
                'errors' => $e->getMessage()
            ], 500);
        }
    }
    public function AddSingleAdmin(Request $request)
    {
        return $this->AddSingleUser($request, 'Admin');
    }
    public function AddSingleDatacell(Request $request)
    {
        return $this->AddSingleUser($request, 'Datacell');
    }
    public function InsertStudent(Request $request)
    {
        try {
            $request->validate([
                'RegNo' => 'required|string|unique:students,RegNo',
                'Name' => 'required|string',
                'gender' => 'required|in:Male,Female',
                'dob' => 'required|date',
                'guardian' => 'required|string',
                'cgpa' => 'nullable|numeric|min:0|max:4.00',
                'email' => 'nullable|email',
                'currentSection' => 'required|string',
                'status' => 'required|string',
                'InTake' => 'required|string',
                'Discipline' => 'required|string',
                'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048'
            ]);

            $regNo = $request->RegNo;

            // Check if student already exists
            if (student::where('RegNo', $regNo)->exists()) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Student with RegNo {$regNo} already exists!"
                ], 409);
            }

            $name = $request->Name;
            $gender = $request->gender;
            $dob = (new DateTime($request->dob))->format('Y-m-d');
            $guardian = $request->guardian;
            $cgpa = $request->cgpa;
            $email = $request->email;
            $currentSection = $request->currentSection;
            $status = $request->status;
            $inTake = $request->InTake;
            $discipline = $request->Discipline;

            // Generate a password for the student
            $password = Action::generateUniquePassword($name);

            // Create a user account for the student
            $user_id = Action::addOrUpdateUser($regNo, $password, $email, 'Student');

            // Get section ID
            $section_id = section::addNewSection($currentSection);
            if (!$section_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid format for Current Section: {$currentSection}"
                ], 400);
            }

            // Get session ID
            $session_id = (new session())->getSessionIdByName($inTake);
            if (!$session_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid format for Session: {$inTake}"
                ], 400);
            }

            // Get program ID
            $program_id = program::where('name', $discipline)->value('id');
            if (!$program_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid format for Discipline: {$discipline}"
                ], 400);
            }

            // Handle image upload
            $imagePath = null;
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $imageName = time() . '_' . $image->getClientOriginalName();
                $imagePath = $image->storeAs('student_images', $imageName, 'public');
            }

            // Insert the student record
            $student = StudentManagement::addOrUpdateStudent($regNo, $name, $cgpa, $gender, $dob, $guardian, $imagePath, $user_id, $section_id, $program_id, $session_id, $status);

            if ($student) {
                return response()->json([
                    'status' => 'success',
                    'message' => "Student with RegNo: {$regNo} added successfully!",
                    'Username' => $regNo,
                    'Password' => $password
                ], 201);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Unknown error occurred while inserting student'
            ], 500);

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
    public function pushNotification(Request $request)
    {
        try {
            // Validate request fields
            $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'sender' => 'required|string|max:50',
                'sender_id' => 'required|integer|exists:user,id', // Sender ID is required
                'reciever' => 'nullable|string|max:50',
                'Brodcast' => 'nullable|boolean',
                'url' => 'nullable|string|max:255',
                'image' => 'nullable|file',
                'Student_Section_Name' => 'nullable|string', // Section name to find Section ID
                'TL_receiver_name' => 'nullable|string', // Receiver name for ID lookup
            ]);
            $imageUrl = null;
            if ($request->hasFile('image')) {
                // If a file was uploaded, store it and use its path
                $imagePath = FileHandler::storeFile(now()->timestamp, 'Notification', $request->file('image'));
                $imageUrl = $imagePath;
            } elseif (!is_null($request->url)) {
                // If no image file but URL is provided, use the URL
                $imageUrl = $request->url;
            } elseif ($request->has('image') && is_string($request->image)) {
                // If image is passed as a string path instead of uploaded file (fallback)
                $imageUrl = $request->image;
            }

            $TL_receiver_id = null;
            $Student_Section = null;
            if ($request->has('Brodcast') && $request->Brodcast == true) {
                if (!$request->has('reciever')) {
                    // Insert only broadcast data without receiver
                    $notification = notification::create([
                        'title' => $request->title,
                        'description' => $request->description,
                        'url' => $imageUrl ?? null,
                        'notification_date' => now(),
                        'sender' => $request->sender,
                        'reciever' => null,
                        'Brodcast' => true,
                        'TL_sender_id' => $request->sender_id,
                        'Student_Section' => null,
                        'TL_receiver_id' => null,
                    ]);
                } else {
                    // Insert broadcast with receiver
                    if ($request->reciever == 'student') {
                        $reciver = 'Student';
                    } else if ($request->reciever == 'teacher') {
                        $reciver = 'Teacher';

                    } else if ($request->reciever == 'lecturer') {
                        $reciver = 'JuniorLecturer';

                    } else {
                        $reciver = $request->reciever;
                    }
                    $notification = Notification::create([
                        'title' => $request->title,
                        'description' => $request->description,
                        'url' => $imageUrl ?? null,
                        'notification_date' => now(),
                        'sender' => $request->sender,
                        'reciever' => $reciver,
                        'Brodcast' => true,
                        'TL_sender_id' => $request->sender_id,
                        'Student_Section' => null,
                        'TL_receiver_id' => null,
                    ]);
                }

                return response()->json([
                    'message' => 'Notification pushed successfully!',
                    'notification' => $notification
                ], 201);
            }

            // If sender is Student and Section Name is provided, find section ID
            if ($request->reciever == 'student') {
                $reciver = 'Student';
            } else if ($request->reciever == 'teacher') {
                $reciver = 'Teacher';

            } else if ($request->reciever == 'lecturer') {
                $reciver = 'JuniorLecturer';

            } else {
                $reciver = $request->reciever;
            }
            if (
                $request->reciever === 'student' &&
                $request->has('Student_Section_Name') &&
                !is_null($request->Student_Section_Name)
            ) {
                $section_id = (new section())->getIDByName($request->Student_Section_Name);
                if (!$section_id) {
                    return response()->json(['message' => 'Invalid Section Name'], 400);
                }
                $Student_Section = $section_id;
            } else if ($request->reciever === 'student' && !$Student_Section && $request->has('TL_receiver_name')) {
                $student = Student::where('name', $request->TL_receiver_name)->first();
                if (!$student) {
                    return response()->json(['message' => 'Student not found ' . $request->TL_receiver_name], 400);
                }
                $TL_receiver_id = $student->user_id; // Use student's linked user_id
            } else
                // If receiver is Teacher, find user_id from Teacher model
                if ($request->reciever === 'teacher' && $request->has('TL_receiver_name')) {
                    $teacher = Teacher::where('name', $request->TL_receiver_name)->first();
                    if (!$teacher) {
                        return response()->json(['message' => 'Teacher not found'], 400);
                    }
                    $TL_receiver_id = $teacher->user_id;
                } else

                    // If receiver is JuniorLecturer, find user_id from JuniorLecturer model
                    if ($request->reciever === 'lecturer' && $request->has('TL_receiver_name')) {
                        $junior = JuniorLecturer::where('name', $request->TL_receiver_name)->first();
                        if (!$junior) {
                            return response()->json(['message' => 'Junior Lecturer not found'], 400);
                        }
                        $TL_receiver_id = $junior->user_id;
                    }

            // Create and save the notification
            $notification = Notification::create([
                'title' => $request->title,
                'description' => $request->description,
                'url' => $imageUrl ?? null,
                'notification_date' => now(),
                'sender' => $request->sender,
                'reciever' => $reciver,
                'Brodcast' => false,
                'TL_sender_id' => $request->sender_id,
                'Student_Section' => $Student_Section,
                'TL_receiver_id' => $TL_receiver_id,
            ]);

            return response()->json([
                'message' => 'Notification pushed successfully!',
                'notification' => $notification
            ], 201);

        } catch (Exception $e) {
            return response()->json([
                'messages' => 'Failed to push notification',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function UpdateSingleUser(Request $request)
    {
        try {
            $request->validate([
                'role' => 'required|string|in:Admin,Datacell',
                'name' => 'required|string',
                'phone_number' => 'required|string',
                'Designation' => 'required|string',
                'email' => 'nullable|email',
                'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
                'password' => 'nullable|string|min:6',
                'id' => 'required|integer'
            ]);
            $id = $request->input('id');
            $role = ucfirst(strtolower($request->input('role')));
            $name = trim($request->input('name'));
            $phone = $request->input('phone_number');
            $designation = $request->input('Designation');
            $email = $request->input('email') ?? null;
            $password = $request->input('password');
            $model = $role === 'Admin' ? Admin::class : Datacell::class;
            $directory = $role === 'Admin' ? 'Images/Admin' : 'Images/DataCell';
            $user = $model::find($id);
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => "{$role} user with ID: {$id} was not found."
                ], 404);
            }
            $user->update([
                'name' => $name,
                'phone_number' => $phone,
                'Designation' => $designation
            ]);
            if ($role == 'Admin') {
                $postfix = '.admin@biit.edu';
            } else {
                $postfix = '.datacell@biit.edu';
            }
            $username = strtolower(str_replace(' ', '', $name)) . $postfix;
            $userAccount = User::find($user->user_id);
            if ($userAccount) {
                $userAccount->update([
                    'username' => $username // Hash password before saving
                ]);
            }
            if (!empty($password)) {
                $userAccount = User::find($user->user_id);
                if ($userAccount) {
                    $userAccount->update([
                        'password' => $password // Hash password before saving
                    ]);
                }
            }
            if (!empty($email)) {
                $userAccount = User::find($user->user_id);
                if ($userAccount) {
                    $userAccount->update([
                        'email' => $email // Hash password before saving
                    ]);
                }
            }
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $storedFilePath = FileHandler::storeFile($user->user_id, $directory, $image);
                $user->update(['image' => $storedFilePath]);
            }
            return response()->json([
                'status' => 'success',
                'message' => "{$role} user with ID: {$id} was updated successfully.",
                'name' => $name,
                'designation' => $designation,
                'username' => $username,
                'email' => $email,
                'phone' => $phone,
                'password' => $password,
                'image' => $user->image ? asset($user->image) : null,
            ], 200);

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
    public function addStudent(Request $request)
    {
        try {
            $request->validate([
                'RegNo' => 'required|unique:student,RegNo',
                'name' => 'required',
                'email' => 'required|email|unique:user,email',
                'program_id' => 'required|exists:program,id',
                'section_id' => 'nullable|exists:section,id',
                'session_id' => 'nullable|exists:session,id',
                'cgpa' => 'nullable|numeric|min:0|max:4',
                'gender' => 'nullable|in:Male,Female',
                'date_of_birth' => 'nullable|date',
                'guardian' => 'nullable|string',
                'image' => 'nullable|image|mimes:jpeg,png,jpg',
                'status' => 'nullable|in:Graduate,UnderGraduate,Freeze'
            ]);

            $regNo = $request->RegNo;
            $name = $request->name;
            $email = $request->email;
            $program_id = $request->program_id;
            $section_id = $request->section_id;
            $session_id = $request->session_id;
            $cgpa = $request->cgpa;
            $gender = $request->gender;
            $date_of_birth = $request->date_of_birth;
            $guardian = $request->guardian;
            $status = $request->status;
            $password = Action::generateUniquePassword($name);
            $imagePath = null;
            if ($request->hasFile('image')) {
                $imagePath = Action::storeFile($request->file('image'), 'Images/Student', $regNo);
            }
            $user = User::create([
                'username' => $regNo,
                'password' => $password,
                'email' => $email,
                'role_id' => role::where('type', 'Student')->value('id')
            ]);

            $student = Student::create([
                'RegNo' => $regNo,
                'name' => $name,
                'cgpa' => $cgpa,
                'gender' => $gender,
                'date_of_birth' => $date_of_birth,
                'guardian' => $guardian,
                'image' => $imagePath,
                'user_id' => $user->id,
                'section_id' => $section_id,
                'program_id' => $program_id,
                'session_id' => $session_id,
                'status' => $status
            ]);

            return response()->json([
                'message' => 'Student added successfully!',
                'username' => $regNo,
                'password' => $password
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => implode(', ', collect($e->errors())->flatten()->toArray())
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'errors' => $e->getMessage()
            ], 500);
        }
    }
    public function addSession(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'year' => 'required',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        // // Split "name-year" (e.g., "Spring-2025" → name="Spring", year="2025")
        // $nameParts = explode('-', $request->name);
        // if (count($nameParts) != 2 || !is_numeric($nameParts[1])) {
        //     return response()->json(['message' => 'Invalid session name format. Use "name-year" (e.g., Spring-2025).'], 400);
        // }
        $name = $request->name;
        $year = $request->year;
        $existingSession = Session::where('name', $name)
            ->where('year', $year)
            ->exists();

        if ($existingSession) {
            return response()->json(['message' => 'A session with the same name and year already exists.'], 400);
        }
        // Check for date range overlap
        $overlap = Session::where(function ($query) use ($request) {
            $query->whereBetween('start_date', [$request->start_date, $request->end_date])
                ->orWhereBetween('end_date', [$request->start_date, $request->end_date])
                ->orWhere(function ($query) use ($request) {
                    $query->where('start_date', '<=', $request->start_date)
                        ->where('end_date', '>=', $request->end_date);
                });
        })->exists();

        if ($overlap) {
            return response()->json(['message' => 'Session date range overlaps with an existing session.'], 400);
        }

        // Create session
        $session = Session::create([
            'name' => $name,
            'year' => $year,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
        ]);

        return response()->json(['message' => 'Session added successfully.', 'session' => $session], 201);
    }
    // Update an existing session
    public function updateSession(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $session = Session::find($id);
        if (!$session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        // Split "name-year" format
        $nameParts = explode('-', $request->name);
        if (count($nameParts) != 2 || !is_numeric($nameParts[1])) {
            return response()->json(['message' => 'Invalid session name format. Use "name-year" (e.g., Spring-2025).'], 400);
        }
        [$name, $year] = $nameParts;

        // Check for date range overlap (excluding current session)
        $overlap = Session::where('id', '!=', $id)
            ->where(function ($query) use ($request) {
                $query->whereBetween('start_date', [$request->start_date, $request->end_date])
                    ->orWhereBetween('end_date', [$request->start_date, $request->end_date])
                    ->orWhere(function ($query) use ($request) {
                        $query->where('start_date', '<=', $request->start_date)
                            ->where('end_date', '>=', $request->end_date);
                    });
            })->exists();

        if ($overlap) {
            return response()->json(['message' => 'Session date range overlaps with an existing session.'], 400);
        }

        // Update session
        $session->update([
            'name' => $name,
            'year' => $year,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
        ]);

        return response()->json(['message' => 'Session updated successfully.', 'session' => $session], 200);
    }

    public function StoreGraderRequest(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'grader_id' => 'required|integer|exists:grader,id',
                'teacher_id' => 'required|integer|exists:teacher,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Get current session
            $sessionId = (new session())->getCurrentSessionId();
            if ($sessionId == 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'No active session. Cannot add request.'
                ], 403);
            }

            // Check if grader already assigned in current session
            $alreadyAssigned = teacher_grader::where('grader_id', $request->grader_id)
                ->where('session_id', $sessionId)
                ->exists();

            if ($alreadyAssigned) {
                return response()->json([
                    'status' => false,
                    'message' => 'Grader is already assigned to a teacher in the current session. Cannot add request.'
                ], 409);
            }

            // Check for existing pending request
            $exists = grader_requests::where('grader_id', $request->grader_id)
                ->where('teacher_id', $request->teacher_id)
                ->where('status', 'pending')
                ->first();

            if ($exists) {
                return response()->json([
                    'status' => false,
                    'message' => 'A pending request already exists for this grader and teacher.'
                ], 409);
            }

            // Store the new request
            $requestData = grader_requests::create([
                'grader_id' => $request->grader_id,
                'teacher_id' => $request->teacher_id,
                'status' => 'pending',
                'requested_at' => now(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Grader request submitted successfully.',
                'data' => $requestData
            ], 201);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while processing the request.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function pendingRequests()
    {
        try {
            $requests = grader_requests::where('status', 'pending')
                ->orderByDesc('requested_at')
                ->with([
                    'grader.student:id,RegNo,name',
                    'teacher:id,name'
                ])
                ->get()
                ->map(function ($req) {
                    return [
                        'id' => $req->id,
                        'grader' => [
                            'RegNo' => $req->grader->student->RegNo ?? null,
                            'name' => $req->grader->student->name ?? null,
                        ],
                        'teacher' => $req->teacher->name ?? null,
                        'requested_at' => $req->requested_at,
                    ];
                });

            return response()->json([
                'status' => true,
                'data' => $requests
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch pending requests.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function processRequest(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required|exists:grader_requests,id',
                'action' => 'required|in:accept,reject',
            ]);

            $requestRecord = grader_requests::with(['grader.student', 'teacher'])->find($request->id);

            if (!$requestRecord) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Grader request not found.'
                ], 404);
            }

            // Get current session ID
            $sessionId = (new session())->getCurrentSessionId();

            if ($sessionId == 0) {
                // No current session, delete the request
                $requestRecord->delete();
                return response()->json([
                    'status' => 'error',
                    'message' => "No Current Session Found! Can't assign new grader. Request has been removed."
                ], 404);
            }

            $teacherName = $requestRecord->teacher->name ?? 'Unknown Teacher';
            $graderName = $requestRecord->grader->student->name ?? 'Unknown Student';

            if ($request->action === 'reject') {
                $requestRecord->delete();
                return response()->json([
                    'status' => 'success',
                    'message' => "The Request by {$teacherName} for grader {$graderName} is Rejected by Administration."
                ]);
            }

            // Check if grader already assigned to a teacher in this session
            $alreadyAssigned = teacher_grader::where('grader_id', $requestRecord->grader_id)
                ->where('session_id', $sessionId)
                ->exists();

            if ($alreadyAssigned) {
                $requestRecord->delete();
                return response()->json([
                    'status' => 'info',
                    'message' => "Grader {$graderName} is already assigned to a teacher in this session. Request removed."
                ]);
            }

            // Assign the grader
            teacher_grader::create([
                'grader_id' => $requestRecord->grader_id,
                'teacher_id' => $requestRecord->teacher_id,
                'session_id' => $sessionId,
                'feedback' => null
            ]);

            // Update the request status
            $requestRecord->update([
                'status' => 'accepted',
                'responded_at' => now()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "The Request by {$teacherName} for grader {$graderName} is Accepted by Administration."
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while processing the request.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function viewExcludedDays()
    {
        try {
            $records = excluded_days::orderBy('date', 'desc')->get();
            return response()->json(['status' => true, 'data' => $records]);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to fetch records.', 'error' => $e->getMessage()], 500);
        }
    }
    public function storeExcludedDay(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'date' => 'required|date|unique:excluded_days,date',
                'type' => 'required|in:Holiday,Exam,Reschedule',
                'reason' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
            }

            $record = excluded_days::create($request->only('date', 'type', 'reason'));
            return response()->json(['status' => true, 'message' => 'Excluded day added.', 'data' => $record], 201);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => 'Insertion failed.', 'error' => $e->getMessage()], 500);
        }
    }
    public function updateExcludedDay(Request $request, $id)
    {
        try {
            $record = excluded_days::find($id);

            if (!$record) {
                return response()->json(['status' => false, 'message' => 'Record not found.'], 404);
            }

            $validator = Validator::make($request->all(), [
                'type' => 'required|in:Holiday,Exam,Reschedule',
                'reason' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
            }

            $record->update($request->only('type', 'reason'));
            return response()->json(['status' => true, 'message' => 'Record updated.', 'data' => $record]);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => 'Update failed.', 'error' => $e->getMessage()], 500);
        }
    }
    public function deleteExcludedDay($id)
    {
        try {
            $record = excluded_days::find($id);

            if (!$record) {
                return response()->json(['status' => false, 'message' => 'Record not found.'], 404);
            }

            $record->delete();
            return response()->json(['status' => true, 'message' => 'Record deleted.']);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => 'Deletion failed.', 'error' => $e->getMessage()], 500);
        }
    }


}
