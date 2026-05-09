<?php

namespace App\Livewire\Roles;

use App\Models\Role;
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

        $this->role->update(['name' => $this->name]);

        $perms = Permission::whereIn('id', $this->selectedPermissions)->pluck('name');
        $this->role->syncPermissions($perms);

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
