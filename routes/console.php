<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('mock:about', function (): void {
    $this->info('MockDeck exact-request mock API service');
})->purpose('Display a short description of this application');
