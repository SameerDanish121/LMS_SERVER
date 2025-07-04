<?php

namespace App\Http\Controllers;

use App\Models\admin;
use App\Models\datacell;
use App\Models\Director;
use App\Models\Hod;
use App\Models\juniorlecturer;
use App\Models\notification;
use App\Models\parents;
use App\Models\parent_student;
use App\Models\role;
use App\Models\student;
use App\Models\teacher;
use App\Models\user;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ParentsController extends Controller
{
    public function Notification(Request $request)
    {
        try {
            $parent_id = $request->parent_id;
            $student = parents::find($parent_id);

            if (!$student) {
                return response()->json(['status' => 'error', 'message' => 'Student not found.'], 404);
            }

            $user_id = $student->user_id;


            $broadcastAll = notification::where('Brodcast', 1)
                ->whereNull('reciever');

            // Scenario 2: Broadcast to Students
            $broadcastToStudents = notification::where('Brodcast', 1)
                ->where('reciever', 'Parent');

            // Scenario 3: Direct to user
            $directToUser = notification::where('TL_receiver_id', $user_id);



            // Merge all queries
            $notifications = $broadcastAll->get()
                ->merge($broadcastToStudents->get())
                ->merge($directToUser->get());

            // Remove duplicates and sort by date
            $sortedNotifications = $notifications->unique('id')->sortByDesc('notification_date')->values();

            // Map and enhance each notification
            $finalResponse = $sortedNotifications->map(function ($note) {
                $senderName = 'System';
                $image = null;
                $type = null;
                $imageOrLink = null;

                if ($note->sender === 'Admin') {
                    $admin = admin::where('user_id', $note->TL_sender_id)->first();
                    if ($admin) {
                        $senderName = $admin->name ?? 'N/A';
                        $image = $admin->image ? asset($admin->image) : null;
                    }
                } else if ($note->sender === 'DataCell') {
                    $datacell = datacell::where('user_id', $note->TL_sender_id)->first();
                    if ($datacell) {
                        $senderName = $datacell->name ?? 'N/A';
                        $image = $datacell->image ? asset($datacell->image) : null;
                    }
                } else if ($note->sender === 'JuniorLecturer') {
                    $datacell = juniorlecturer::where('user_id', $note->TL_sender_id)->first();
                    if ($datacell) {
                        $senderName = $datacell->name ?? 'N/A';
                        $image = $datacell->image ? asset($datacell->image) : null;
                    }
                } else if ($note->sender === 'Teacher') {
                    $datacell = teacher::where('user_id', $note->TL_sender_id)->first();
                    if ($datacell) {
                        $senderName = $datacell->name ?? 'N/A';
                        $image = $datacell->image ? asset($datacell->image) : null;
                    }
                } else if ($note->sender == 'Director') {
                    $datacell = Director::where('user_id', $note->TL_sender_id)->first();
                    if ($datacell) {
                        $senderName = $datacell->name ?? 'N/A';
                        $image = $datacell->image ? asset($datacell->image) : null;
                    }
                } else if ($note->sender == 'HOD') {
                    $datacell = Hod::where('user_id', $note->TL_sender_id)->first();
                    if ($datacell) {
                        $senderName = $datacell->name ?? 'N/A';
                        $image = $datacell->image ? asset($datacell->image) : null;
                    }
                }

                if (!empty($note->url)) {
                    if (str_contains($note->url, 'https://') || str_contains($note->url, 'http://')) {
                        $type = 'link';
                        $imageOrLink = $note->url;
                    } else {
                        $type = 'image';
                        $imageOrLink = asset($note->url);
                    }
                }

                return [
                    'id' => $note->id,
                    'title' => $note->title,
                    'description' => $note->description,
                    'url' => $note->url,
                    'notification_date' => $note->notification_date,
                    'sender' => $note->sender,
                    'sender_name' => $senderName,
                    'sender_image' => $image,
                    'media_type' => $type,
                    'media' => $imageOrLink
                ];
            });

            return response()->json([
                'status' => 'success',
                'data' => $finalResponse
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getGroupedParents()
    {
        $students = Student::with(['section', 'parents'])->get();

        $data = $students->map(function ($student) {
            return [
                'student_id' => $student->id,
                'student_name' => $student->name,
                'reg_no' => $student->RegNo,
                'section_id' => $student->section?->id,
                'section' => $student->section ? $student->section->program . '-' . $student->section->semester . $student->section->group : null,
                'parents' => $student->parents->map(function ($parent) {
                    return [
                        'parent_id' => $parent->id,
                        'name' => $parent->name,
                        'relation' => $parent->relation_with_student,
                        'contact' => $parent->contact,
                        'address' => $parent->address,

                    ];
                }),
            ];
        });

        return response()->json([
            'message' => 'Parent data grouped by student fetched successfully.',
            'data' => $data
        ], 200);
    }

    public function AddParents(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|integer|exists:student,id',
            'name' => 'required|string|max:100',
            'relation_with_student' => 'nullable|string|max:50',
            'contact' => 'nullable|string|max:20',
            'address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $studentId = $request->student_id;

        // Check if this student already has this parent
        $exists = parent_student::where('student_id', $studentId)
            ->whereHas('parent', function ($q) use ($request) {
                $q->where('name', $request->name);
            })->exists();

        if ($exists) {
            return response()->json(['message' => 'This parent already exists for the student.'], 409);
        }

        // Get or create "Parent" role
        $parentRole = role::firstOrCreate(['type' => 'Parent']);

        // Get student info for username generation
        $student = student::find($studentId);
        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        // Generate username: studentRegNo@parent (lowercase, no spaces)
        $relation = strtolower(str_replace(' ', '_', $request->relation_with_student)); // e.g., "Father" → "father"
        $baseUsername = $student->RegNo . '_' . $relation . '@parent';

        // Ensure username is unique, add number suffix if needed
        $username = $baseUsername;
        $suffix = 1;
        while (user::where('username', $username)->exists()) {
            $username = $baseUsername . $suffix;
            $suffix++;
        }

        // Generate random password (10 chars)
        $randomPassword = Str::random(10);

        // Create user for parent
        $parentUser = user::create([
            'username' => $username,
            'password' => $randomPassword,
            'email' => null, // email optional, can update later via API
            'role_id' => $parentRole->id,
        ]);

        // Create parent record linked to user and student
        $parent = parents::create([
            'user_id' => $parentUser->id,
            'name' => $request->name,
            'relation_with_student' => $request->relation_with_student ?? null,
            'contact' => $request->contact ?? null,
            'address' => $request->address ?? null,
        ]);

        // Create parent_student relation
        parent_student::create([
            'parent_id' => $parent->id,
            'student_id' => $studentId,
        ]);

        return response()->json([
            'message' => 'Parent created successfully.',
            'parent' => $parent,
            'username' => $username,
            'password' => $randomPassword, // Return for initial use
        ], 201);
    }

    // 2. Update Parent (name, contact, address)
    public function Update(Request $request, $parentId)
    {
        $parent = parents::find($parentId);
        if (!$parent) {
            return response()->json(['message' => 'Parent not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:100',
            'contact' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string',
            'relation_with_student' => 'sometimes|nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $parent->fill($request->only(['name', 'contact', 'address', 'relation_with_student']));
        $parent->save();

        return response()->json(['message' => 'Parent updated successfully.', 'parent' => $parent], 200);
    }

    // 3. Remove Parent (safe delete)
    // public function Remove($parentId)
    // {
    //     $parent = parents::find($parentId);
    //     if (!$parent) {
    //         return response()->json(['message' => 'Parent not found.'], 404);
    //     }
    //     parent_student::where('parent_id', $parentId)->delete();
    //     $parent->delete();
    //     $user = user::find($parent->user_id);
    //     if ($user) {
    //         $user->delete();
    //     }
    //     return response()->json(['message' => 'Parent and associated user deleted successfully.'], 200);
    // }


public function Remove($parentId)
{
    try {
        DB::beginTransaction();

        $parent = parents::find($parentId);
        if (!$parent) {
            return response()->json(['message' => 'Parent not found.'], 404);
        }

        $userId = $parent->user_id;
        parent_student::where('parent_id', $parentId)->delete();
        notification::where('TL_receiver_id', $userId)->delete();
        $parent->delete();
        $user = user::find($userId);
        if ($user) {
            $user->delete();
        }

        DB::commit();
        return response()->json(['message' => 'Parent, associated user, and notifications deleted successfully.'], 200);
    } catch (Exception $e) {
        DB::rollBack();
        Log::error('Error deleting parent: '.$e->getMessage());

        return response()->json([
            'error' => 'Unexpected error: ' . $e->getMessage()
        ], 500);
    }
}
    public function UpdateEmail(Request $request, $parentId)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:user,email',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $parent = parents::find($parentId);
        if (!$parent) {
            return response()->json(['message' => 'Parent not found.'], 404);
        }

        $user = user::find($parent->user_id);
        if (!$user) {
            return response()->json(['message' => 'Associated user not found.'], 404);
        }

        $user->email = $request->email;
        $user->save();

        return response()->json(['message' => 'Email updated successfully.', 'email' => $user->email], 200);
    }

    // 5. Update Password of Parent's user
    public function UpdatePassword(Request $request, $parentId)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string|min:6', // expects password_confirmation field
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $parent = parents::find($parentId);
        if (!$parent) {
            return response()->json(['message' => 'Parent not found.'], 404);
        }

        $user = user::find($parent->user_id);
        if (!$user) {
            return response()->json(['message' => 'Associated user not found.'], 404);
        }

        $user->password = $request->password;
        $user->save();

        return response()->json(['message' => 'Password updated successfully.'], 200);
    }
    public function AssignExistingParentToStudent(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|integer|exists:student,id',
            'parent_id' => 'required|integer|exists:parents,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $studentId = $request->student_id;
        $parentId = $request->parent_id;
        $alreadyAssigned = parent_student::where('student_id', $studentId)
            ->where('parent_id', $parentId)
            ->exists();
        if ($alreadyAssigned) {
            return response()->json([
                'message' => 'This parent is already assigned to the selected student.'
            ], 409);
        }
        parent_student::create([
            'student_id' => $studentId,
            'parent_id' => $parentId,
        ]);
        return response()->json([
            'message' => 'Existing parent successfully assigned to the student.',
        ], 201);
    }

}
