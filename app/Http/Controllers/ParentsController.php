<?php

namespace App\Http\Controllers;

use App\Models\parents;
use App\Models\parent_student;
use App\Models\role;
use App\Models\student;
use App\Models\user;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class ParentsController extends Controller
{
    // 1. Add Parent

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
    public function Remove($parentId)
    {
        $parent = parents::find($parentId);
        if (!$parent) {
            return response()->json(['message' => 'Parent not found.'], 404);
        }

        // Delete parent_student relations first
        parent_student::where('parent_id', $parentId)->delete();

        // Delete parent record
        $parent->delete();

        // Delete associated user
        $user = user::find($parent->user_id);
        if ($user) {
            $user->delete();
        }

        return response()->json(['message' => 'Parent and associated user deleted successfully.'], 200);
    }

    // 4. Update Email of Parent's user
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
}
