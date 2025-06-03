<?php

namespace App\Http\Controllers;

use App\Models\user;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;


class AllControlsController extends Controller
{
  
public function allUser()
{
    try {
        $users = user::with('role')->get();
        $roleMap = [
            'Admin' => \App\Models\admin::class,
            'Datacell' => \App\Models\datacell::class,
            'Director' => \App\Models\Director::class,
            'HOD' => \App\Models\Hod::class,
            'Student' => \App\Models\student::class,
            'Teacher' => \App\Models\teacher::class,
            'JuniorLecturer' => \App\Models\juniorlecturer::class,
            'Parent'=> \App\Models\parents::class
        ];
        $result = [];  
        $totalUsers = 0;
        foreach ($roleMap as $role => $modelClass) {
            $relatedUsers = $modelClass::with('user')
                ->whereHas('user', function ($query) use ($role) {
                    $query->whereHas('role', function ($q) use ($role) {
                        $q->where('type', $role);
                    });
                })
                ->get()
                ->map(function ($item) {
                    return [
                        'user_id' => $item->user->id,
                        'username' => $item->user->username,
                        'email' => $item->user->email,
                        'password' => $item->user->password,
                        'name' => $item->name,
                        'image' => $item->image?asset($item->image):null,
                    ];
                });
            if ($relatedUsers->isNotEmpty()) {
                $result[$role] = $relatedUsers;
                $totalUsers += $relatedUsers->count();
            }
        }
        return response()->json([
            'total_users' => $totalUsers,
            'data' => $result
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Failed to retrieve users.',
            'error' => $e->getMessage()
        ], 500);
    }
}
public function updatePassword(Request $request)
{
    try {
        $request->validate([
            'user_id' => 'required|exists:user,id',
            'password' => 'required|string|min:6',
        ]);

        $user = user::find($request->user_id);
        $user->password = $request->password; 
        $user->save();

        return response()->json([
            'status' => true,
            'message' => 'Password updated successfully.'
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => false,
            'message' => 'Validation error',
            'errors' => $e->errors(),
        ], 422);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'An error occurred while updating password.',
            'error' => $e->getMessage()
        ], 500);
    }
}
public function updateEmail(Request $request)
{
    try {
        $request->validate([
            'user_id' => 'required|exists:user,id',
            'email' => 'required|email|unique:user,email,' . $request->user_id,
        ]);

        $user = user::find($request->user_id);
        $user->email = $request->email;
        $user->save();

        return response()->json([
            'status' => true,
            'message' => 'Email updated successfully.'
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => false,
            'message' => 'Validation error',
            'errors' => $e->errors(),
        ], 422);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'An error occurred while updating email.',
            'error' => $e->getMessage()
        ], 500);
    }
}

}
