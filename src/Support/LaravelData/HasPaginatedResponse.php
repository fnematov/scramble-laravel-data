<?php

namespace Dedoc\Scramble\Support\LaravelData;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Add this trait to your base Data class to enable paginated responses.
 *
 * Usage:
 *   return UserData::paginated(User::query()->paginate());
 */
trait HasPaginatedResponse
{
    public static function paginated(LengthAwarePaginator $paginator): DataPaginatedResponse
    {
        return DataPaginatedResponse::from(static::class, $paginator);
    }
}
