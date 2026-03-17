<?php

namespace Dedoc\Scramble\Support\LaravelData;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Add this trait to your base Data class to enable list and paginated responses.
 *
 * Usage:
 *   return UserData::paginated(User::query()->paginate());
 *   return UserData::list(User::query()->get());
 */
trait HasPaginatedResponse
{
    public static function paginated(LengthAwarePaginator $paginator): DataPaginatedResponse
    {
        return DataPaginatedResponse::from(static::class, $paginator);
    }

    public static function list(iterable $items): DataListResponse
    {
        return DataListResponse::from(static::class, $items);
    }
}
