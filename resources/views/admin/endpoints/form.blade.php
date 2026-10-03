<x-layouts.dashboard :title="$endpoint ? 'Edit endpoint' : 'New endpoint'">
    <livewire:admin.endpoint-form :endpoint="$endpoint" />

</x-layouts.dashboard>
