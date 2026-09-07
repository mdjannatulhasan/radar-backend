<?php

declare(strict_types=1);

namespace SmsCore\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains queries to the active school. Tenant isolation is already handled
 * structurally by the Postgres search_path — this scope is the second, finer
 * axis: which CAMPUS inside a multi-campus tenant.
 *
 * The active school is set once per request by the tenancy middleware chain
 * (or defaults to the tenant's only school). When it is unset, the scope is a
 * no-op so console commands and seeders see everything.
 */
class SchoolScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $schoolId = app()->bound('sms.school_id') ? app('sms.school_id') : null;

        if ($schoolId !== null) {
            $builder->where($model->getTable().'.school_id', $schoolId);
        }
    }
}
