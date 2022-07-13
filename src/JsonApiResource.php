<?php

declare(strict_types=1);

namespace TiMacDonald\JsonApi;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use stdClass;
use TiMacDonald\JsonApi\Contracts\Flushable;
use TiMacDonald\JsonApi\Support\Cache;
use function property_exists;

abstract class JsonApiResource extends JsonResource implements Flushable
{
    use Concerns\Attributes;
    use Concerns\Caching;
    use Concerns\Identification;
    use Concerns\Implementation;
    use Concerns\Links;
    use Concerns\Meta;
    use Concerns\Relationships;

    /**
     * @var array<Closure(ResourceIdentifier): void>
     */
    private array $resourceIdentifierCallbacks = [];

    /**
     * @var array<Closure(RelationshipLink): void>
     */
    private array $relationshipLinkCallbacks = [];

    /**
     * @see https://github.com/timacdonald/json-api#customising-the-resource-id
     * @see https://jsonapi.org/format/#document-resource-object-identification
     */
    public static function resolveIdUsing(Closure $resolver): void
    {
        self::$idResolver = $resolver;
    }

    /**
     * @see https://github.com/timacdonald/json-api#customising-the-resource-type
     * @see https://jsonapi.org/format/#document-resource-object-identification
     */
    public static function resolveTypeUsing(Closure $resolver): void
    {
        self::$typeResolver = $resolver;
    }

    /**
     * TODO see local-docs
     * @see https://jsonapi.org/format/#document-jsonapi-object
     */
    public static function resolveServerImplementationUsing(Closure $resolver): void
    {
        self::$serverImplementationResolver = $resolver;
    }

    /**
     * @see https://github.com/timacdonald/json-api#minimal-resource-attributes
     */
    public static function minimalAttributes(?Closure $callback = null): void
    {
        self::$minimalAttributes = true;

        if ($callback === null) {
            return;
        }

        try {
            $callback();
        } finally {
            self::$minimalAttributes = false;
        }
    }

    /**
     * @see https://github.com/timacdonald/json-api#resource-attributes
     * @see https://jsonapi.org/format/#document-resource-object-attributes
     * @return array<string, mixed>
     */
    protected function toAttributes(Request $request): array
    {
        return [
            // 'name' => $this->name,
            //
            // or with lazy evaluation...
            //
            // 'address' => fn () => new Address($this->address),
        ];
    }

    /**
     * @see https://github.com/timacdonald/json-api#resource-relationships
     * @see https://jsonapi.org/format/#document-resource-object-relationships
     * @return array<string, Closure>
     */
    protected function toRelationships(Request $request): array
    {
        return [
            // 'posts' => fn () => PostResource::collection($this->posts),
            // 'avatar' => fn () => AvatarResource::make($this->avatar),
        ];
    }

    /**
     * @see https://github.com/timacdonald/json-api#resource-links
     * @see https://jsonapi.org/format/#document-resource-object-links
     * @return array<int|string, Link|string>
     */
    protected function toLinks(Request $request): array
    {
        return [
            // Link::self(route('users.show'), $this->resource),
            // Link::related(/** ... */),
            // 'whatever' => 'Something'
            // 'whateverElse' => new Link('whatever')
        ];
    }

    /**
     * @see https://github.com/timacdonald/json-api#resource-meta
     * @see https://jsonapi.org/format/#document-meta
     * @return array<string, mixed>
     */
    protected function toMeta(Request $request): array
    {
        return [
            // 'resourceDeprecated' => false,
        ];
    }

    /**
     * @see https://github.com/timacdonald/json-api#customising-the-resource-id
     * @see https://jsonapi.org/format/#document-resource-object-identification
     *
     * @param Request $request
     * @return string
     */
    protected function toId($request)
    {
        return self::idResolver()($this->resource, $request);
    }

    /**
     * @see https://github.com/timacdonald/json-api#customising-the-resource-type
     * @see https://jsonapi.org/format/#document-resource-object-identification
     *
     * @param Request $request
     * @return string
     */
    protected function toType($request)
    {
        return self::typeResolver()($this->resource, $request);
    }

    /**
     * TODO: @see docs-link
     * TODO: naming is inconsistent: resource link vs relationship link
     * @see https://jsonapi.org/format/#document-resource-object-linkage
     */
    public function toResourceLink(Request $request): RelationshipLink
    {
        if ($this->resource === null) {
            return new RelationshipLink(null);
        }

        return new RelationshipLink($this->resolveResourceIdentifier($request));
    }

    /**
     * @internal
     */
    public function resolveRelationshipLink(Request $request): RelationshipLink
    {
        return tap($this->toResourceLink($request), function (RelationshipLink $link) {
            foreach ($this->relationshipLinkCallbacks as $callback) {
                $callback($link);
            }
        });
    }

    public function withRelationshipLink(Closure $callback): self
    {
        $this->relationshipLinkCallbacks[] = $callback;

        return $this;
    }

    public function toResourceIdentifier(Request $request): ResourceIdentifier
    {
        return new ResourceIdentifier($this->resolveId($request), $this->resolveType($request));
    }

    /**
     * @internal
     */
    public function resolveResourceIdentifier(Request $request): ResourceIdentifier
    {
        return tap($this->toResourceIdentifier($request), function (ResourceIdentifier $identifier) {
            foreach ($this->resourceIdentifierCallbacks as $callback) {
                $callback($identifier);
            }
        });
    }

    public function withResourceIdentifier(Closure $callback): self
    {
        $this->resourceIdentifierCallbacks[] = $callback;

        return $this;
    }

    /**
     * @param Request $request
     * @return array{id: string, type: string, attributes: stdClass, relationships: stdClass, meta: stdClass, links: stdClass}
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->resolveId($request),
            'type' => $this->resolveType($request),
            'attributes' => (object) $this->requestedAttributes($request)->all(),
            'relationships' => (object) $this->requestedRelationshipsAsIdentifiers($request)->all(),
            'meta' => (object) array_merge($this->toMeta($request), $this->meta),
            'links' => (object) $this->parseLinks(array_merge($this->toLinks($request), $this->links)),
        ];
    }

    /**
     * @param Request $request
     * @return array{included: Collection, jsonapi: JsonApiServerImplementation}
     */
    public function with($request): array
    {
        return [
            'included' => $this->included($request)
                ->uniqueStrict(fn (JsonApiResource $resource): string => $resource->toUniqueResourceIdentifier($request)),
            'jsonapi' => self::serverImplementationResolver()($request),
        ];
    }

    /**
     * @param mixed $resource
     * @return JsonApiResourceCollection<mixed>
     */
    public static function collection($resource): JsonApiResourceCollection
    {
        return tap($this->newCollection($resource), function (JsonApiResourceCollection $collection): void {
            if (property_exists(static::class, 'preserveKeys')) {
                /** @phpstan-ignore-next-line */
                $collection->preserveKeys = (new static([]))->preserveKeys === true;
            }
        });
    }

    /**
     * @param mixed $resource
     * @return JsonApiResourceCollection<mixed>
     */
    protected function newCollection($resource)
    {
        return new JsonApiResourceCollection($resource, static::class);
    }

    /**
     * @param Request $request
     */
    public function toResponse($request): JsonResponse
    {
        return tap(parent::toResponse($request)->header('Content-type', 'application/vnd.api+json'), fn () => Cache::flush($this));
    }
}
