<?php
// app/Http/Controllers/PermissionController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

   public function givePermissions(Request $request)
    {
        $request->validate([
            'users' => 'required|array|min:1',
            'permissions' => 'required|array|min:1'
        ]);

        $authUser = Auth::user();
        $adminEse = $this->getEse($authUser->id);

        DB::beginTransaction(); // BEGIN TRANSACTION
        try {
            $users = User::whereIn('id', $request->users)
                ->where('status','enabled')
                ->get();

            $success = [];
            $skipped = [];
            $errors = [];

            foreach ($request->users as $userId) {
                $user = $users->firstWhere('id', $userId);

                if (!$user) {
                    $errors[] = "Utilisateur ID $userId introuvable ou désactivé.";
                    continue;
                }

                $userEse = $this->getEse($user->id);
                if (!$userEse || $userEse['id'] != $adminEse['id']) {
                    $errors[] = "Utilisateur {$user->name} n'appartient pas à votre entreprise.";
                    continue;
                }

                $toAssign = array_filter($request->permissions, fn($perm) => !$user->hasPermissionTo($perm));

                if (!empty($toAssign)) {
                    $user->givePermissionTo($toAssign);
                    $success[] = "Permissions attribuées à {$user->name}: " . implode(', ', $toAssign);
                }

                $already = array_filter($request->permissions, fn($perm) => $user->hasPermissionTo($perm));
                foreach ($already as $perm) {
                    $skipped[] = "Permission {$perm} déjà attribuée à {$user->name}.";
                }
            }

            DB::commit(); // COMMIT TRANSACTION

            return response()->json([
                'success' => $success,
                'skipped' => $skipped,
                'errors' => $errors,
                'message' => 'Traitement terminé'
            ]);
        } catch (\Exception $e) {
            DB::rollBack(); // ROLLBACK TRANSACTION en cas d'erreur
            return response()->json(['message' => 'Erreur lors de l’attribution des permissions', 'error' => $e->getMessage()], 500);
        }
    }

    public function checkPermission(Request $request)
    {
        $user = User::findOrFail($request->user_id);
        $permission = $request->permission;

        return response()->json([
            'has_permission' => $user->can($permission),
        ]);
    }

    public function groupedPermissionsWithUser($userId)
    {
        $permissions = Permission::all()->pluck('name')->toArray();
        $user = User::findOrFail($userId);
        $userPermissions = $user->getPermissionNames()->toArray();

        $grouped = [];

        foreach ($permissions as $permission) {
            [$prefix, $action] = explode('.', $permission);
            $grouped[$prefix][] = [
                'name' => $permission,
                'assigned' => in_array($permission, $userPermissions)
            ];
        }

        return response()->json($grouped);
    }

    public function groupedPermissions()
    {
        $permissions = Permission::all()->pluck('name')->toArray();
        $grouped = [];

        foreach ($permissions as $permission) {
            [$prefix, $action] = explode('.', $permission);
            $grouped[$prefix][] = $permission;
        }

        return response()->json($grouped);
    }

    public function getPermissionUsers(Request $request)
    {
        $request->validate([
            'module' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
            'per_page' => 'nullable|integer|min:1'
        ]);

        try {
            $perPage = $request->input('per_page', 10);
            $module = $request->input('module');
            $permissions = $request->input('permissions', []);

            $query = User::query()->with('roles', 'permissions');

            if ($module) {
                // Si un module est fourni, on cherche tous les utilisateurs ayant au moins une permission de ce module
                $query->whereHas('permissions', function($q) use ($module) {
                    $q->where('name', 'like', "$module.%");
                });
            } elseif (!empty($permissions)) {
                // Si des permissions spécifiques sont fournies
                $query->whereHas('permissions', function($q) use ($permissions) {
                    $q->whereIn('name', $permissions);
                });
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vous devez fournir soit un module, soit une liste de permissions.'
                ], 400);
            }

            $users = $query->paginate($perPage);

            // Filtrer les permissions de chaque utilisateur selon le module ou la liste envoyée
            // Récupère les items paginés comme collection
            $usersCollection = collect($users->items())->map(function($user) use ($module, $permissions) {
                if ($module) {
                    $userPermissions = $user->permissions->filter(function($perm) use ($module) {
                        return str_starts_with($perm->name, $module . '.');
                    })->pluck('name');
                } else {
                    $userPermissions = $user->permissions->filter(function($perm) use ($permissions) {
                        return in_array($perm->name, $permissions);
                    })->pluck('name');
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->pluck('name'),
                    'permissions' => $userPermissions
                ];
            });

            return response()->json([
                'status' => 200,
                'message' => 'success',
                'total_users' => $users->total(),
                'data' =>$usersCollection,
                'pagination' => [
                    'total' => $users->total(),
                    'per_page' => $users->perPage(),
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'next_page_url' => $users->nextPageUrl(),
                    'prev_page_url' => $users->previousPageUrl(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors du chargement des utilisateurs : ' . $e->getMessage()
            ], 500);
        }
    }

    public function removeUsersFromPermission(Request $request)
    {
        $validated = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'integer|exists:users,id',
            'module' => 'required|string',
            'permissions' => 'required|array|min:1',
            'permissions.*' => 'string'
        ]);

        try {
            $userIds = $validated['user_ids'];
            $permissions = $validated['permissions'];

            $affectedCount = 0;
            $errors = [];

            foreach ($userIds as $userId) {
                $user = User::find($userId);
                if (!$user) {
                    $errors[] = "Utilisateur ID $userId introuvable.";
                    continue;
                }

                // Retirer les permissions liées au module
                foreach ($permissions as $perm) {
                    if ($user->hasPermissionTo($perm)) {
                        $user->revokePermissionTo($perm);
                        $affectedCount++;
                    }
                }

                // Optionnel : si tu veux retirer un rôle du module aussi
                $roleName = ucfirst($validated['module']);
                if ($user->hasRole($roleName)) {
                    $user->removeRole($roleName);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => "Suppression terminée.",
                'total_users' => count($userIds),
                'affected_permissions' => $affectedCount,
                'errors' => $errors,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

}
