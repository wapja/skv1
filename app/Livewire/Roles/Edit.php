<?php

namespace App\Livewire\Roles;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Permission;

class Edit extends Component
{
    public Role $role;

    public string $name = '';

    public array $selectedPermissions = [];

    public function mount(Role $role): void
    {
        $this->authorize('update', $role);

        $this->role = $role;
        $this->name = $role->name;
        $this->selectedPermissions = $role->permissions->pluck('id')->all();
    }

    public function save(): mixed
    {
        $this->authorize('update', $this->role);

        $this->validate([
            'name' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('roles')
                    ->where(fn ($q) => $q
                        ->where('guard_name', 'web')
                        ->where('team_id', $this->role->team_id))
                    ->ignore($this->role->id),
                Rule::notIn(['super_admin', 'organisation_admin', 'member']),
                function ($attribute, $value, $fail): void {
                    $clash = Role::query()
                        ->whereNull('team_id')
                        ->where('name', $value)
                        ->where('guard_name', 'web')
                        ->exists();
                    if ($clash) {
                        $fail(__('Deze naam is gereserveerd voor een sjabloonrol.'));
                    }
                },
            ],
            'selectedPermissions' => 'array',
            'selectedPermissions.*' => 'integer|exists:permissions,id',
        ]);

        DB::transaction(function () {
            $this->role->update(['name' => $this->name]);

            // selectedPermissions arrive as string-cast ints from Livewire's wire-properties; resolve to names before syncing.
            $perms = Permission::whereIn('id', $this->selectedPermissions)->pluck('name');
            $this->role->syncPermissions($perms);
        });

        session()->flash('status', __('Rol bijgewerkt.'));

        return redirect()->route('roles.index');
    }

    #[Layout('components.layouts.app')]
    #[Title('Rol bewerken')]
    public function render()
    {
        return view('livewire.roles.edit', [
            'permissions' => Permission::orderBy('name')->get(),
        ]);
    }
}
