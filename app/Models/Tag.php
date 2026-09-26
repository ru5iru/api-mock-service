<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

final class Tag extends Model
{
    protected $fillable = ['name', 'normalized_name'];

    protected static function booted(): void
    {
        self::saving(function (Tag $tag): void {
            $tag->name = trim($tag->name);
            $tag->normalized_name = Str::lower($tag->name);
        });
    }

    /** @return BelongsToMany<MockEndpoint, $this> */
    public function endpoints(): BelongsToMany
    {
        return $this->belongsToMany(MockEndpoint::class, 'endpoint_tags', 'tag_id', 'endpoint_id');
    }
}
