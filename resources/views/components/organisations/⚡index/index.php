<?php

use App\Models\Organisation;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new class extends Component
{
    public function delete(int $organisationId): void
    {
        $org = Organisation::findOrFail($organisationId);
        $this->authorize('delete', $org);
        $org->delete();
        session()->flash('status', __('Organisatie verwijderd.'));
    }

    #[Layout('components.layouts.app')]
    #[Title('Organisaties')]
    public function render()
    {
        $this->authorize('viewAny', Organisation::class);

        return $this->view([
            'organisations' => Organisation::orderBy('name')->get(),
        ]);
    }
};
