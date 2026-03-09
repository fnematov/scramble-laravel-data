<?php

namespace Dedoc\Scramble\Support\LaravelData;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\LaravelData\Data;

/**
 * Paginated response wrapper for Laravel Data classes.
 *
 * Returns a standardized pagination format:
 * { items, totalCount, currentPage, perPage, hasMorePages }
 *
 * @template T of Data
 */
class DataPaginatedResponse implements Responsable
{
    /**
     * @param  class-string<T>  $dataClass
     */
    public function __construct(
        protected string $dataClass,
        protected LengthAwarePaginator $paginator,
    ) {}

    /**
     * @param  class-string<T>  $dataClass
     */
    public static function from(string $dataClass, LengthAwarePaginator $paginator): static
    {
        return new static($dataClass, $paginator);
    }

    public function toResponse($request): JsonResponse
    {
        return response()->json($this->toArray());
    }

    public function toArray(): array
    {
        return [
            'items' => $this->dataClass::collect($this->paginator->items()),
            'totalCount' => $this->paginator->total(),
            'currentPage' => $this->paginator->currentPage(),
            'perPage' => $this->paginator->perPage(),
            'hasMorePages' => $this->paginator->hasMorePages(),
        ];
    }

    public function getDataClass(): string
    {
        return $this->dataClass;
    }
}
