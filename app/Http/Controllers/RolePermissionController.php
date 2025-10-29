<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RolePermissionController extends Controller
{
   // Liste des rôles
   public function indexRoles()
    {
        try {
            $actualuser = Auth::user();
            if (!$actualuser) {
                return $this->errorResponse("Utilisateur non authentifié.", 401);
            }

            $ese = $this->getEse($actualuser->id);
            if (!$ese) {
                return $this->errorResponse("Entreprise non trouvée pour cet utilisateur.", 404);
            }

            $roles = Role::select('id', 'title', 'description', 'enterprise_id')
                ->where('enterprise_id', $ese->id)
                ->orderBy('title')
                ->get();

            $roles = $roles->map(fn($role) => $this->show($role));

            return $this->successResponse("success", $roles);

        } catch (\Throwable $th) {
            return $this->errorResponse("Erreur interne du serveur : " . $th->getMessage(), 500);
        }
    }


    //Store Role
   public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $actualuser = Auth::user();
        if (!$actualuser) {
            return $this->errorResponse("Utilisateur non authentifié.", 401);
        }

        $ese = $this->getEse($actualuser->id);
        if (!$ese) {
            return $this->errorResponse("Entreprise non trouvée pour cet utilisateur.", 404);
        }

        DB::beginTransaction();
        try {
            $existingRole = Role::where('enterprise_id', $ese->id)
                ->whereRaw('LOWER(name) = ?', [strtolower($request->title)])
                ->first();

            if ($existingRole) {
                return $this->errorResponse(
                    "Un rôle portant ce nom existe déjà dans votre base.",
                    409
                );
            }

            $role = Role::create([
                'title'         => $request->title,
                'name'          => strtolower($request->title),
                'description'   => $request->description,
                'user_id'       => $actualuser->id,
                'enterprise_id' => $ese->id,
                'guard_name'    => 'web',
            ]);

            $permissions = $request->permissions;

            if (!empty($permissions)) {
                $existingPermissions = \Spatie\Permission\Models\Permission::whereIn('name', $permissions)
                    ->pluck('name')
                    ->toArray();

                if (count($existingPermissions) !== count($permissions)) {
                    throw new \Exception("Certaines permissions n'existent pas ou sont invalides.");
                }

                $role->syncPermissions($existingPermissions);
            }

            DB::commit();

            return $this->successResponse('success.',$this->show($role));

        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->errorResponse("Erreur interne du serveur : " . $th->getMessage(), 500);
        }
    }

    public function show(Role $role)
    {
        $actualuser = Auth::user();
        if (!$actualuser) {
            return $this->errorResponse("Utilisateur non authentifié.", 401);
        }

        $ese = $this->getEse($actualuser->id);
        if (!$ese) {
            return $this->errorResponse("Entreprise non trouvée pour cet utilisateur.", 404);
        }

        if ($role->enterprise_id !== $ese->id) {
            return $this->errorResponse("Accès non autorisé à ce rôle.", 403);
        }

        $role->load('permissions');

        $permissions = $role->permissions->pluck('name')->toArray();
        $grouped = [];

        foreach ($permissions as $permission) {
            if (strpos($permission, '.') !== false) {
                [$module, $action] = explode('.', $permission);
                $grouped[$module][] = $permission;
            } else {
                $grouped['autres'][] = $permission;
            }
        }

        $data = [
            'id'          => $role->id,
            'title'       => $role->title,
            'description' => $role->description,
            'permissions' => $grouped,
        ];

        return $data;
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

    public function deleteRole(Request $request)
    {
        $request->validate([
            'role_id' => 'required|integer|exists:roles,id',
        ]);

        $actualuser = Auth::user();
        if (!$actualuser) {
            return $this->errorResponse("Utilisateur non authentifié.", 401);
        }

        $ese = $this->getEse($actualuser->id);
        if (!$ese) {
            return $this->errorResponse("Entreprise non trouvée pour cet utilisateur.", 404);
        }

        DB::beginTransaction();
        try {
            $role = Role::where('id', $request->role_id)
                        ->where('enterprise_id', $ese->id)
                        ->first();

            if (!$role) {
                return $this->errorResponse("Rôle introuvable ou non autorisé à être supprimé.", 404);
            }
            $role->syncPermissions([]);

            $role->delete();

            DB::commit();

            return $this->successResponse("success",$request->role_id);

        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->errorResponse("Erreur interne du serveur : " . $th->getMessage(), 500);
        }
    }
}
