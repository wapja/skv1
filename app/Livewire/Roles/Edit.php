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

    #[Layout('components.layouts.app')]
    #[Title('Rol bewerken')]
    public function render()
    {
        return view('livewire.roles.edit', [
            'permissions' => Permission::orderBy('name')->get(),
        ]);
    }
}
