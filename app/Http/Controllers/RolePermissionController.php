<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;

class RolePermissionController extends Controller
{
    // Liste des rôles
    public function indexRoles()
    {
        return Role::all();
    }

    // Liste des permissions
    public function indexPermissions()
    {
        return Permission::all();
    }

    // Liste des utilisateurs
    public function indexUsers()
    {
        return User::all();
    }

    // Assigner un rôle à un utilisateur
    public function assignRole(Request $request, User $user)
    {
        $request->validate([
            'role_id' => 'required|exists:roles,id',
        ]);

        $role = Role::findOrFail($request->role_id);
        $user->syncRoles($role);

        return response()->json([
            'message' => 'Rôle attribué avec succès',
            'user' => $user->load('roles')
        ]);
    }

    // Assigner des permissions à un utilisateur
    public function assignPermissions(Request $request, User $user)
    {
        $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,name'
        ]);

        $user->syncPermissions($request->permissions);

        return response()->json([
            'message' => 'Permissions attribuées avec succès',
            'user' => $user->load('permissions')
        ]);
    }
}
