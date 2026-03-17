<?php

namespace Dedoc\Scramble\Support\LaravelData;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

/**
 * List response wrapper for Laravel Data classes.
 *
 * Returns a standardized format: { items: [...] }
 *
 * @template T of Data
 */
class DataListResponse implements Responsable
{
    /**
     * @param  class-string<T>  $dataClass
     * @param  iterable<mixed>  $items
     */
    public function __construct(
        protected string $dataClass,
        protected iterable $items,
    ) {}

    /**
     * @param  class-string<T>  $dataClass
     * @param  iterable<mixed>  $items
     */
    public static function from(string $dataClass, iterable $items): static
    {
        return new static($dataClass, $items);
    }

    public function toResponse($request): JsonResponse
    {
        return response()->json($this->toArray());
    }

    public function toArray(): array
    {
        $items = $this->items instanceof Collection
            ? $this->items->all()
            : (is_array($this->items) ? $this->items : iterator_to_array($this->items));

        return [
            'items' => $this->dataClass::collect($items),
        ];
    }

    public function getDataClass(): string
    {
        return $this->dataClass;
    }
}
