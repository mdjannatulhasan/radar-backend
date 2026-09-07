<?php

declare(strict_types=1);

namespace SmsCore\Models;

use Illuminate\Database\Eloquent\Model;
use SmsCore\Concerns\BelongsToSchool;

class Role extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled_features' => 'array',
            'is_admin' => 'boolean',
        ];
    }

    /** Feature ids are namespaced by product: "radar.marks.view". */
    public function hasFeature(string $featureId): bool
    {
        return $this->is_admin || in_array($featureId, $this->enabled_features ?? [], true);
    }
}
