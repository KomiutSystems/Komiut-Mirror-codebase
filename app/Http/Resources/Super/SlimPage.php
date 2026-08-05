<?php

declare(strict_types=1);

namespace App\Http\Resources\Super;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The envelope for every NEW /super list route (pulse/users/saccos/vehicles/
 * payments/tills/loyalty/directory/drivers/audit/fraud/partners/…):
 *
 *   { "data": [...], "total": 0, "per_page": 25, "current_page": 1, "last_page": 1 }
 *
 * Deliberately NOT Laravel's default paginator shape (which also carries
 * first_page_url/links/etc) and NOT the older {"message":{...}} envelope used
 * by the notification/audit/log feeds built earlier — this is the contract the
 * frontend's super-api.ts expects for every route added from here on.
 *
 * Usage: SlimPage::make($paginator, fn ($row) => [...]) or pass a Resource
 * class name as the mapper.
 */
class SlimPage extends JsonResource
{
    /** @var callable|null */
    private $mapper;

    public function __construct($resource, ?callable $mapper = null)
    {
        parent::__construct($resource);
        $this->mapper = $mapper;
    }

    /** Named constructor — avoids JsonResource::make()'s own (...$parameters) forwarding. */
    public static function of($paginator, ?callable $mapper = null): self
    {
        return new self($paginator, $mapper);
    }

    public function toArray($request): array
    {
        /** @var LengthAwarePaginator $paginator */
        $paginator = $this->resource;
        $items = collect($paginator->items());

        return [
            'data' => $this->mapper !== null ? $items->map($this->mapper)->values() : $items->values(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
