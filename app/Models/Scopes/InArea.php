<?php

namespace App\Models\Scopes;

use App\Support\Area;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Limits a model to the signed-in coordinator's LGA or ward (see Area).
 *
 *  - places: the table has lga and ward columns (polling units, volunteers)
 *  - code: the table has polling_unit_code (results, presences, materials)
 *  - agent: agents assigned to a PU in the area, and agents with no PU who
 *    reported from one (they type the PU on each report)
 *  - incident: polling_unit_code, or lga / ward for public reports without a PU
 *  - lga: only an lga column (coordinators: their LGA's)
 */
class InArea implements Scope
{
    public function __construct(private string $mode) {}

    public function apply(Builder $builder, Model $model): void
    {
        if (! Area::restricted()) {
            return;
        }

        $table = $model->getTable();

        match ($this->mode) {
            'places' => Area::onPlaceColumns($builder, "{$table}.lga", "{$table}.ward"),
            'code' => Area::onCodes($builder, "{$table}.polling_unit_code"),
            'agent' => $builder->where(function (Builder $query) use ($table) {
                $query->where(fn (Builder $q) => Area::onCodes($q, "{$table}.polling_unit_code"))
                    ->orWhere(fn (Builder $q) => $q->whereNull("{$table}.polling_unit_code")->whereIn("{$table}.id", self::reportedInArea()));
            }),
            'incident' => $builder->where(function (Builder $query) use ($table) {
                $query->where(fn (Builder $q) => Area::onCodes($q, "{$table}.polling_unit_code"))
                    ->orWhere(function (Builder $q) use ($table) {
                        $q->whereNull("{$table}.polling_unit_code");
                        Area::onPlaceColumns($q, "{$table}.lga", "{$table}.ward");
                    });
            }),
            'lga' => Area::unassigned() ? $builder->whereRaw('1 = 0') : $builder->where("{$table}.lga", Area::lga()),
        };
    }

    /**
     * Ids of agents with a check-in, result, materials report or incident at a PU in the area.
     */
    private static function reportedInArea(): QueryBuilder
    {
        $query = null;
        foreach (['presences', 'results', 'material_reports', 'incidents'] as $records) {
            $ids = DB::table($records)->select('agent_id')->whereNotNull('agent_id')->whereIn('polling_unit_code', Area::codes());
            $query = $query ? $query->union($ids) : $ids;
        }

        return $query;
    }
}
