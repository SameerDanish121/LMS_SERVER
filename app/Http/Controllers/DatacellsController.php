<?php

namespace App\Http\Controllers;
use App\Models\datacell;
use App\Models\Hod;
use Illuminate\Http\Request;
use App\Models\Action;
use App\Models\coursecontent;
use App\Models\coursecontent_topic;
use Illuminate\Support\Facades\Validator;
use App\Models\dayslot;
use App\Models\exam;
use App\Models\excluded_days;
use App\Models\FileHandler;
use App\Models\grader;
use App\Models\juniorlecturer;
use App\Models\program;
use App\Models\question;
use App\Models\quiz_questions;
use App\Models\role;
use App\Models\student_exam_result;
use App\Models\StudentManagement;
use App\Models\subjectresult;
use App\Models\teacher;
use App\Models\teacher_grader;
use App\Models\teacher_juniorlecturer;
use App\Models\topic;
use App\Models\venue;
use Exception;
use GrahamCampbell\ResultType\Success;
use Laravel\Pail\Options;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Models\attendance;
use App\Models\course;
use App\Models\notification;
use App\Models\offered_courses;
use App\Models\section;
use App\Models\sessionresult;
use App\Models\student;
use App\Models\student_offered_courses;
use App\Models\student_task_result;
use App\Models\student_task_submission;
use App\Models\task;
use App\Models\teacher_offered_courses;
use App\Models\timetable;
use App\Models\User;
use DateTime;
use App\Models;
use App\Models\admin;
use App\Models\Director;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use App\Models\session;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use function PHPUnit\Framework\isEmpty;
use Illuminate\Support\Facades\Storage;
class DatacellsController extends Controller
{
    public function deleteUserRole(Request $request)
    {
        try {
            $request->validate([
                'type' => 'required|string|in:Admin,Datacell,HOD,Director',
                'id' => 'required|integer',
            ]);

            $type = $request->input('type');
            $id = $request->input('id');
            $modelMap = [
                'Admin' => Admin::class,
                'Datacell' => Datacell::class,
                'HOD' => Hod::class,
                'Director' => Director::class,
            ];

            if (!isset($modelMap[$type])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid user role type.'
                ], 422);
            }

            $model = $modelMap[$type];
            $record = $model::findOrFail($id);

            // Wrap in transaction for safe delete
            DB::beginTransaction();

            $userId = $record->user_id;

            // Delete notifications sent by this user
            Notification::where('TL_sender_id', $userId)->delete();

            // Delete role-specific record
            $record->delete();

