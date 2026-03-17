<?php

namespace Dedoc\Scramble\Support\TypeToSchemaExtensions;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Infer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType as OpenApiArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\LaravelData\DataListResponse;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\GenericClassStringType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Spatie\LaravelData\Data;

class LaravelDataListTypeToSchema extends TypeToSchemaExtension
{
    protected OpenApiContext $openApiContext;

    public function __construct(
        Infer $infer,
        TypeTransformer $openApiTransformer,
        Components $components,
        OpenApiContext $openApiContext,
    ) {
        parent::__construct($infer, $openApiTransformer, $components);
        $this->openApiContext = $openApiContext;
    }

    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType
            && $type->isInstanceOf(DataListResponse::class);
    }

    public function toSchema(Type $type): OpenApiType
    {
        $itemsSchema = $this->resolveItemsSchema($type);

        return (new OpenApiObjectType)
            ->addProperty('items', (new OpenApiArrayType)->setItems($itemsSchema))
            ->setRequired(['items']);
    }

    public function toResponse(Type $type): Response
    {
        $dataClassName = $this->resolveDataClassName($type);
        $description = $dataClassName
            ? 'List of `'.$this->openApiContext->references->schemas->uniqueName($dataClassName).'`'
            : 'List';

        return Response::make(200)
            ->setDescription($description)
            ->setContent('application/json', Schema::fromType($this->toSchema($type)));
    }

    private function resolveItemsSchema(Type $type): OpenApiType
    {
        $dataClassName = $this->resolveDataClassName($type);

        if (! $dataClassName) {
            return new OpenApiObjectType;
        }

        return $this->openApiTransformer->transform(new ObjectType($dataClassName));
    }

    private function resolveDataClassName(Type $type): ?string
    {
        if ($type instanceof Generic && ! empty($type->templateTypes)) {
            foreach ($type->templateTypes as $templateType) {
                if ($templateType instanceof GenericClassStringType && $templateType->type instanceof ObjectType) {
                    $className = $templateType->type->name;

                    if (class_exists($className) && is_subclass_of($className, Data::class)) {
                        return $className;
                    }
                }

                if ($templateType instanceof ObjectType && is_subclass_of($templateType->name, Data::class)) {
                    return $templateType->name;
                }
            }
        }

        return null;
    }
}