<?php

namespace App\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class QuestFilter
{
    protected $request;
    protected $builder;

    protected array $filters = [
        'name',
        'zone',
        'language',
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

        return $this->builder;
    }

    protected function name($value)
    {
        // Script names carry npc_types spellings: '_' for spaces, '-' where the
        // name has a backtick or apostrophe the filesystem cannot hold.
        $script = str_replace([' ', '`', "'"], ['_', '-', '-'], trim($value));

        $this->builder->where(function ($query) use ($value, $script) {
            $query->where('npc_name', 'like', "%{$script}%")
                ->orWhere('file_name', 'like', "%{$value}%");
        });
    }

    protected function zone($value)
    {
        $this->builder->where('zone', $value);
    }

    protected function language($value)
    {
        if (in_array($value, ['lua', 'pl'], true)) {
            $this->builder->where('language', $value);
        }
    }
}