            // Delete the user record
            User::where('id', $userId)->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "{$type} deleted successfully with user and related notifications."
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => "{$type} with given ID not found."
            ], 404);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred during deletion.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateUserRole(Request $request)
    {
        try {
            $request->validate([
                'type' => 'required|string|in:Admin,Datacell,HOD,Director',
                'id' => 'required|integer',
                'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            ]);

            $type = $request->input('type');
            $id = $request->input('id');

            // Resolve model class
            $modelMap = [
                'Admin' => Admin::class,
                'Datacell' => Datacell::class,
                'HOD' => Hod::class,
                'Director' => Director::class,
            ];

            if (!isset($modelMap[$type])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid type provided.'
                ], 422);
            }

            $modelClass = $modelMap[$type];
            $record = $modelClass::findOrFail($id);

            $updatable = [];

            foreach ($request->except(['type', 'id', 'program_name', 'image']) as $key => $value) {
                $updatable[$key] = $value;
            }

            // Special case: HOD with program_name
            if ($type === 'HOD' && $request->has('program_name')) {
                $programName = trim($request->input('program_name'));
                $program = Program::where('name', $programName)->first();

                if (!$program) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Program with short form '{$programName}' not found."
                    ], 404);
                }

                $updatable['program_id'] = $program->id;
                $updatable['department'] = $programName;
            }

            // Handle image upload if present
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $directory = 'Images/' . $type;
                $storedPath = FileHandler::storeFile($record->user_id, $directory, $image);
                $updatable['image'] = $storedPath;
            }

            // Update only provided fields
            $record->update($updatable);

            return response()->json([
                'status' => 'success',
                'message' => "{$type} updated successfully.",
                'updated_fields' => array_keys($updatable)
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => "{$type} with given ID not found."
            ], 404);

        } catch (Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred.',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null, // Shows stack trace only in debug
            ], 500);
        }
    }

    public function getCoreUsers()
    {
        try {
            $transformUsers = function ($collection, $role) {
                $result = [];

                foreach ($collection as $user) {
                    $result[] = [
                        'id' => $user->id,
                        'name' => $user->name ?? null,
                        'phone_number' => $user->phone_number ?? null,
                        'Designation' => $user->designation ?? $user->Designation ?? null,
                        'department' => $user->department ?? null, // only for HOD
                        'email' => optional($user->user)->email,
                        'image' => $user->image ? asset($user->image) : null,
                    ];
                }
                return $result;
            };

            $data = [
                'Datacell' => $transformUsers(Datacell::with('user')->get(), 'Datacell'),
                'Admin' => $transformUsers(admin::with('user')->get(), 'Admin'),
                'HOD' => $transformUsers(Hod::with('user')->get(), 'HOD'),
                'Director' => $transformUsers(Director::with('user')->get(), 'Director'),
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Core users fetched successfully.',
                'data' => $data
            ], 200);

        } catch (\Exception $e) {


            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred while fetching core users.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function UpdateJuniorLecturer(Request $request)
    {
        try {
            $request->validate([
                'junior_lecturer_id' => 'required|integer|exists:juniorlecturer,id',
                'name' => 'nullable|string',
                'date_of_birth' => 'nullable|date',
                'gender' => 'nullable|string',
                'cnic' => 'nullable|string',
                'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048'
            ]);

            $jl = JuniorLecturer::findOrFail($request->input('junior_lecturer_id'));

            $updatedData = [];

            if ($request->has('name')) {
                $updatedData['name'] = trim($request->input('name'));
            }

            if ($request->has('date_of_birth')) {
                $formattedDOB = (new DateTime($request->input('date_of_birth')))->format('Y-m-d');
                $updatedData['date_of_birth'] = $formattedDOB;
            }

            if ($request->has('gender')) {
                $updatedData['gender'] = $request->input('gender');
            }

            if ($request->has('cnic')) {
                $cnic = $request->input('cnic');
                $existing = JuniorLecturer::where('cnic', $cnic)
                    ->where('id', '!=', $jl->id)
                    ->exists();

                if ($existing) {
                    return response()->json([
                        'status' => 'failed',
                        'message' => "Another junior lecturer with CNIC: {$cnic} already exists."
                    ], 409);
                }

                $updatedData['cnic'] = $cnic;
            }
            // Handle image upload
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $directory = 'Images/JuniorLecturer';
                $storedFilePath = FileHandler::storeFile($jl->user_id, $directory, $image);
                $updatedData['image'] = $storedFilePath;
            }

            $jl->update($updatedData);

            return response()->json([
                'status' => 'success',
                'message' => 'Junior Lecturer information updated successfully.',
                'updated_fields' => array_keys($updatedData)
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
                'message' => 'Junior Lecturer not found'
            ], 404);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function UpdateTeacher(Request $request)
    {
        try {
            $request->validate([
                'teacher_id' => 'required|integer|exists:teacher,id',
                'name' => 'nullable|string',
                'date_of_birth' => 'nullable|date',
                'gender' => 'nullable|string',
                'cnic' => 'nullable',
                'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            ]);

            $teacher = Teacher::findOrFail($request->input('teacher_id'));

            $updatedData = [];

            if ($request->has('name')) {
                $updatedData['name'] = trim($request->input('name'));
            }

            if ($request->has('date_of_birth')) {
                $formattedDOB = (new DateTime($request->input('date_of_birth')))->format('Y-m-d');
                $updatedData['date_of_birth'] = $formattedDOB;
            }

            if ($request->has('gender')) {
                $updatedData['gender'] = $request->input('gender');
            }

            if ($request->has('cnic')) {
                // Check for duplicate CNIC
                $cnic = $request->input('cnic');
                $existing = Teacher::where('cnic', $cnic)
                    ->where('id', '!=', $teacher->id)
                    ->exists();
                if ($existing) {
                    return response()->json([
                        'status' => 'failed',
                        'message' => "Another teacher with CNIC: {$cnic} already exists."
                    ], 409);
                }

                $updatedData['cnic'] = $cnic;
            }
            // Handle image upload
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $directory = 'Images/Teacher';
                $storedFilePath = FileHandler::storeFile($teacher->user_id, $directory, $image);
                $updatedData['image'] = $storedFilePath;
            }

            // Apply updates
            $teacher->update($updatedData);

            return response()->json([
                'status' => 'success',
                'message' => "Teacher information updated successfully.",
                'updated_fields' => array_keys($updatedData),
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
                'message' => 'Teacher not found'
            ], 404);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage(), // Show message
                'line' => $e->getLine(),     // Optional: Line number
                'file' => $e->getFile(),     // Optional: File location
            ], 500);
        }
    }

    public function promoteStudents(Request $request)
    {
        // ✅ Step 1: Validate input
        $validator = Validator::make($request->all(), [
            'semester' => 'required|integer|min:1|max:11',
            'cgpa_criteria' => 'required|numeric|min:0|max:4.0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        $semester = $request->semester;
        $cgpaCriteria = $request->cgpa_criteria;

        try {
            // ✅ Step 2: Find section IDs in the given semester
            $sections = Section::where('semester', $semester)->pluck('id');

            if ($sections->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No sections found for the specified semester.',
                ], 404);
            }

            // ✅ Step 3: Fetch students in those sections
            $students = student::whereIn('section_id', $sections)->with('section')->get();

            if ($students->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No students found in the specified semester.',
                ], 404);
            }

            // ✅ Step 4: Promotion logic
            $promoted = 0;
            $dropped = 0;
            $responseList = [];

            foreach ($students as $stu) {
                $currentSection = $stu->section;

                // Check if section relation is loaded and valid
                if (!$currentSection) {
                    $dropped++;
                    $responseList[] = [
                        'RegNo' => $stu->RegNo,
                        'Name' => $stu->name,
                        'Previous Semester' => 'Unknown',
                        'New Semester' => 'Unknown',
                        'Promotion Status' => 'Dropped (Missing Section)',
                        'CGPA' => $stu->cgpa
                    ];
                    continue;
                }

                $currentSemester = $currentSection->semester;
                $program = program::where('name', $currentSection->program)->first();
                $programId = $program->id;
                $nextSemester = $currentSemester + 1;
                $newSemester = $currentSemester;
                $status = 'Dropped';

                if ($stu->cgpa >= $cgpaCriteria) {
                    $nextSection = Section::where('program', $currentSection->program)
                        ->where('semester', $nextSemester)
                        ->first();

                    if (!$nextSection) {
                        $nextSection = Section::create([
                            'program' => $currentSection->program,
                            'semester' => $nextSemester,
                            'group' => $currentSection->group ?? 'A',
                            'status' => 'active'
                        ]);
                    }

                    // ✅ Update student
                    $stu->section_id = $nextSection->id;
                    $stu->save();

                    $status = 'Promoted';
                    $newSemester = $nextSemester;
                    $promoted++;
                } else {
                    $dropped++;
                }

                $responseList[] = [
                    'RegNo' => $stu->RegNo,
                    'Name' => $stu->name,
                    'Previous Semester' => $currentSemester,
                    'New Semester' => $newSemester,
                    'Promotion Status' => $status,
                    'CGPA' => $stu->cgpa
                ];
            }

            return response()->json([
                'status' => true,
                'message' => "Out of {$students->count()} students, {$promoted} were promoted and {$dropped} dropped.",
                'data' => $responseList
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while processing promotions.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function updateStudent(Request $request, $id)
    {
        $student = student::find($id);

        if (!$student) {
            return response()->json([
                'status' => false,
                'message' => 'Student not found.'
            ], 404);
        }

        // Validate inputs
        $validator = Validator::make($request->all(), [
            'RegNo' => 'nullable|string|max:20|unique:student,RegNo,' . $id,
            'name' => 'nullable|string|max:100',
            'cgpa' => 'nullable|numeric|between:0,4.00',
            'gender' => 'nullable|in:Male,Female,Other',
            'date_of_birth' => 'nullable|date',
            'guardian' => 'nullable|string|max:100',
            'section_id' => 'nullable|integer|exists:section,id',
            'session_id' => 'nullable|integer|exists:session,id',
            'status' => 'nullable|in:Graduate,UnderGraduate,Freeze',
            'program_name' => 'nullable|string|exists:program,name',
            'image' => 'nullable|file|image|mimes:jpg,jpeg,png|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }
        $student->fill($request->only([
            'RegNo',
            'name',
            'cgpa',
            'gender',
            'date_of_birth',
            'guardian',
            'section_id',
            'session_id',
            'status'
        ]));
        if ($request->filled('program_name')) {
            $program = Program::where('name', $request->program_name)->first();
            if (!$program) {
                return response()->json([
                    'status' => false,
                    'message' => 'Program not found.'
                ], 404);
            }
            $student->program_id = $program->id;
        }
        if ($request->hasFile('image')) {
            $regNo = $request->input('RegNo', $student->RegNo);
            $imagePath = Action::storeFile($request->file('image'), 'Images/Student', $regNo);
            $student->image = $imagePath;
        }
        $student->save();
        return response()->json([
            'status' => true,
            'message' => 'Student updated successfully.',
            'data' => $student
        ], 200);
    }


    public function updateDatacellInfo(Request $request, $hod_id)
    {
        try {
            $hod = datacell::with('user')->find($hod_id);

            if (!$hod || !$hod->user) {
                return response()->json(['error' => 'DATACELL or associated user not found'], 404);
            }

            // Validate input
            $validated = $request->validate([
                'email' => 'nullable|email|unique:user,email,',
                'password' => 'nullable|min:3',
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

                $directory = 'Images/DataCell';
                $storedFilePath = FileHandler::storeFile($hod->user_id, $directory, $request->file('image'));
                $hod->image = $storedFilePath;
            }
            $hod->user->save();
            $hod->save();
            return response()->json(['message' => 'DATCELL info updated successfully'], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => 'Validation error', 'messages' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json(['error' => 'An error occurred', 'details' => $e->getMessage()], 500);
        }
    }
    public function AddNewOfferedCourse(Request $request)
    {
        try {
            $course_name = $request->course_name;
            if ($course_name) {
                $course_id = Course::where('name', $course_name)->pluck('id')->first();
                if ($course_id) {
                    $offered_course = offered_courses::where('course_id', $course_id)->where('session_id', (new session())->getCurrentSessionId())->first();
                    if ($offered_course) {
                        throw new Exception('Course is Already is Offerd in this session');
                    } else {
                        offered_courses::create(
                            [
                                'course_id' => $course_id,
                                'session_id' => (new session())->getCurrentSessionId()
                            ]
                        );
                        return response()->json(
                            [
                                'message' => 'Offerd Course Successfully Added'
                            ],
                            200
                        );

                    }

                } else {
                    throw new Exception('Course Name is Not in the Course List');
                }
            } else {
                throw new Exception('Course Name Required');
            }
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function AllStudent(Request $request)
    {
        try {
            $id = $request->id;
            $students = [];
            if ($request->student_RegNo) {
                $students = student::where('RegNo', $request->student_RegNo)->first();
            } else if ($request->student_name) {
                $students = student::where('name', $request->student_name)->get();
            } else if ($request->student_cgpa) {
                $students = student::where('cgpa', '>=', $request->student_cgpa)->get();
            } else if ($request->student_section) {
                $students = student::where('section_id', $request->student_section)->get();
            } else if ($request->student_program) {
                $students = student::where('program_id', $request->student_program)->get();
            } else if ($request->student_session) {
                $students = student::where('session_id', $request->student_session)->get();
            } else if ($request->student_status) {
                $students = student::where('status', $request->student_status)->get();
            } else {
                $students = student::with(['user', 'section', 'session', 'program'])->get();
            }
            foreach ($students as $student) {
                $originalPath = $student->image;
                if (!$originalPath) {
                    $student->image = null;
                } else {
                    $imageContent = file_get_contents(public_path($originalPath));
                    $student->image = base64_encode($imageContent);
                }
            }
            return response()->json(
                [
                    'message' => 'Student Fetched Successfully',
                    'Student' => $students,
                ],
                200
            );
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function NewEnrollment(Request $request)
    {
        try {
            $validated = $request->validate([
                'student_RegNo' => 'required|string',
                'course_name' => 'required|string',
                'section' => 'required'
            ]);
            $student_RegNo = $request->student;
            $student = student::where('RegNo', $student_RegNo)->pluck('id')->first();
            $course_name = $request->course_name;
            $course = course::where('name', $course_name)->pluck('id')->first();
            $session = $request->session;
            $section = $request->section;
            if (!$session) {
                $session = (new session())->getCurrentSessionId();
            } else {
                $session_info = explode('-', $session);
                $session = session::where('year', $session_info[1])->where('name', $session_info[0])->pluck('id')->first();
            }
            $offered_course = offered_courses::where('session_id', $session)->where('course_id', $course)->pluck('id')->first();
            if (!$offered_course) {
                throw new Exception('Course is Not Offered in Provided Session ');
            }
            $section_info = $splitString = explode('-', $section);
            $program = $section_info[0];
            $semester = $section_info[1][0];
            $group = $section_info[1][1];
            $section = section::where('program', $program)->where('group', $group)->where('semester', $semester)->pluck('id')->first();
            $enrollment = student_offered_courses::where('student_id', $student)->where('section_id', $section)->where('offered_course_id', $offered_course);
            if ($enrollment) {
                throw new Exception('Enrollment Already Exsists');
            } else {
                $enrollment = student_offered_courses::where('student_id', $student)
                    ->whereHas('offeredCourse', function ($query) use ($course) {
                        $query->where('course_id', $course);
                    })
                    ->where('grade', 'F')
                    ->first();
                if ($enrollment) {
                    student_offered_courses::where('id', $enrollment->id)->update(
                        [
                            'grade' => '',
                            'offered_course_id' => $offered_course,
                            'section_id' => $section,
                            'attempt_no' => DB::raw('attempt_no + 1')
                        ]
                    );
                    return response()->json(
                        [
                            'message' => 'The Re-Enrollment For Failed Course is Added'
                        ],
                        200
                    );
                } else {
                    student_offered_courses::create(
                        [
                            'grade' => null,
                            'attempt_no' => 0,
                            'student_id' => $student,
                            'section_id' => $section,
                            'offered_course_id' => $offered_course
                        ]
                    );

                    return response()->json(
                        [
                            'message' => 'The Enrollment is Added'
                        ],
                        200
                    );
                }

            }
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

    public function Archives(Request $request)
    {
        try {
            if ($request->directory) {
                $directory_details = FileHandler::getFolderInfo($request->directory);
                if (!$directory_details) {
                    throw new Exception('No Directory Exsist with the Given Name');
                }
            } else {
                $directory_details = FileHandler::getFolderInfo();
            }
            return response()->json(
                [
                    'message' => 'Directory Details Fetched !',
                    'Details' => $directory_details,
                ],
                200
            );
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',

                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function DeleteFolderByPath(Request $request)
    {
        try {
            if ($request->path) {
                $isFolderDeleted = FileHandler::deleteFolder($request->path);
                if (!$isFolderDeleted) {
                    throw new Exception('No Directory Exsist with the Given Path');
                }
            } else {
                throw new Exception('Folder PATH OR Name is Required , Please Select Valid Folder !');
            }
            return response()->json(
                [
                    'message' => 'Folder Deleted Successfully !',
                    'logs' => " Deleted {$isFolderDeleted} Of Data  "
                ],
                200
            );
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function Sample(Request $request)
    {
        try {
            $id = $request->id;
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid username or password'
            ], 404);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function OfferedCourseTeacheruploadExcel(Request $request)
    {
        try {
            $request->validate([
                'excel_file' => 'required|mimes:xlsx,xls',
                'session' => 'required'
            ]);
            $session_name = $request->session;
            $session_id = (new session())->getSessionIdByName($session_name);
            if (!$session_id) {
                throw new Exception('NO SESSION EXISIT FOR THE PROVIDED NAME ');
            }
            $file = $request->file('excel_file');
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            $filteredData = [];
            foreach ($sheetData as $row) {
                $filteredData[] = array_slice($row, 0, 4);
            }
            $nonEmpty = [];
            foreach ($filteredData as $row) {
                if (
                    array_filter($row, function ($value) {
                        return !is_null($value);
                    })
                ) {
                    if ($row['A'] != 'Section') {
                        $row = array_map('trim', $row);
                        $nonEmpty[] = $row;
                    }

                }
            }
            $FaultyData = [];
            $Succesfull = [];
            $CountRow = 2;
            foreach ($nonEmpty as $index => $row) {
                $section_id = (new section())->getIDByName($row['A']);
                if (!$section_id) {
                    $section_id = section::addNewSection($row['A']);
                }
                $RawData = " Section = " . $row['A'] . " , Course = " . $row['B'] . " , Teacher = " . $row['D'];
                if (!$section_id) {
                    $FaultyData[] = ["status" => "error", "Issue" => "Row No {$CountRow} : Section Format is Not Supported {$RawData}"];
                    $CountRow++;
                    continue;
                }
                $course_id = (new course())->getIDByName($row['B']);
                if (!$course_id) {
                    $FaultyData[] = ["status" => "error", "Issue" => "Row No {$CountRow} : Course Not Found {$RawData}"];
                    $CountRow++;
                    continue;
                }
                $teacher_id = (new teacher())->getIDByName($row['D']);
                if (!$teacher_id) {
                    $FaultyData[] = ["status" => "error", "Issue" => "Row No {$CountRow} : Teacher Not Found {$RawData}"];
                    $CountRow++;
                    continue;
                }
                $offered_course = offered_courses::firstOrCreate(
                    [
                        "course_id" => $course_id,
                        "session_id" => $session_id
                    ]
                );
                $offered_course_id = $offered_course->id;
                $teacherOfferedCourse = teacher_offered_courses::updateOrCreate(
                    [
                        'offered_course_id' => $offered_course_id,
                        'section_id' => $section_id,
                    ],
                    [
                        'teacher_id' => $teacher_id,
                    ]
                );
                $Succesfull[] = ["status" => "success", "data" => "Row No {$CountRow}" . $RawData];
                $CountRow++;
            }
            return response()->json(
                [
                    'message' => 'The Teacher Enrollments Are Added',
                    'data' => [
                        "Total Data " => count($nonEmpty),
                        "Total Inserted " => count($Succesfull),
                        "Total Failure " => count($FaultyData),
                        "FaultyData" => $FaultyData,
                        "Sucessfull" => $Succesfull
                    ]
                ],
                200
            );
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid username or password'
            ], 404);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function ExcludedDays(Request $request)
    {
        try {
            $request->validate([
                'excel_file' => 'required|mimes:xlsx,xls',
            ]);
            $file = $request->file('excel_file');
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            $expectedFormat = ['A' => 'Reason', 'B' => 'Type', 'C' => 'Date'];
            $headerRow = $sheetData[1] ?? [];
            $headerRow['A'] = trim($headerRow['A']);
            $headerRow['B'] = trim($headerRow['B']);
            $headerRow['C'] = trim($headerRow['C']);
            if (array_diff_assoc($expectedFormat, $headerRow)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid file format. Expected header: ' . implode(', ', $expectedFormat),
                ], 422);
            }
            $filteredData = array_slice($sheetData, 1);
            $nonEmpty = [];
            foreach ($filteredData as $row) {
                if (
                    array_filter($row, function ($value) {
                        return !is_null($value);
                    })
                ) {
                    $row = array_map('trim', $row);
                    $nonEmpty[] = $row;
                }
            }
            $FaultyData = [];
            $Succesfull = [];
            $CountRow = 2;
            foreach ($nonEmpty as $row) {
                $RawData = "Reason = " . $row['A'] . ", Type = " . $row['B'] . ", Date = " . $row['C'];
                $reason = trim($row['A']);
                $type = trim($row['B']);
                $date = trim($row['C']);

                if (empty($reason) || empty($type) || empty($date)) {
                    $FaultyData[] = [
                        "status" => "error",
                        "Issue" => "Row No {$CountRow}: Missing required fields. {$RawData}"
                    ];
                    $CountRow++;
                    continue;
                }
                $validTypes = ['Holiday', 'Reschedule', 'Exam'];
                if (!in_array($type, $validTypes)) {
                    $FaultyData[] = [
                        "status" => "error",
                        "Issue" => "Row No {$CountRow}: Invalid type '{$type}'. Valid types are: " . implode(', ', $validTypes) . ". {$RawData}"
                    ];
                    $CountRow++;
                    continue;
                }
                if (!Carbon::hasFormat($date, 'Y-m-d')) {
                    $FaultyData[] = [
                        "status" => "error",
                        "Issue" => "Row No {$CountRow}: Invalid date format '{$date}'. Expected format: YYYY-MM-DD. {$RawData}"
                    ];
                    $CountRow++;
                    continue;
                }
                $excludedDay = excluded_days::where('date', $date)->first();
                if ($excludedDay) {
                    $excludedDay->update([
                        'type' => $type,
                        'reason' => $reason,
                    ]);
                    $Succesfull[] = [
                        "status" => "success",
                        "data" => "Row No {$CountRow}: Updated existing record. {$RawData}"
                    ];
                } else {
                    excluded_days::create([
                        'date' => $date,
                        'type' => $type,
                        'reason' => $reason,
                    ]);
                    $Succesfull[] = [
                        "status" => "success",
                        "data" => "Row No {$CountRow}: Inserted new record. {$RawData}"
                    ];
                }
                $CountRow++;
            }
            return response()->json([
                'message' => 'The excluded days have been processed successfully.',
                'data' => [
                    "Total Data" => count($nonEmpty),
                    "Total Inserted/Updated" => count($Succesfull),
                    "Total Errors" => count($FaultyData),
                    "FaultyData" => $FaultyData,
                    "Succesfull" => $Succesfull
                ]
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
    public function processSessionRecords(Request $request)
    {
        try {
            $request->validate([
                'excel_file' => 'required|mimes:xlsx,xls',
            ]);
            $file = $request->file('excel_file');
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            $expectedFormat = ['A' => 'name', 'B' => 'year', 'C' => 'start_date', 'D' => 'end_date'];
            $headerRow = $sheetData[1] ?? [];
            $headerRow = array_map('trim', $headerRow);
            if (array_diff_assoc($expectedFormat, $headerRow)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid file format. Expected header: ' . implode(', ', $expectedFormat),
                ], 422);
            }
            $filteredData = array_slice($sheetData, 1);
            $nonEmpty = array_filter($filteredData, function ($row) {
                return !empty(array_filter($row, function ($value) {
                    return trim($value) !== '';
                }));
            });

            $FaultyData = [];
            $Succesfull = [];
            $CountRow = 2;

            foreach ($nonEmpty as $row) {
                $name = trim($row['A']);
                $year = trim($row['B']);
                $startDate = trim($row['C']);
                $endDate = trim($row['D']);
                $RawData = "Name = {$name}, Year = {$year}, Start Date = {$startDate}, End Date = {$endDate}";
                if (empty($name) || empty($year) || empty($startDate) || empty($endDate)) {
                    $FaultyData[] = [
                        "status" => "error",
                        "Issue" => "Row No {$CountRow}: Missing required fields. {$RawData}"
                    ];
                    $CountRow++;
                    continue;
                }
                try {
                    $startDateObj = Carbon::createFromFormat('Y-m-d', $startDate);
                    $endDateObj = Carbon::createFromFormat('Y-m-d', $endDate);
                } catch (Exception $e) {
                    $FaultyData[] = [
                        "status" => "error",
                        "Issue" => "Row No {$CountRow}: Invalid date format. Expected format: YYYY-MM-DD. {$RawData}"
                    ];
                    $CountRow++;
                    continue;
                }
                if ($startDateObj->greaterThanOrEqualTo($endDateObj)) {
                    $FaultyData[] = [
                        "status" => "error",
                        "Issue" => "Row No {$CountRow}: End date must be greater than start date. {$RawData}"
                    ];
                    $CountRow++;
                    continue;
                }
                $overlap = session::where(function ($query) use ($startDate, $endDate) {
                    $query->whereBetween('start_date', [$startDate, $endDate])
                        ->orWhereBetween('end_date', [$startDate, $endDate])
                        ->orWhere(function ($query) use ($startDate, $endDate) {
                            $query->where('start_date', '<=', $startDate)
                                ->where('end_date', '>=', $endDate);
                        });
                })->exists();

                if ($overlap) {
                    $FaultyData[] = [
                        "status" => "error",
                        "Issue" => "Row No {$CountRow}: Date range overlaps with an existing session. {$RawData}"
                    ];
                    $CountRow++;
                    continue;
                }
                $existingSession = session::where('name', $name)
                    ->where('year', $year)
                    ->first();

                if ($existingSession) {
                    $existingSession->update([
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ]);
                    $Succesfull[] = [
                        "status" => "success",
                        "data" => "Row No {$CountRow}: Updated existing session. {$RawData}"
                    ];
                } else {
                    session::create([
                        'name' => $name,
                        'year' => $year,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ]);
                    $Succesfull[] = [
                        "status" => "success",
                        "data" => "Row No {$CountRow}: Inserted new session. {$RawData}"
                    ];
                }

                $CountRow++;
            }

            return response()->json([
                'message' => 'The sessions have been processed successfully.',
                'data' => [
                    "Total Data" => count($nonEmpty),
                    "Total Inserted/Updated" => count($Succesfull),
                    "Total Errors" => count($FaultyData),
                    "FaultyData" => $FaultyData,
                    "Succesfull" => $Succesfull
                ]
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
    public function importVenues(Request $request)
    {
        try {
            $request->validate([
                'excel_file' => 'required|mimes:xlsx,xls',
            ]);
            $file = $request->file('excel_file');
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            $expectedHeader = 'venue';
            $headerRow = $sheetData[1]['A'] ?? null;
            if (trim(strtolower($headerRow)) !== $expectedHeader) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid file format. Expected header: '{$expectedHeader}' in column A.",
                ], 422);
            }
            $filteredData = array_slice($sheetData, 1);
            $nonEmptyRows = array_filter($filteredData, function ($row) {
                return !empty($row['A']);
            });

            $faultyData = [];
            $successful = [];
            $rowCount = 2; // Start from the second row since the first row is the header

            foreach ($nonEmptyRows as $row) {
                $rawVenue = trim($row['A']);

                if (empty($rawVenue)) {
                    $faultyData[] = [
                        'status' => 'error',
                        'Issue' => "Row No {$rowCount}: Venue name is empty."
                    ];
                    $rowCount++;
                    continue;
                }

                try {
                    $venue = venue::firstOrCreate(['venue' => $rawVenue]);

                    $successful[] = [
                        'status' => 'success',
                        'data' => "Row No {$rowCount}: Venue '{$venue->venue}' was added or already exists."
                    ];
                } catch (Exception $e) {
                    $faultyData[] = [
                        'status' => 'error',
                        'Issue' => "Row No {$rowCount}: Unable to process venue '{$rawVenue}'. Error: " . $e->getMessage()
                    ];
                }

                $rowCount++;
            }

            return response()->json([
                'message' => 'The venues have been processed successfully.',
                'data' => [
                    "Total Data" => count($nonEmptyRows),
                    "Total Processed" => count($successful),
                    "Total Errors" => count($faultyData),
                    "FaultyData" => $faultyData,
                    "Successful" => $successful
                ]
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
    public function importSections(Request $request)
    {
        try {
            $request->validate([
                'excel_file' => 'required|mimes:xlsx,xls',
            ]);
            $file = $request->file('excel_file');
            $spreadsheet = IOFactory::load(filename: $file->getPathname());
            $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            $expectedHeader = ['A' => 'Section'];
            $headerRow = $sheetData[1] ?? [];
            $headerRow = array_map('trim', $headerRow); // Trim header values
            if (array_diff_assoc($expectedHeader, $headerRow)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid file format. Expected header: ' . implode(', ', $expectedHeader),
                ], 422);
            }
            $dataRows = array_slice($sheetData, 1);
            $nonEmptyRows = array_filter($dataRows, function ($row) {
                return !empty(trim($row['A'] ?? ''));
            });

            $successful = [];
            $faultyData = [];
            $rowNumber = 2;

            foreach ($nonEmptyRows as $row) {
                $sectionName = trim($row['A'] ?? '');
                if (empty($sectionName)) {
                    $faultyData[] = [
                        'status' => 'error',
                        'Issue' => "Row No {$rowNumber}: Missing section name.",
                    ];
                    $rowNumber++;
                    continue;
                }
                try {
                    $sectionId = section::addNewSection($sectionName);
                    if ($sectionId) {
                        $successful[] = [
                            'status' => 'success',
                            'data' => "Row No {$rowNumber}: Section '{$sectionName}' added or found with ID {$sectionId}.",
                        ];
                    } else {
                        $faultyData[] = [
                            'status' => 'error',
                            'Issue' => "Row No {$rowNumber}: Invalid section format '{$sectionName}'.",
                        ];
                    }
                } catch (Exception $e) {
                    $faultyData[] = [
                        'status' => 'error',
                        'Issue' => "Row No {$rowNumber}: Failed to process section '{$sectionName}'. Error: " . $e->getMessage(),
                    ];
                }
                $rowNumber++;
            }
            return response()->json([
                'message' => 'Sections processed successfully.',
                'data' => [
                    'Total Rows' => count($nonEmptyRows),
                    'Total Successful' => count($successful),
                    'Total Errors' => count($faultyData),
                    'Successful' => $successful,
                    'Faulty Data' => $faultyData,
                ],
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function UploadStudentEnrollments(Request $request)
    {
        try {
            $request->validate([
                'excel_file' => 'required|mimes:xlsx,xls',
                'session' => 'required'
            ]);
            $session = (new session())->getSessionIdByName($request->session);
            if (!$session) {
                throw new Exception('No Session Exsist with given name');
            }
            $file = $request->file('excel_file');
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            $NonEmptyRow = [];
            foreach ($sheetData as $row) {
                if (
                    array_filter($row, function ($value) {
                        return !is_null($value);
                    })
                ) {
                    $row = array_map('trim', $row);
                    $NonEmptyRow[] = $row;
                }
            }
            $response = [

            ];

            array_shift($NonEmptyRow);
            $RowNo = 1;
            foreach ($NonEmptyRow as $singleRow) {
                $studentRegNo = $singleRow['A'];
                $studentName = $singleRow['B'];
                $enrollmentExsist = null;
                $sectionExsist = null;
                $student_id = student::where('RegNo', $studentRegNo)->value('id');
                if (!$student_id) {
                    $response[] = ['status' => "error on Row No {$RowNo}", 'message' => "No Student with this RegNo ={$studentRegNo} , Name = {$studentName} Found in Student Record"];
                    continue;
                }
                $iteration = 0;
                $CellNo = 0;
                foreach ($singleRow as $cell) {
                    $CellNo++;
                    $iteration++;
                    if ($iteration <= 2) {
                        continue;
                    }
                    if ($cell != null || $cell != '') {
                        if ($enrollmentExsist) {
                            $sectionExsist = $cell;
                            $section_id = section::addNewSection($sectionExsist);
                            if (!$section_id) {
                                $response[] = ['status' => "error on Row No " . ($RowNo - 1) . " , Cell NO {$CellNo}", 'message' => "The Data {$sectionExsist} is in Incorrect Format "];
                                $enrollmentExsist = null;
                                $sectionExsist = null;
                                continue;
                            }
                            $courseInfo = explode('_', $enrollmentExsist);
                            if (count($courseInfo) == 2) {
                                $course_name = $courseInfo[0];
                                $course_code = $courseInfo[1];
                            } else {
                                $response[] = ['status' => "error on Row No {$RowNo} , Cell NO {$CellNo}", 'message' => "The Data is in Incorrect Format"];
                                $enrollmentExsist = null;
                                $sectionExsist = null;
                                continue;
                            }
                            $course_id = (new course())->getIDByName($course_name);
                            if (!$course_id) {
                                $response[] = ['status' => "error on Row No {$RowNo} , Cell NO {$CellNo}", 'message' => "The Course {$course_name} is Not in Course Record"];
                                $enrollmentExsist = null;
                                $sectionExsist = null;
                                continue;
                            }
                            $offered_course_id = offered_courses::where('session_id', $session)
                                ->where('course_id', $course_id)->value('id');
                            if (!$offered_course_id) {
                                $response[] = ['status' => "error on Row No {$RowNo} , Cell NO {$CellNo}", 'message' => "The Course {$course_name} is Not Yet offered in Session {$request->session}"];
                                $enrollmentExsist = null;
                                $sectionExsist = null;
                                continue;
                            }
                            $check = self::EnrollorReEnroll($student_id, $course_id, $offered_course_id, $section_id);
                            if ($check['message'] == 'success') {
                                $response[] = ['status' => $check['status'], 'message' => " The Enrollment for {$studentRegNo}/{$studentName} in {$course_name}: Cell No {$CellNo}, Row NO {$RowNo} " . $check['message']];
                            } else {
                                $response[] = ['status' => $check['status'], 'message' => " The Enrollment for {$studentRegNo}/{$studentName} in {$course_name}: Cell No {$CellNo}, Row NO {$RowNo} " . $check['message']];
                            }
                            $enrollmentExsist = null;
                            $sectionExsist = null;
                        } else {
                            $enrollmentExsist = $cell;

                        }
                    } else {
                        continue;
                    }
                }

            }
            $successMessages = [];
            $errorMessages = [];
            foreach ($response as $stat) {
                if ($stat['status'] === 'success') {
                    $successMessages[] = $stat;
                } else {
                    $errorMessages[] = $stat;
                }
            }
            return response()->json(
                [
                    'Message' => 'Data Inserted Successfully !',
                    'data' => [
                        "Total Student Rows" => count($NonEmptyRow),
                        "Added Enrollments" => count($successMessages),
                        "Failed Enrollments" => count($errorMessages),
                        "Sucess" => $successMessages,
                        "Error" => $errorMessages
                    ]
                ],
                200
            );

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid username or password'
            ], 404);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public static function EnrollorReEnroll($student_id, $course_id, $offered_course_id, $section_id)
    {
        try {
            if (!$student_id || !$course_id || !$offered_course_id || !$section_id) {
                return ['status' => 'failure', 'message' => 'Invalid parameters'];
            }
            $existingRecord = student_offered_courses::where('student_id', $student_id)
                ->where('offered_course_id', $offered_course_id)
                ->first();
            if ($existingRecord) {
                return ['status' => 'success', 'message' => 'Exist'];
            }
            $offeredCoursesList = offered_courses::where('course_id', $course_id)->pluck('id')->toArray();
            $studentCourse = student_offered_courses::where('student_id', $student_id)
                ->whereIn('offered_course_id', $offeredCoursesList)
                ->first();
            if ($studentCourse) {
                if (in_array($studentCourse->grade, ['F', 'D'])) {
                    $studentCourse->offered_course_id = $offered_course_id;
                    $studentCourse->attempt_no += 1;
                    $studentCourse->grade = null;
                    $studentCourse->section_id = $section_id;
                    $studentCourse->save();
                    return ['status' => 'Re-Enrolled', 'message' => "Re-Enrolled the the Course with {$studentCourse->grade}"];
                }
            }
            student_offered_courses::create([
                'student_id' => $student_id,
                'offered_course_id' => $offered_course_id,
                'section_id' => $section_id,
                'attempt_no' => 0,
                'grade' => null,
            ]);
            return ['status' => 'success', 'message' => 'Record inserted successfully'];
        } catch (Exception $ex) {
            return ['status' => 'error', 'message' => "{$ex->getMessage()}"];
        }
    }
    public function AddOrUpdateTeacher(Request $request)
    {
        try {
            $request->validate([
                'excel_file' => 'required|mimes:xlsx,xls'
            ]);
            $file = $request->file('excel_file');
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            $filteredData = [];
            foreach ($sheetData as $row) {
                $filteredData[] = array_slice($row, 0, 4);
            }
            $nonEmpty = [];
            foreach ($filteredData as $row) {
                if (
                    array_filter($row, function ($value) {
                        return !is_null($value);
                    })
                ) {
                    if ($row['A'] != 'Name') {
                        $row = array_map('trim', $row);
                        $nonEmpty[] = $row;
                    }
                }
            }
            $RowNo = 1;
            $status = [];

            $successMessages = [];
            $errorMessages = [];
            foreach ($nonEmpty as $singleRow) {
                $RowNo++;
                $name = $singleRow['A'];
                $dateOfBirth = $singleRow['B'];
                $gender = $singleRow['C'];
                $email = $singleRow['D'];
                $username = strtolower(str_replace(' ', '', $name)) . '@biit.edu';
                $formattedDOB = (new DateTime($dateOfBirth))->format('Y-m-d');
                $password = Action::generateUniquePassword($name);
                $userId = Action::addOrUpdateUser($username, $password, $email, 'Teacher');
                if (!$userId) {
                    $errorMessages[] = ["status" => 'failed', "reason" => "Failed to create or update user for {$name}."];
                    continue;
                }
                $teacher = Teacher::where('name', $name)->first();
                if ($teacher) {
                    $teacher->update([
                        'user_id' => $userId,
                        'date_of_birth' => $formattedDOB,
                        'gender' => $gender
                    ]);
                    $successMessages[] = ["status" => 'success', "Logs" => "The teacher with Name: {$name} was updated."];
                } else {
                    Teacher::create([
                        'user_id' => $userId,
                        'name' => $name,
                        'date_of_birth' => $formattedDOB,
                        'gender' => $gender
                    ]);
                    $successMessages[] = ["status" => 'success', "Logs" => "The teacher with Name: {$name} was added."];
                }
            }

            return response()->json(
                [
                    'Message' => 'Teachers Processed Successfully!',
                    'success' => $successMessages,
                    'failed' => $errorMessages
                ],
                200
            );

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
    public function AddOrUpdateStudent(Request $request)
    {
        try {
            $request->validate([
                'excel_file' => 'required|mimes:xlsx,xls'
            ]);
            $file = $request->file('excel_file');
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            $filteredData = [];
            foreach ($sheetData as $row) {
                $filteredData[] = array_slice($row, 0, 11);
            }
            $nonEmpty = [];
            foreach ($filteredData as $row) {
                if (
                    array_filter($row, function ($value) {
                        return !is_null($value);
                    })
                ) {
                    if ($row['A'] != 'RegNo') {
                        $row = array_map('trim', $row);
                        $nonEmpty[] = $row;
                    }
                }
            }
            $RowNo = 1;

            $status = [];
            $successMessages = [];
            $errorMessages = [];
            foreach ($nonEmpty as $singleRow) {
                $RowNo++;
                $regNo = $singleRow['A'];
                $Name = $singleRow['B'];
                $gender = $singleRow['C'];
                $dob = $singleRow['D'];
                $guardian = $singleRow['E'];
                $cgpa = $singleRow['F'];
                $email = $singleRow['G'];
                $currentSection = $singleRow['H'];
                $status = $singleRow['I'];
                $InTake = $singleRow['J'];
                $Discipline = $singleRow['K'];
                $dob = (new DateTime($dob))->format('Y-m-d');
                $password = Action::generateUniquePassword($Name);

                $user_id = Action::addOrUpdateUser($regNo, $password, $email, 'Student');
                $section_id = section::addNewSection($currentSection);
                if (!$section_id) {
                    $errorMessages[] = ["status" => 'failed', "reason" => "The Field Current Section {$currentSection} Format is Not Correct !"];
                }

                $session_id = (new session())->getSessionIdByName($InTake);

                if ($session_id == 0) {
                    $errorMessages[] = ["status" => 'failed', "reason" => "The Field Current Session  {$InTake} Format is Not Correct !"];
                }
                $program_id = program::where('name', $Discipline)->value('id');
                if (!$program_id) {
                    $errorMessages[] = ["status" => 'failed', "reason" => "The Field Disciplein  {$Discipline} Format is Not Correct !"];
                }

                $student = StudentManagement::addOrUpdateStudent($regNo, $Name, $cgpa, $gender, $dob, $guardian, null, $user_id, $section_id, $program_id, $session_id, $status);

                if ($student) {
                    $successMessages[] = ["status" => 'success', "Logs" => "The Student with RegNo : {$regNo} ,Name : {$Name}  is added to Record !", "Username" => $regNo, "Password" => $password];

                } else {
                    $errorMessages[] = ["status" => 'failed', 'Reason' => 'Unknown'];
                }

            }
            return response()->json(
                [
                    'Message' => 'Data Inserted Successfully !',
                    'success' => $successMessages,
                    'failed' => $errorMessages
                ],
                200
            );
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid username or password'
            ], 404);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage()
            ], 500);
        }


    }
    public function AddOrUpdateCourses(Request $request)
    {
        try {
            $request->validate([
                'excel_file' => 'required|mimes:xlsx,xls'
            ]);
            $file = $request->file('excel_file');
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            $filteredData = [];
            foreach ($sheetData as $row) {
                $filteredData[] = array_slice($row, 0, 8);
            }
            $nonEmpty = [];
            foreach ($filteredData as $row) {
                if (
                    !empty(array_filter($row, function ($value) {
                        return !is_null($value) && trim($value) !== '';
                    }))
                ) {
                    if ($row['A'] != 'code') {
                        $row = array_map('trim', $row);
                        $nonEmpty[] = $row;
                    }
                }
            }
            $RowNo = 1;
            $status = [];
            foreach ($nonEmpty as $singleRow) {
                $RowNo++;
                $code = $singleRow['A'];
                $name = $singleRow['B'];
                $creditHours = $singleRow['C'];
                $preReqMain = $singleRow['D'] === 'Null' ? null : $singleRow['D'];
                $program = $singleRow['E'];
                $type = $singleRow['F'];
                $shortform = $singleRow['G'];
                $lab = $singleRow['H'];
                if (!isEmpty($program)) {
                    $programId = Program::where('name', $program)->value('id');
                    if (!$programId) {
                        $status[] = ["status" => 'failed', "reason" => "The Field Program {$program} does not exist!"];
                        continue;
                    }
                } else {
                    $programId = null;
                }
                $preReqId = null;
                if ($preReqMain !== null && !isEmpty($preReqMain) && $preReqId != '') {
                    $preReqId = Course::where('name', $preReqMain)->value('id');
                    if (!$preReqId) {
                        $status[] = ["status" => 'failed', "reason" => "The prerequisite course {$preReqMain} does not exist!"];
                        continue;
                    }
                }
                $course = Course::where('name', $name)->where('code', $code)->first();
                $dataToUpdate = [
                    'code' => $code,
                    'credit_hours' => $creditHours,
                    'type' => $type,
                    'description' => $shortform,
                    'lab' => $lab,
                ];
                if (!is_null($preReqId)) {
                    $dataToUpdate['pre_req_main'] = $preReqId;
                }
                if (!is_null($programId)) {
                    $dataToUpdate['program_id'] = $programId;
                }

                if ($course) {
                    $course->update($dataToUpdate);
                    $status[] = ["status" => 'success', "Logs" => "The course with Name: {$name} was updated."];
                } else {
                    $dataToCreate = $dataToUpdate;
                    $dataToCreate['name'] = $name;
                    Course::create($dataToCreate);
                    $status[] = ["status" => 'success', "Logs" => "The course with Name: {$name} was added."];
                }
            }
            $successMessages = [];
            $errorMessages = [];
            foreach ($status as $stat) {
                if ($stat['status'] === 'success') {
                    $successMessages[] = $stat;
                } else {
                    $errorMessages[] = $stat;
                }
            }

            return response()->json(
                [
                    'Message' => 'Courses Processed Successfully!',
                    'success' => $successMessages,
                    'failed' => $errorMessages
                ],
                200
            );

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
    public function AddOrUpdateJuniorLecturers(Request $request)
    {
        try {
            $request->validate([
                'excel_file' => 'required|mimes:xlsx,xls'
            ]);
            $file = $request->file('excel_file');
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            $filteredData = [];
            foreach ($sheetData as $row) {
                $filteredData[] = array_slice($row, 0, 4); // Fetch only necessary columns
            }
            $nonEmpty = [];
            foreach ($filteredData as $row) {
                if (
                    array_filter($row, function ($value) {
                        return !is_null($value);
                    })
                ) {
                    if ($row['A'] != 'Name') {
                        $row = array_map('trim', $row);
                        $nonEmpty[] = $row;
                    }
                }
            }
            $RowNo = 1;
            $status = [];
            foreach ($nonEmpty as $singleRow) {
                $RowNo++;
                $name = $singleRow['A'];
                $dateOfBirth = $singleRow['B'];
                $gender = $singleRow['C'];
                $email = $singleRow['D'];
                $username = strtolower(str_replace(' ', '', $name)) . '_jl@biit.edu'; // Custom username format for JuniorLecturer
                $userExists = User::where('username', $username)->exists();
                if ($userExists) {
                    $status[] = ["status" => 'failed', "reason" => "Username {$username} already exists!"];
                    continue;
                }
                $formattedDOB = (new DateTime($dateOfBirth))->format('Y-m-d');
                $password = Action::generateUniquePassword($name);
                $userId = Action::addOrUpdateUser($username, $password, $email, 'JuniorLecturer'); // Set User type as JuniorLecturer

                if (!$userId) {
                    $status[] = ["status" => 'failed', "reason" => "Failed to create or update user for {$name}."];
                    continue;
                }
                $juniorLecturer = JuniorLecturer::where('name', $name)->first();

                if ($juniorLecturer) {
                    $juniorLecturer->update([
                        'user_id' => $userId,
                        'name' => $name,
                        'date_of_birth' => $formattedDOB,
                        'gender' => $gender
                    ]);
                    $status[] = ["status" => 'success', "Logs" => "The JuniorLecturer with Name: {$name} was updated."];
                } else {
                    JuniorLecturer::create([
                        'user_id' => $userId,
                        'name' => $name,
                        'date_of_birth' => $formattedDOB,
                        'gender' => $gender
                    ]);
                    $status[] = ["status" => 'success', "Logs" => "The JuniorLecturer with Name: {$name} was added."];
                }
            }

            // Categorize success and failed messages
            $successMessages = [];
            $errorMessages = [];
            foreach ($status as $stat) {
                if ($stat['status'] === 'success') {
                    $successMessages[] = $stat;
                } else {
                    $errorMessages[] = $stat;
                }
            }

            // Return the response
            return response()->json(
                [
                    'Message' => 'JuniorLecturers Processed Successfully!',
                    'success' => $successMessages,
                    'failed' => $errorMessages
                ],
                200
            );

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
    public function assignGrader(Request $request)
    {
        try {
            $request->validate([
                'excel_file' => 'required|mimes:xlsx,xls',
                'session_name' => 'required|string'
            ]);

            $file = $request->file('excel_file');
            $sessionName = $request->input('session_name');
            $sessionId = (new Session())->getSessionIdByName($sessionName);

            if ($sessionId === 0) {
                return response()->json([
                    'status' => 'failed',
                    'message' => "Invalid session name: {$sessionName}"
                ], 400);
            }
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);

            $filteredData = [];
            foreach ($sheetData as $row) {
                $filteredData[] = array_slice($row, 0, 5); // Fetch relevant columns
            }

            $nonEmpty = [];
            foreach ($filteredData as $row) {
                if (
                    array_filter($row, function ($value) {
                        return !is_null($value);
                    })
                ) {
                    if ($row['A'] != 'RegNo') {
                        $row = array_map('trim', $row);
                        $nonEmpty[] = $row;
                    }
                }
            }


            Grader::query()->update(['status' => 'in-active']);
            $status = [];
            foreach ($nonEmpty as $row) {
                $regNo = $row['A'];
                $name = $row['D'];
                $type = $row['E'];
                $studentId = Student::where('RegNo', $regNo)->value('id');
                if (!$studentId) {
                    $status[] = ["status" => 'failed', "reason" => "Student with RegNo {$regNo} not found."];
                    continue;
                }
                $grader = Grader::where('student_id', $studentId)->first();
                if ($grader) {
                    $grader->update([
                        'type' => $type,
                        'status' => 'active'
                    ]);
                } else {
                    $grader = grader::create([
                        'student_id' => $studentId,
                        'type' => $type,
                        'status' => 'active'
                    ]);
                }
                $teacherId = Teacher::where('name', $name)->value('id');
                if (!$teacherId) {
                    $status[] = ["status" => 'failed', "reason" => "Teacher with name {$name} not found."];
                    continue;
                }
                $checkGrader = teacher_grader::where('grader_id', $grader->id)
                    ->where('session_id', $sessionId)->get();
                if (count($checkGrader) > 0) {
                    $status[] = ["status" => 'error', "message" => "The Grader is Already Assigned to A differet teacher in given Session Cannot Assign 1 Grader to Multiple Teacher :  RegNo {$regNo}.", "teacher" => $name, "session" => $sessionName];
                    continue;
                }
                $teacherGrader = teacher_grader::where([
                    'grader_id' => $grader->id,
                    'teacher_id' => $teacherId,
                    'session_id' => $sessionId
                ])->first();
                if (!$teacherGrader) {
                    teacher_grader::create([
                        'grader_id' => $grader->id,
                        'teacher_id' => $teacherId,
                        'session_id' => $sessionId,
                        'feedback' => ''
                    ]);
                    $status[] = ["status" => 'success', "message" => "Grader assigned :  RegNo {$regNo}.", "teacher" => $name, "session" => $sessionName];
                } else {
                    $status[] = ["status" => 'success', "message" => "Grader is Already Assigned to The Teacher : RegNo {$regNo}.", "teacher" => $name, "session" => $sessionName];
                }
            }

            $successMessages = [];
            $errorMessages = [];
            foreach ($status as $stat) {
                if ($stat['status'] === 'success') {
                    $successMessages[] = $stat;
                } else {
                    $errorMessages[] = $stat;
                }
            }

            return response()->json([
                'Message' => 'Grader assignment processed.',
                'success' => $successMessages,
                'failed' => $errorMessages
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
    public function assignJuniorLecturer(Request $request)
    {
        try {
            $request->validate([
                'excel_file' => 'required|mimes:xlsx,xls',
                'session_name' => 'required|string'
            ]);
            $file = $request->file('excel_file');
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            $faultydata = [];
            foreach ($sheetData as $row) {
                $filteredData[] = array_slice($row, 0, 4);
            }
            $nonEmpty = [];
            foreach ($filteredData as $row) {
                if (
                    array_filter($row, function ($value) {
                        return !is_null($value);
                    })
                ) {
                    if ($row['A'] != 'Name') {
                        $row = array_map('trim', $row);
                        $nonEmpty[] = $row;
                    }
                }
            }

            $sessionName = $request->input('session_name');

            $sessionId = (new Session())->getSessionIdByName($sessionName);
            if (!$sessionId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid session name.'
                ], 400);
            }
            $status = [];
            $success = [];
            $faultydata = [];
            foreach ($nonEmpty as $row) {
                if (trim($row['A']) === 'Section') {
                    continue;
                }
                $sectionName = trim($row['A']);

                $courseTitle = trim($row['B']);
                $juniorLecturerName = trim($row['C']);
                $teacherName = trim($row['D']);
                $courseId = Course::where('name', $courseTitle)->value('id');
                if (!$courseId) {
                    $faultydata[] = [
                        'status' => 'failed',
                        'message' => "Course '$courseTitle' not found."
                    ];
                    continue;
                }
                $offeredCourseId = offered_courses::where('course_id', $courseId)
                    ->where('session_id', $sessionId)
                    ->value('id');

                if (!$offeredCourseId) {
                    $faultydata[] = [
                        'status' => 'failed',
                        'message' => "Offered course for '$courseTitle' not found in session '$sessionName'."
                    ];
                    continue;
                }
                $sectionId = section::addNewSection($sectionName);
                if (!$sectionId) {
                    $faultydata[] = [
                        'status' => 'failed',
                        'message' => "Section '$sectionName' not found."
                    ];
                    continue;
                }
                $teacherId = Teacher::where('name', $teacherName)->value('id');
                if (!$teacherId) {
                    $faultydata[] = [
                        'status' => 'failed',
                        'message' => "Teacher '$teacherName' not found."
                    ];
                    continue;
                }
                $teacherOfferedCourse = teacher_offered_courses::where([
                    'teacher_id' => $teacherId,
                    'section_id' => $sectionId,
                    'offered_course_id' => $offeredCourseId
                ])->first();

                if (!$teacherOfferedCourse) {
                    $faultydata[] = [
                        'status' => 'failed',
                        'message' => "Teacher Offered Course not found for Teacher '$teacherName', Course '$courseTitle', Section '$sectionName'."
                    ];
                    continue;
                }
                $juniorLecturerId = juniorlecturer::where('name', $juniorLecturerName)->value('id');
                if (!$juniorLecturerId) {
                    $faultydata[] = [
                        'status' => 'failed',
                        'message' => "Junior Lecturer '$juniorLecturerName' not found."
                    ];
                    continue;
                }
                $teacherJuniorLecturer = teacher_juniorlecturer::updateOrCreate(
                    [
                        'teacher_offered_course_id' => $teacherOfferedCourse->id,
                    ],
                    [
                        'juniorlecturer_id' => $juniorLecturerId
                    ]
                );
                $success[] = [
                    'status' => 'success',
                    'message' => "Assigned Junior Lecturer '$juniorLecturerName' for course '$courseTitle' in section '$sectionName'."
                ];
            }

            return response()->json([
                'status' => 'success',
                'Total Records' => count($nonEmpty),
                'Added' => count($success),
                'Failed' => count($faultydata),
                'Faulty DATA' => $faultydata,
                'Succes' => $success
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while processing the request.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function CreateExam(Request $request)
    {
        try {
            $request->validate([
                'offered_course_id' => 'required|exists:offered_courses,id',
                'type' => 'required|string',
                'Solid_marks' => 'required',
                'QuestionPaper' => 'required|file|mimes:pdf',
                'questions' => 'required|array',
                'questions.*.q_no' => 'required|integer',
                'questions.*.marks' => 'required|integer'
            ]);
            $offered_course_id = $request->offered_course_id;
            $type = $request->type;
            $Solid_marks = $request->Solid_marks;
            $questionPaperFile = $request->file('QuestionPaper');
            $TotalMarks = 0;
            $offered_course = offered_courses::where('id', $offered_course_id)->with(['course', 'session'])->first();
            if (!$offered_course) {
                throw new Exception('No Course Found in Given Session');
            }
            $course_name = $offered_course->course->name;
            $session_name = $offered_course->session->name . '-' . $offered_course->session->year;
            $directory = $session_name . '/' . 'Exam/' . $type;
            $madeupname = $course_name . '-(' . $session_name . ')-' . $type;
            $path = FileHandler::storeFile($madeupname, $directory, $questionPaperFile);
            $exam = exam::where('offered_course_id', $offered_course_id)
                ->where('type', $type)
                ->first();
            if ($exam) {
                throw new Exception('Exam Already Exsist');
            } else {
                $exam = exam::create([
                    'type' => $type,
                    'Solid_marks' => $Solid_marks,
                    'total_marks' => $TotalMarks,
                    'QuestionPaper' => $path,
                    'offered_course_id' => $offered_course_id,
                ]);
            }
            foreach ($request->questions as $questionData) {
                $TotalMarks += $questionData['marks'];
                $questionsData[] = [
                    'marks' => $questionData['marks'],
                    'q_no' => $questionData['q_no'],
                    'exam_id' => $exam->id,
                ];
            }
            if (!empty($questionsData)) {
                question::insert($questionsData);
            }
            $exam->update(['total_marks' => $TotalMarks]);
            return response()->json([
                'status' => 'Successfully Created !',
                'Exam' => $exam
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
                'message' => 'Invalid username or password'
            ], 404);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function UploadCourseContentTopic(Request $request)
    {
        try {
            $request->validate([
                'excel_file' => 'required|mimes:xlsx,xls',
                'offered_course_id' => 'required|exists:offered_courses,id',
            ]);
            $file = $request->file('excel_file');
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            $offeredCourse = offered_courses::where('id', $request->offered_course_id)->with(['course', 'session'])->first();
            if (!$offeredCourse) {
                return response()->json(['message' => 'Offered course not found'], 404);
            }

            $filteredData = [];
            $rowCount = count($sheetData);
            for ($rowIndex = 1; $rowIndex <= min(17, $rowCount); $rowIndex++) {
                $row = $sheetData[$rowIndex];
                if (
                    array_filter($row, function ($value) {
                        return !is_null($value);
                    })
                ) {
                    $filteredData[] = $row;
                }
            }

            $successfull = [];
            $RowCount = 1;
            foreach ($filteredData as $row) {
                if (trim($row['A'] == 'Week#')) {

                    $rowCount++;
                    continue;
                }

                $WeekNo = trim($row['A']);
                $Type = 'Notes';
                $WeekNo = (int) $WeekNo;
                $LecNo = ($WeekNo * 2) - 1;

                $LecNo1 = $WeekNo * 2;

                $title = $offeredCourse->course->description . '-Week' . $WeekNo . '-Lec(' . $LecNo . '-' . $LecNo1 . ')';
                $courseContent = coursecontent::firstOrCreate(
                    [
                        'week' => $WeekNo,
                        'offered_course_id' => $offeredCourse->id,
                        'type' => $Type,
                    ],
                    [
                        'title' => $title,
                    ]
                );
                $cell = 1;
                foreach ($row as $topic) {
                    if (!empty($topic)) {
                        if ($cell < 2) {
                            $cell++;
                            continue;
                        }
                        $topics = topic::firstOrCreate(
                            ['title' => trim($topic)],
                            ['title' => trim($topic)]
                        );

                        coursecontent_topic::firstOrCreate(
                            [
                                'coursecontent_id' => $courseContent->id,
                                'topic_id' => $topics->id,
                            ],
                        );
                        $rowCount++;
                        $successfull[] = ["status" => "success", "logs" => "Row {$rowCount} : The Topic {$topics->title} For Week {$WeekNo} has Added Successfully"];
                    } else {

                        continue;
                    }


                }
                $rowCount++;

            }
            return response()->json([
                'message' => 'Successfully Added !',
                'Stats' => $successfull,
            ], 200);

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
    public function UploadExamAwardList(Request $request)
    {
        try {
            $request->validate([
                'excel_file' => 'required|file|mimes:xlsx,xls',
                'section_id' => 'required|integer',
                'offered_course_id' => 'required|integer',
                'type' => 'required|string',
            ]);

            $section_id = $request->section_id;
            $offered_course_id = $request->offered_course_id;
            $examType = $request->type;
            $exam = exam::where('offered_course_id', $offered_course_id)
                ->where('type', $examType)
                ->first();
            if (!$exam) {
                throw new Exception('Exam not found for the given course and type.');
            }
            $examQuestions = question::where('exam_id', $exam->id)->pluck('q_no')->toArray();
            $students = student_offered_courses::where('section_id', $section_id)
                ->where('offered_course_id', $offered_course_id)
                ->with('student')
                ->get();
            $studentIds = $students->pluck('student_id')->toArray();
            $file = $request->file('excel_file');
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();
            $header = $rows[0];
            $format = [];
            foreach ($examQuestions as $q) {
                $format[] = "Q-No{$q}";
            }
            $filteredHeaders = array_filter($header, function ($header) {
                return $header !== null && $header !== "RegNo" && $header !== "Name";
            });
            $values = array_values($filteredHeaders);
            $duplicates = array_diff_assoc($values, array_unique($values));
            $missingFromValues = array_diff($format, $values);
            $extraInValues = array_diff($values, $format);
            if (!empty($duplicates)) {
                throw new Exception("Duplicates found in header data: " . implode(", ", $duplicates));
            }
            if (!empty($missingFromValues)) {
                throw new Exception("Questions missing from header data: " . implode(", ", $missingFromValues));
            }
            if (!empty($extraInValues)) {
                throw new Exception("Extra questions in header data: " . implode(", ", $extraInValues));
            }
            $filteredData = [];
            foreach ($rows as $row) {
                $filteredData[] = array_slice($row, 0, count($format) + 2);
            }
            $excelID = [];
            $RowCount = 1;
            $faultyData = [];
            $successfull = [];

            foreach (array_slice($filteredData, 1) as $eachRow) {

                $RegNo = $eachRow[0];
                $Name = $eachRow[1];
                $students = student::where('RegNo', $RegNo)->first();

                $excelID[] = $students->id;
                if (!$students) {
                    $faultyData[] = ["status" => "error", "issue" => "No STUDET FOUND WITH {$RegNo}/{$Name}"];
                    $RowCount++;
                    continue;
                }

                foreach (array_slice($eachRow, 2) as $index => $record) {
                    $question_id = question::where('exam_id', $exam->id)->where('q_no', ($index + 1))->value('id');
                    if ($question_id) {
                        student_exam_result::updateOrCreate(
                            [
                                'question_id' => $question_id,
                                'student_id' => $students->id,
                                'exam_id' => $exam->id,
                            ],
                            [
                                'obtained_marks' => $record,
                            ]
                        );
                        $qno = $index + 1;
                        $successfull[] = ["status" => "success", "Added" => " Qno {$qno} : Marks For {$RegNo}/{$Name} Added Successfully"];
                    }



                }

            }
            $missingStudentIds = array_diff($studentIds, $excelID);
            foreach ($missingStudentIds as $miss) {
                $student = student::find($miss);
                if ($student) {
                    $faultyData[] = ["status" => "error", "issue" => " You Missed the Record of  {$student->RegNo}/{$student->name}"];
                    foreach ($examQuestions as $qno) {
                        $question_id = question::where('exam_id', $exam->id)->where('q_no', $qno)->value('id');
                        student_exam_result::updateOrCreate(
                            [
                                'question_id' => $question_id,
                                'student_id' => $miss,
                                'exam_id' => $exam->id,
                            ],
                            [
                                'obtained_marks' => 0,
                            ]
                        );
                    }

                } else {
                    continue;
                }
            }
            return response()->json([
                'status' => 'success',
                'Total Records' => count($filteredData),
                'Added' => count($successfull),
                'Failed' => count($faultyData),
                'Faulty DATA' => $faultyData,
                'Succes' => $successfull
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
                'message' => 'Invalid username or password'
            ], 404);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function UploadTimetableExcel(Request $request)
    {
        try {
            $request->validate([
                'excel_file' => 'required|mimes:xlsx,xls',
                'session' => 'required'
            ]);

            $session_name = $request->session;
            $session_id = (new session())->getSessionIdByName($session_name);
            if (!$session_id) {
                $session_id = (new session())->getUpcomingSessionId();
            }
            $file = $request->file('excel_file');
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            $NonEmptyRow = [];
            foreach ($sheetData as $row) {
                if (
                    array_filter($row, function ($value) {
                        return !is_null($value);
                    })
                ) {
                    $row = array_map('trim', $row);
                    $NonEmptyRow[] = $row;
                }
            }
            $sectionList = [];
            foreach ($NonEmptyRow[0] as $firstRowCell) {
                if ($firstRowCell != null || $firstRowCell != '') {
                    $sectionList[] = $firstRowCell;
                }
            }
            $ColumnEmptyCheck = [];
            $sliceLength = count($sectionList);
            foreach ($NonEmptyRow as $row) {
                $slicedRow = array_slice($row, 0, $sliceLength);
                $ColumnEmptyCheck[] = $slicedRow;
            }
            $Monday = [];
            $Tuesday = [];
            $Wednesday = [];
            $Thursday = [];
            $Firday = [];
            $WhatIs = '';
            foreach ($ColumnEmptyCheck as $row) {
                if ($row['A'] == 'Monday') {
                    $WhatIs = 'Monday';
                    continue;
                } else if ($row['A'] == 'Tuesday') {
                    $WhatIs = 'Tuesday';
                    continue;
                } else if ($row['A'] == 'Wednesday') {
                    $WhatIs = 'Wednesday';
                    continue;
                } else if ($row['A'] == 'Thursday') {
                    $WhatIs = 'Thursday';
                    continue;
                } else if ($row['A'] == 'Friday') {
                    $WhatIs = 'Friday';
                    continue;
                } else {
                    if ($WhatIs == 'Monday') {
                        $Monday[] = $row;
                    }
                    if ($WhatIs == 'Tuesday') {
                        $Tuesday[] = $row;
                    }
                    if ($WhatIs == 'Wednesday') {
                        $Wednesday[] = $row;
                    }
                    if ($WhatIs == 'Thursday') {
                        $Thursday[] = $row;
                    }
                    if ($WhatIs == 'Friday') {
                        $Firday[] = $row;
                    }
                }
            }

            function convertTo24HourFormat($time)
            {
                return date("H:i:s", strtotime($time));
            }
            $Success = [];
            $Error = [];
            foreach ($Monday as $MondayRows) {
                $Time = $MondayRows['A'];
                $Day = 'Monday';
                list($startTime, $endTime) = explode(' - ', $Time);
                $startTimeFormatted = convertTo24HourFormat($startTime);
                $endTimeFormatted = convertTo24HourFormat($endTime);

                $dayslot = dayslot::firstOrCreate(
                    [
                        'day' => $Day,
                        'start_time' => $startTimeFormatted,
                        'end_time' => $endTimeFormatted,
                    ]
                );

                $dayslotId = $dayslot->id;

                foreach ($MondayRows as $index => $value) {

                    if ($value == $Time) {
                        continue;
                    } else if ($value != null && $value != '') {
                        $timetable = Action::insertOrCreateTimetable($value, $dayslotId);
                        if ($timetable) {
                            if ($timetable['status'] == 'error') {
                                $Error[] = $timetable;
                            } else {
                                $Success[] = $Error[] = [
                                    "status" => "success",
                                    "Day" => $Day,
                                    "Time" => $startTimeFormatted . '-' . $endTimeFormatted,
                                    "Record" => $value
                                ];
                            }
                        } else if ($timetable == null || !$timetable) {
                            $Error[] = [
                                "Day" => $Day,
                                "Time" => $startTimeFormatted . '' . $endTimeFormatted,
                                "Raw Data" => $value
                            ];
                        }
                    } else {
                        continue;
                    }
                }
            }
            foreach ($Tuesday as $TuesdayRows) {
                $Time = $TuesdayRows['A'];
                $Day = 'Tuesday';
                list($startTime, $endTime) = explode(' - ', $Time);
                $startTimeFormatted = convertTo24HourFormat($startTime);
                $endTimeFormatted = convertTo24HourFormat($endTime);

                $dayslot = dayslot::firstOrCreate(
                    [
                        'day' => $Day,
                        'start_time' => $startTimeFormatted,
                        'end_time' => $endTimeFormatted,
                    ]
                );
                $dayslotId = $dayslot->id;

                foreach ($TuesdayRows as $index => $value) {
                    if ($value == $Time) {
                        continue;
                    } else if ($value != null && $value != '') {
                        $timetable = Action::insertOrCreateTimetable($value, $dayslotId);
                        if ($timetable) {
                            if ($timetable['status'] == 'error') {
                                $Error[] = $timetable;
                            } else {
                                $Success[] = $Error[] = [
                                    "status" => "success",
                                    "Day" => $Day,
                                    "Time" => $startTimeFormatted . '-' . $endTimeFormatted,
                                    "Record" => $value
                                ];
                            }
                        } else if ($timetable == null || !$timetable) {
                            $Error[] = [
                                "Day" => $Day,
                                "Time" => $startTimeFormatted . '' . $endTimeFormatted,
                                "Raw Data" => $value
                            ];
                        }
                    } else {
                        continue;
                    }
                }
            }
            foreach ($Wednesday as $WedRows) {
                $Time = $WedRows['A'];
                $Day = 'Wednesday';
                list($startTime, $endTime) = explode(' - ', $Time);
                $startTimeFormatted = convertTo24HourFormat($startTime);
                $endTimeFormatted = convertTo24HourFormat($endTime);

                $dayslot = dayslot::firstOrCreate(
                    [
                        'day' => $Day,
                        'start_time' => $startTimeFormatted,
                        'end_time' => $endTimeFormatted,
                    ]
                );
                $dayslotId = $dayslot->id;

                foreach ($WedRows as $index => $value) {
                    if ($value == $Time) {
                        continue;
                    } else if ($value != null && $value != '') {
                        $timetable = Action::insertOrCreateTimetable($value, $dayslotId);
                        if ($timetable) {
                            if ($timetable['status'] == 'error') {
                                $Error[] = $timetable;
                            } else {
                                $Success[] = $Error[] = [
                                    "status" => "success",
                                    "Day" => $Day,
                                    "Time" => $startTimeFormatted . '-' . $endTimeFormatted,
                                    "Record" => $value
                                ];
                            }

                        } else if ($timetable == null || !$timetable) {
                            $Error[] = [
                                "Day" => $Day,
                                "Time" => $startTimeFormatted . '' . $endTimeFormatted,
                                "Raw Data" => $value
                            ];
                        }
                    } else {
                        continue;
                    }
                }
            }
            foreach ($Thursday as $ThuRows) {
                $Time = $ThuRows['A'];
                $Day = 'Thursday';
                list($startTime, $endTime) = explode(' - ', $Time);
                $startTimeFormatted = convertTo24HourFormat($startTime);
                $endTimeFormatted = convertTo24HourFormat($endTime);

                $dayslot = dayslot::firstOrCreate(
                    [
                        'day' => $Day,
                        'start_time' => $startTimeFormatted,
                        'end_time' => $endTimeFormatted,
                    ]
                );
                $dayslotId = $dayslot->id;

                foreach ($ThuRows as $index => $value) {
                    if ($value == $Time) {
                        continue;
                    } else if ($value != null && $value != '') {
                        $timetable = Action::insertOrCreateTimetable($value, $dayslotId);
                        if ($timetable) {
                            if ($timetable['status'] == 'error') {
                                $Error[] = $timetable;
                            } else {
                                $Success[] = $Error[] = [
                                    "status" => "success",
                                    "Day" => $Day,
                                    "Time" => $startTimeFormatted . '-' . $endTimeFormatted,
                                    "Record" => $value
                                ];
                            }
                        } else if ($timetable == null || !$timetable) {
                            $Error[] = [
                                "Day" => $Day,
                                "Time" => $startTimeFormatted . '' . $endTimeFormatted,
                                "Raw Data" => $value
                            ];
                        }
                    } else {
                        continue;
                    }
                }
            }

            foreach ($Firday as $FriRows) {
                $Time = $FriRows['A'];
                $Day = 'Friday';
                list($startTime, $endTime) = explode(' - ', $Time);
                $startTimeFormatted = convertTo24HourFormat($startTime);
                $endTimeFormatted = convertTo24HourFormat($endTime);

                $dayslot = dayslot::firstOrCreate(
                    [
                        'day' => $Day,
                        'start_time' => $startTimeFormatted,
                        'end_time' => $endTimeFormatted,
                    ]
                );
                $dayslotId = $dayslot->id;
                foreach ($FriRows as $index => $value) {
                    if ($value == $Time) {
                        continue;
                    } else if ($value != null && $value != '') {
                        $timetable = Action::insertOrCreateTimetable($value, $dayslotId);
                        if ($timetable) {
                            if ($timetable['status'] == 'error') {
                                $Error[] = $timetable;
                            } else {
                                $Success[] = $Error[] = [
                                    "status" => "success",
                                    "Day" => $Day,
                                    "Time" => $startTimeFormatted . '-' . $endTimeFormatted,
                                    "Record" => $value
                                ];
                            }
                        } else if ($timetable == null || !$timetable) {
                            $Error[] = [
                                "Day" => $Day,
                                "Time" => $startTimeFormatted . '' . $endTimeFormatted,
                                "Raw Data" => $value
                            ];
                        }
                    } else {
                        continue;
                    }
                }
            }
            $successCount = count($Success);
            $errorCount = count($Error);
            return response()->json(
                [
                    'Message' => 'Data Inserted Successfully !',
                    'data' => [
                        "Successfully Added Records Count :" => $successCount,
                        "Faulty Records Count :" => $errorCount,
                        "Sucess" => $Success,
                        "Error" => $Error,
                    ]
                ],
                200
            );
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid username or password'
            ], 404);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getTimetableGroupedBySection(Request $request)
    {
        $session_id = $request->session_id ?? (new Session())->getCurrentSessionId();
        if (!$session_id) {
            return response()->json(['error' => 'Session ID is required.'], 400);
        }
        $timetable = Timetable::with([
            'course:name,id,description',
            'teacher:name,id',
            'venue:venue,id',
            'dayslot:day,start_time,end_time,id',
            'juniorLecturer:id,name',
        ])
            ->where('session_id', $session_id)
            ->get()
            ->map(function ($item) {
                return [
                    'section' => (new Section())->getNameByID($item->section_id) ?? 'N/A',
                    'name' => $item->course->name ?? 'N/A',
                    'description' => $item->course->description ?? 'N/A',
                    'teacher' => $item->teacher->name ?? 'N/A',
                    'junior_lecturer' => $item->juniorLecturer->name ?? 'N/A',
                    'venue' => $item->venue->venue ?? 'N/A',
                    'day' => $item->dayslot->day ?? 'N/A',
                    'time' => ($item->dayslot->start_time && $item->dayslot->end_time)
                        ? $item->dayslot->start_time . ' - ' . $item->dayslot->end_time
                        : 'N/A',
                ];
            })
            ->groupBy('section');

        return response()->json([
            "status" => "Successfull",
            "timetable" => $timetable,
        ], 200);
    }
    public function CreateCourseContent(Request $request)
    {
        try {
            $request->validate([
                'data' => 'required|array',
                'offered_course_id' => 'required|exists:offered_courses,id',
            ]);

            $offeredCourse = offered_courses::where('id', $request->offered_course_id)
                ->with(['course', 'session'])
                ->first();

            if (!$offeredCourse) {
                return response()->json(['message' => 'Offered course not found'], 404);
            }

            $successfull = [];
            $index = 0;
            foreach ($request->data as $row) {
                $type = $row['type'] ?? null;
                $weekNo = $row['WeekNo'] ?? null;
                $file = $row['file'] ?? null;
                if (is_null($type) || is_null($weekNo)) {
                    $successfull[] = [
                        "status" => "error",
                        "logs" => "Row skipped: Missing type or WeekNo.",
                    ];
                    continue;
                }

                $weekNo = (int) $weekNo;
                $title = $offeredCourse->course->description . '-Week' . $weekNo;
                if (in_array($type, ['Quiz', 'Assignment'])) {
                    $existingCount = coursecontent::where('week', $weekNo)
                        ->where('offered_course_id', $offeredCourse->id)
                        ->where('type', $type)
                        ->count();
                    $title .= '-' . $type . '#(' . ($existingCount + 1) . ')';
                }
                if ($type === 'Notes') {
                    $lecNo = ($weekNo * 2) - 1;
                    $lecNo1 = $weekNo * 2;
                    $title .= '-Lec(' . $lecNo . '-' . $lecNo1 . ')';
                }
                $courseContent = coursecontent::updateOrCreate(
                    [
                        'title' => $title,
                        'week' => $weekNo,
                        'offered_course_id' => $offeredCourse->id,
                        'type' => $type,
                    ],
                    []
                );
                if ($file === 'MCQS' && isset($row['MCQS']) && is_array($row['MCQS'])) {
                    $courseContent->type = 'Quiz';
                    $courseContent->content = 'MCQS';
                    $courseContent->save();
                    foreach ($row['MCQS'] as $mcq) {
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
                            $successfull[] = [
                                "status" => "error",
                                "logs" => "Row skipped: Missing MCQS question data.",
                            ];
                            continue;
                        }
                        $quizQuestion = quiz_questions::create([
                            'question_no' => $questionNo,
                            'question_text' => $questionText,
                            'points' => $points,
                            'coursecontent_id' => $courseContent->id,
                        ]);

                        foreach ($options as $index => $optionText) {
                            if ($optionText) {
                                \App\Models\options::create([
                                    'quiz_question_id' => $quizQuestion->id,
                                    'option_text' => $optionText,
                                    'is_correct' => trim($optionText) === trim($answer),
                                ]);
                            }
                        }
                    }
                } else {
                    if (in_array($file->getClientOriginalExtension(), ['pdf', 'doc', 'docx', 'xlsx', 'xls'])) {
                        $directory = $offeredCourse->session->name . '-' . $offeredCourse->session->year . '/Course Content' . '/' . $offeredCourse->course->description;
                        $filePath = FileHandler::storeFile($title, $directory, $file);
                        $courseContent->content = $filePath;
                        $courseContent->save();
                    } else {
                        $successfull[] = [
                            "status" => "error",
                            "logs" => "Row skipped: Invalid file type.",
                        ];
                        continue;
                    }
                }
                $successfull[] = [
                    "status" => "success",
                    "logs" => "The content for Week {$weekNo} ({$type}) has been added successfully."
                ];
            }
            return response()->json([
                'message' => 'Successfully added course content!',
                'Stats' => $successfull,
            ], 200);
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
    public function sendNotification(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string|max:1000',
                'url' => 'nullable|url|max:255',
                'sender' => 'required|in:Teacher,JuniorLecturer,Admin,Datacell',
                'reciever' => 'required|in:Teacher,JuniorLecturer,Student,Datacell',
                'Brodcast' => 'nullable|boolean',
                'Student_Section' => 'nullable|integer',
                'TL_receiver_id' => 'nullable|integer',
                'TL_sender_id' => 'nullable|integer'
            ]);

            $senderId = $request->TL_sender_id;
            $receiverId = $request->TL_receiver_id;

            $sender = user::find($senderId);
            if (!$sender) {
                return response()->json(['message' => 'Sender not found.'], 404);
            }

            $senderRole = role::find($sender->role_id);
            if (!$senderRole || $senderRole->type != $request->sender) {
                return response()->json(['message' => 'Sender role does not match the sender ID.'], 400);
            }

            // Handle Broadcast logic
            $isBroadcast = $request->Brodcast ?? 0; // Default to 0 if Brodcast is null
            if ($isBroadcast == 1) {
                $receiverId = null;
            }

            $data = [
                'title' => $request->title,
                'description' => $request->description,
                'url' => $request->url,
                'notification_date' => now(),
                'sender' => $request->sender,
                'reciever' => $request->reciever,
                'Brodcast' => $isBroadcast,
                'Student_Section' => $isBroadcast == 0 && $request->Student_Section ? $request->Student_Section : null,
                'TL_receiver_id' => $receiverId,
                'TL_sender_id' => $senderId == 0 ? null : $senderId,
            ];

            $notification = notification::create($data);

            return response()->json([
                'message' => 'Notification sent successfully!',
                'data' => $notification,
            ], 201);
        } catch (Exception $ex) {
            return response()->json(['message' => 'Failed to send notification.', 'error' => $ex->getMessage()], 500);
        }
    }
    public function AddSubjectResult(Request $request)
    {
        try {
            $request->validate([
                'excel_file' => 'required|file|mimes:xlsx,xls',
                'section_id' => 'required|integer',
                'offered_course_id' => 'required|integer',
            ]);
            $section_id = $request->section_id;
            $offered_course_id = $request->offered_course_id;
            $offeredCourse = offered_courses::with('course:id,name,credit_hours,lab')->findOrFail($offered_course_id);
            $course = $offeredCourse->course;
            $isLab = $course->lab == 1;
            $creditHours = $course->credit_hours;
            $marksDistribution = match ($creditHours) {
                4 => $isLab ? ['mid' => 18, 'internal' => 12, 'final' => 30, 'lab' => 20] : ['mid' => 18, 'internal' => 12, 'final' => 50],
                3 => $isLab ? ['mid' => 12, 'internal' => 8, 'final' => 20, 'lab' => 20] : ['mid' => 18, 'internal' => 12, 'final' => 30],
                2 => ['mid' => 12, 'internal' => 8, 'final' => 20],
                1 => ['mid' => 8, 'internal' => 4, 'final' => 12],
                default => throw new Exception("Invalid credit hours configuration for course"),
            };
            $totalMarks = array_sum($marksDistribution);
            $passingMarks = 23;
            $gradeThresholds = [
                'A' => ceil(0.8 * $totalMarks), // 80%
                'B' => ceil(0.7 * $totalMarks), // 70%
                'C' => ceil(0.55 * $totalMarks), // 55%
                'D' => ceil(0.4 * $totalMarks), // 40%
                'F' => $passingMarks - 1,       // Below 23
            ];
            $qualityPointsMultiplier = match ($creditHours) {
                4 => 16,
                3 => 12,
                2 => 8,
                1 => 4,
                default => 0,
            };
            $file = $request->file('excel_file');
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();
            $header = $rows[0];
            $expectedHeaders = ['RegNo', 'Name', 'Mid', 'Internal', 'Final'];
            if ($isLab) {
                $expectedHeaders[] = 'Lab';
            }

            if (array_slice($header, 0, count($expectedHeaders)) !== $expectedHeaders) {
                throw new Exception("Invalid header format. Expected: " . implode(", ", $expectedHeaders));
            }
            $filteredData = array_slice($rows, 1);
            $dbStudents = student_offered_courses::with('student')
                ->where('offered_course_id', $offered_course_id)
                ->where('section_id', $section_id)
                ->get();

            $dbStudentIds = $dbStudents->pluck('student.id')->toArray();
            $excelStudentIds = [];
            $success = [];
            $faultyData = [];

            foreach ($filteredData as $row) {
                $regNo = $row[0];
                $name = $row[1];
                $mid = $row[2] ?? 0;
                $internal = $row[3] ?? 0;
                $final = $row[4] ?? 0;
                $lab = $isLab ? ($row[5] ?? 0) : 0;

                $student = student::where('RegNo', $regNo)->first();
                if (!$student) {
                    $faultyData[] = ["status" => "error", "issue" => "Student with RegNo {$regNo} not found"];
                    continue;
                }

                $excelStudentIds[] = $student->id;

                $studentOfferedCourse = student_offered_courses::where('student_id', $student->id)
                    ->where('offered_course_id', $offered_course_id)
                    ->first();

                if (!$studentOfferedCourse) {
                    $faultyData[] = ["status" => "error", "issue" => "Student not enrolled in the course"];
                    continue;
                }

                $obtainedMarks = $mid + $internal + $final + $lab;
                $grade = $this->calculateGrade($obtainedMarks, $gradeThresholds);
                $qualityPoints = $qualityPointsMultiplier * ($grade === 'F' ? 0 : ($grade === 'A' ? 4 : ($grade === 'B' ? 3 : ($grade === 'C' ? 2 : 1))));
                subjectresult::updateOrCreate(
                    [
                        'student_offered_course_id' => $studentOfferedCourse->id,
                    ],
                    [
                        'mid' => $mid,
                        'internal' => $internal,
                        'final' => $final,
                        'lab' => $lab,
                        'grade' => $grade,
                        'quality_points' => $qualityPoints,
                    ]
                );
                $studentOfferedCourse->grade = $grade; // Replace $newGrade with the desired grade value
                $studentOfferedCourse->save();
                $success[] = ["status" => "success", "message" => "Result added for {$regNo}"];
            }
            $missingStudentIds = array_diff($dbStudentIds, $excelStudentIds);
            foreach ($missingStudentIds as $missingId) {
                $studentOfferedCourse = student_offered_courses::where('student_id', $missingId)
                    ->where('offered_course_id', $offered_course_id)
                    ->first();
                student_offered_courses::where('id', $missingId)->update(['grade' => 'F']);
                if ($studentOfferedCourse) {
                    subjectresult::updateOrCreate(
                        [
                            'student_offered_course_id' => $studentOfferedCourse->id,
                        ],
                        [
                            'mid' => 0,
                            'internal' => 0,
                            'final' => 0,
                            'lab' => $isLab ? 0 : null,
                            'grade' => 'F',
                            'quality_points' => 0,
                        ]
                    );

                    $faultyData[] = ["status" => "error", "issue" => "Missing record for student {$missingId}. Assigned grade 'F'"];
                }
            }
            return response()->json([
                'status' => 'success',
                'Total Records' => count($filteredData),
                'Added' => count($success),
                'Failed' => count($faultyData),
                'Faulty Data' => $faultyData,
                'Success' => $success,
            ], 200);
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
    private function calculateGrade($marks, $thresholds)
    {
        return match (true) {
            $marks >= $thresholds['A'] => 'A',
            $marks >= $thresholds['B'] => 'B',
            $marks >= $thresholds['C'] => 'C',
            $marks >= $thresholds['D'] => 'D',
            default => 'F',
        };
    }
    public function updateDataCellImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'datacell_id' => 'required',
            'image' => 'required|image',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $datacell_id = $request->datacell_id;
            $file = $request->file('image');

            // Fetch data cell details
            $datacell = datacell::find($datacell_id);
            if (!$datacell) {
                throw new Exception("DataCell not found");
            }

            // Store file in a directory
            $directory = 'Images/DataCell';
            $storedFilePath = FileHandler::storeFile($datacell->user_id, $directory, $file);

            // Update the data cell's image path
            $datacell->update(['image' => $storedFilePath]);

            return response()->json([
                'success' => true,
                'message' => "Image updated successfully for DataCell: $datacell->name"
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }


}
