<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/** A bounded, model-specific replacement for the unsupported search-string package. */
trait SearchString
{
    public function scopeUsingSearchString(Builder $query, mixed $expression = null): Builder
    {
        if ($expression === null || $expression === '') {
            return $query;
        }
        if (!is_string($expression) || strlen($expression) > 512 || !mb_check_encoding($expression, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f]/', $expression) !== 0) {
            self::invalidSearch();
        }

        $columns = [];
        foreach ($this->searchStringColumns as $key => $config) {
            if (is_int($key)) {
                $columns[$config] = [];
            } else {
                $columns[$key] = is_array($config) ? $config : ['alias' => $config];
            }
        }
        $count = preg_match_all('/(?:[^\s"]+|"[^"]*")+/u', trim($expression), $matches);
        if ($count === false || $count > 12 || substr_count($expression, '"') % 2 !== 0) {
            self::invalidSearch();
        }
        $terms = $matches[0];

        foreach ($terms as $term) {
            if (!preg_match('/\A([a-z_][a-z0-9_]*):(.*)\z/isuD', $term, $parts)) {
                $word = self::searchValue($term);
                $searchable = array_keys(array_filter($columns, static fn (array $flags): bool => $flags['searchable'] ?? false));
                if (!$searchable) {
                    $query->whereRaw('1 = 0');
                    continue;
                }
                // Character escaping is independent from SQL parameter binding.
                $pattern = '%'.strtr($word, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
                $query->where(function (Builder $group) use ($searchable, $pattern) {
                    foreach ($searchable as $column) {
                        $qualified = $group->getQuery()->getGrammar()->wrap($this->qualifyColumn($column));
                        $group->orWhereRaw("LOWER($qualified) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
                    }
                });
                continue;
            }

            $key = strtolower($parts[1]);
            $value = self::searchValue($parts[2]);
            if ($key === 'sort') {
                $column = $this->resolveSearchColumn(ltrim($value, '-'), $columns);
                $query->orderBy($this->qualifyColumn($column), str_starts_with($value, '-') ? 'desc' : 'asc');
                continue;
            }

            $column = $this->resolveSearchColumn($key, $columns);
            $flags = $columns[$column];
            $qualified = $this->qualifyColumn($column);
            if ($flags['boolean'] ?? false) {
                $boolean = match (strtolower($value)) {
                    'true', '1' => true,
                    'false', '0' => false,
                    default => self::invalidSearch(),
                };
                $query->where($qualified, '=', $boolean);
            } elseif ($flags['date'] ?? false) {
                if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $value, $date) !== 1
                    || !checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
                    self::invalidSearch();
                }
                $query->whereDate($qualified, '=', $value);
            } elseif (in_array($column, ['uid', 'pid', 'id', 'uploader', 'reporter', 'tid', 'tid_skin', 'tid_cape', 'avatar', 'score', 'status', 'permission'], true)) {
                if (!preg_match('/\A-?\d{1,10}\z/D', $value) || (int) $value < -2147483648 || (int) $value > 4294967295) {
                    self::invalidSearch();
                }
                $query->where($qualified, '=', (int) $value);
            } else {
                $query->where($qualified, '=', $value);
            }
        }

        return $query->orderBy($this->getQualifiedKeyName());
    }

    private static function searchValue(string $value): string
    {
        if (preg_match('/\A"([^\"]*)"\z/D', $value, $quoted) === 1) {
            $value = $quoted[1];
        }
        if ($value === '' || str_contains($value, '"') || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            self::invalidSearch();
        }

        return $value;
    }

    private function resolveSearchColumn(string $name, array $columns): string
    {
        if (array_key_exists($name, $columns)) {
            return $name;
        }
        foreach ($columns as $column => $flags) {
            if (isset($flags['alias']) && preg_match($flags['alias'], $name) === 1) {
                return $column;
            }
        }

        self::invalidSearch();
    }

    private static function invalidSearch(): never
    {
        throw ValidationException::withMessages(['q' => 'Invalid search expression.']);
    }
}
