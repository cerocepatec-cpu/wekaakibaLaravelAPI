<?php
// app/Http/Controllers/PermissionController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function assignRole(Request $request)
    {
        $user = User::findOrFail($request->user_id);
        $role = Role::findByName($request->role);

        $user->assignRole($role);
        return response()->json(['message' => 'Rôle attribué avec succès']);
    }

    public function givePermission(Request $request)
    {
        $user = User::findOrFail($request->user_id);
        $user->givePermissionTo($request->permissions); // array ou string

        return response()->json(['message' => 'Permission(s) octroyée(s) avec succès']);
    }

    public function checkPermission(Request $request)
    {
        $user = User::findOrFail($request->user_id);
        $permission = $request->permission;

        return response()->json([
            'has_permission' => $user->can($permission),
        ]);
    }
}
