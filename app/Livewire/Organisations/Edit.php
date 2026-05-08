<?php

namespace App\Livewire\Organisations;

use App\Models\Organisation;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

class Edit extends Component
{
    public ?Organisation $organisation = null;

    public string $name = '';

    public string $slug = '';

    public string $description = '';

    public function mount(?Organisation $organisation = null): void
    {
        if ($organisation && $organisation->exists) {
            $this->authorize('update', $organisation);
            $this->organisation = $organisation;
            $this->name = $organisation->name;
            $this->slug = $organisation->slug;
            $this->description = (string) $organisation->description;
        } else {
            $this->authorize('create', Organisation::class);
        }
    }

    protected function rules(): array
    {
        $ignoreId = $this->organisation?->id;

        return [
            'name' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('organisations', 'slug')->ignore($ignoreId)],
            'description' => 'nullable|string|max:1000',
        ];
    }

    public function save(): mixed
    {
        if ($this->organisation) {
            $this->authorize('update', $this->organisation);
        } else {
            $this->authorize('create', Organisation::class);
        }

        $validated = $this->validate();

        if ($this->organisation) {
            $this->organisation->update($validated);
        } else {
            Organisation::create($validated);
        }

        session()->flash('status', __('Organisatie opgeslagen.'));

        return redirect()->route('organisations.index');
    }

    #[Layout('components.layouts.app')]
    #[Title('Organisatie bewerken')]
    public function render()
    {
        return view('livewire.organisations.edit');
    }
}
