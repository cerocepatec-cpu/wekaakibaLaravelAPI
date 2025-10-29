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
}
