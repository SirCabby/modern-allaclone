<?php

namespace App\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use App\Support\ContentFilter;

class RecipeFilter
{
    protected $request;
    protected $builder;

    protected array $filters = [
        'name',
        'min',
        'max',
        'ts',
    ];

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function apply(Builder $builder): Builder
    {
        $this->builder = $builder;

        foreach ($this->filters as $filter) {
            if (method_exists($this, $filter) && $this->request->filled($filter)) {
                $this->{$filter}($this->request->get($filter));
            }
        }

        $this->applyExpansionFilter();

        return $this->builder;
    }

    protected function name($value)
    {
        $this->builder->where('name', 'like', "%{$value}%");
    }

    protected function min($value)
    {
        $this->builder->where('trivial', '>=', $value);
    }

    protected function max($value)
    {
        $this->builder->where('trivial', '<=', $value);
    }

    protected function ts($value)
    {
        $this->builder->where('tradeskill', $value);
    }

    protected function applyExpansionFilter()
    {
        // The shared gate also understands "all eras" and content flags, which
        // the old inline min/max comparison did not.
        ContentFilter::apply($this->builder);
    }
}
