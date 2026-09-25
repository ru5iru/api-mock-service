<?php

namespace App\Services\Environments;

use App\Models\Environment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class EnvironmentContext
{
    private ?Environment $resolved = null;

    public function active(): Environment
    {
        if ($this->resolved !== null && $this->resolved->exists) {
            return $this->resolved;
        }

        $activeId = (int) (DB::table('app_settings')->where('key', 'active_environment_id')->value('value') ?? 0);
        $this->resolved = Environment::query()->find($activeId)
            ?? Environment::query()->where('is_default', true)->first()
            ?? Environment::query()->oldest('id')->firstOrFail();

        return $this->resolved;
    }

    public function activate(Environment $environment): Environment
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => 'active_environment_id'],
            ['value' => (string) $environment->id, 'updated_at' => now(), 'created_at' => now()],
        );
        $this->resolved = $environment;

        return $environment;
    }

    public function makeDefault(Environment $environment): Environment
    {
        return DB::transaction(function () use ($environment): Environment {
            Environment::query()->where('is_default', true)->update(['is_default' => false]);
            $environment->update(['is_default' => true]);

            return $environment->refresh();
        }, 3);
    }

    public function delete(Environment $environment, ?Environment $replacement = null): void
    {
        $active = $this->active();
        if (($environment->is_default || $active->is($environment)) && $replacement === null) {
            throw new InvalidArgumentException('Choose a replacement environment before deleting the active or default environment.');
        }

        DB::transaction(function () use ($environment, $replacement, $active): void {
            if ($replacement !== null && $replacement->is($environment)) {
                throw new InvalidArgumentException('The replacement must be a different environment.');
            }
            if ($environment->is_default && $replacement !== null) {
                $this->makeDefault($replacement);
            }
            if ($active->is($environment) && $replacement !== null) {
                $this->activate($replacement);
            }
            $environment->delete();
            $this->resolved = null;
        }, 3);
    }

    /** @return array<string, string> */
    public function variables(?Environment $environment = null): array
    {
        return ($environment ?? $this->active())->variables()
            ->get()
            ->mapWithKeys(static fn ($variable): array => [$variable->key => (string) $variable->value])
            ->all();
    }
}
