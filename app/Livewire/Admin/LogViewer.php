<?php

namespace App\Livewire\Admin;

use App\Services\Logging\LogTailer;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class LogViewer extends Component
{
    public int $limit = 50;

    public function render(): View
    {
        return view('livewire.admin.log-viewer', [
            'events' => app(LogTailer::class)->recent($this->limit),
        ]);
    }
}
