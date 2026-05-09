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
    public ?Role $role = null;

    public string $name = '';

    public array $selectedPermissions = [];

    public function mount(?Role $role = null): void
    {
        if ($role && $role->exists) {
            $this->authorize('update', $role);

            $this->role = $role;
            $this->name = $role->name;
            $this->selectedPermissions = $role->permissions->pluck('id')->all();
        } else {
            $this->authorize('create', Role::class);
        }
    }

    public function save(): mixed
    {
        if ($this->role) {
            $this->authorize('update', $this->role);
        } else {
            $this->authorize('create', Role::class);
        }

        $this->validate([
            'name' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('roles')
                    ->where(fn ($q) => $q
                        ->where('guard_name', 'web')
                        ->where('team_id', $this->role?->team_id ?? tenant()?->id))
                    ->ignore($this->role?->id),
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

        $wasCreate = $this->role === null;

        DB::transaction(function () {
            if ($this->role) {
                $this->role->update(['name' => $this->name]);
            } else {
                $this->role = Role::create([
                    'name' => $this->name,
                    'guard_name' => 'web',
                    'team_id' => tenant()?->id,
                ]);
            }

            // selectedPermissions arrive as string-cast ints from Livewire's wire-properties; resolve to names before syncing.
            $perms = Permission::whereIn('id', $this->selectedPermissions)->pluck('name');
            $this->role->syncPermissions($perms);
        });

        session()->flash('status', __($wasCreate ? 'Rol aangemaakt.' : 'Rol bijgewerkt.'));

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
