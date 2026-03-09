<?php

namespace Dedoc\Scramble\Support\TypeToSchemaExtensions;

use BackedEnum;
use Carbon\Carbon;
use DateTimeInterface;
use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Infer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\ClassBasedReference;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType as OpenApiArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType as OpenApiBooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType as OpenApiIntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType as OpenApiNumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType as OpenApiStringType;
use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\Generator\Types\UnknownType as OpenApiUnknownType;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class LaravelDataTypeToSchema extends TypeToSchemaExtension
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
            && $type->isInstanceOf(Data::class);
    }

    public function toSchema(Type $type): OpenApiType
    {
        /** @var ObjectType $type */
        return self::buildSchemaFromDataClass($type->name, $this->openApiTransformer, $this->components);
    }

    public function toResponse(Type $type): Response
    {
        /** @var ObjectType $type */
        return Response::make(200)
            ->setDescription('`'.$this->openApiContext->references->schemas->uniqueName($type->name).'`')
            ->setContent(
                'application/json',
                Schema::fromType($this->openApiTransformer->transform($type)),
            );
    }

    public function reference(ObjectType $type): Reference
    {
        return ClassBasedReference::create('schemas', $type->name, $this->components);
    }

    /**
     * Build OpenAPI schema from a Data class using reflection.
     */
    public static function buildSchemaFromDataClass(
        string $className,
        ?TypeTransformer $transformer = null,
        ?Components $components = null,
    ): OpenApiObjectType {
        $reflection = new ReflectionClass($className);
        $schema = new OpenApiObjectType;
        $required = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $propertyName = $property->getName();
            $propertyType = $property->getType();

            $openApiType = self::resolvePropertyType($propertyType, $property, $transformer, $components);
            $schema->addProperty($propertyName, $openApiType);

            if ($propertyType && ! $propertyType->allowsNull() && ! $property->hasDefaultValue()) {
                $required[] = $propertyName;
            }
        }

        if ($required) {
            $schema->setRequired($required);
        }

        return $schema;
    }

    private static function resolvePropertyType(
        ?\ReflectionType $type,
        ReflectionProperty $property,
        ?TypeTransformer $transformer,
        ?Components $components,
    ): OpenApiType {
        if ($type === null) {
            return new OpenApiUnknownType;
        }

        if ($type instanceof ReflectionUnionType) {
            return self::resolveUnionType($type, $property, $transformer, $components);
        }

        if (! $type instanceof ReflectionNamedType) {
            return new OpenApiUnknownType;
        }

        $openApiType = self::resolveNamedType($type, $property, $transformer, $components);

        if ($type->allowsNull() && ! ($openApiType instanceof OpenApiUnknownType)) {
            $openApiType->nullable(true);
        }

        return $openApiType;
    }

    private static function resolveUnionType(
        ReflectionUnionType $type,
        ReflectionProperty $property,
        ?TypeTransformer $transformer,
        ?Components $components,
    ): OpenApiType {
        $types = $type->getTypes();

        $nonNullTypes = array_filter(
            $types,
            fn (\ReflectionType $t) => ! ($t instanceof ReflectionNamedType && $t->getName() === 'null')
        );

        $isNullable = count($nonNullTypes) < count($types);

        if (count($nonNullTypes) === 1) {
            $resolved = self::resolveNamedType(reset($nonNullTypes), $property, $transformer, $components);

            if ($isNullable) {
                $resolved->nullable(true);
            }

            return $resolved;
        }

        return new OpenApiUnknownType;
    }

    private static function resolveNamedType(
        ReflectionNamedType $type,
        ReflectionProperty $property,
        ?TypeTransformer $transformer,
        ?Components $components,
    ): OpenApiType {
        $typeName = $type->getName();

        return match ($typeName) {
            'string' => new OpenApiStringType,
            'int' => new OpenApiIntegerType,
            'float' => new OpenApiNumberType,
            'bool' => new OpenApiBooleanType,
            'array' => self::resolveArrayType($property, $transformer, $components),
            'mixed' => new OpenApiUnknownType,
            default => self::resolveClassType($typeName, $property, $transformer, $components),
        };
    }

    private static function resolveClassType(
        string $className,
        ReflectionProperty $property,
        ?TypeTransformer $transformer,
        ?Components $components,
    ): OpenApiType {
        if (! class_exists($className) && ! interface_exists($className) && ! enum_exists($className)) {
            return new OpenApiUnknownType;
        }

        // File uploads
        if ($className === UploadedFile::class || is_subclass_of($className, UploadedFile::class)) {
            return (new OpenApiStringType)->format('binary')->contentMediaType('application/octet-stream');
        }

        // Enums
        if (is_subclass_of($className, BackedEnum::class)) {
            $cases = $className::cases();
            $values = array_map(fn ($case) => $case->value, $cases);
            $backingType = (new ReflectionClass($className))
                ->getMethod('from')
                ->getParameters()[0]
                ->getType()
                ->getName();

            $openApiType = $backingType === 'int' ? new OpenApiIntegerType : new OpenApiStringType;
            $openApiType->enum($values);

            return $openApiType;
        }

        // Nested Data classes
        if (is_subclass_of($className, Data::class)) {
            if ($transformer && $components) {
                $ref = ClassBasedReference::create('schemas', $className, $components);

                if (! $components->hasSchema($ref->fullName)) {
                    $schema = self::buildSchemaFromDataClass($className, $transformer, $components);
                    $components->addSchema($ref->fullName, Schema::fromType($schema));
                }

                return $ref;
            }

            return self::buildSchemaFromDataClass($className, $transformer, $components);
        }

        // Date/time types
        if (is_subclass_of($className, DateTimeInterface::class) || $className === Carbon::class) {
            return (new OpenApiStringType)->format('date-time');
        }

        // Laravel/Spatie collections
        if (is_subclass_of($className, Collection::class) || is_subclass_of($className, DataCollection::class)) {
            return self::resolveArrayType($property, $transformer, $components);
        }

        return new OpenApiObjectType;
    }

    private static function resolveArrayType(
        ReflectionProperty $property,
        ?TypeTransformer $transformer,
        ?Components $components,
    ): OpenApiType {
        $arrayType = new OpenApiArrayType;

        $docComment = $property->getDocComment();

        if ($docComment) {
            if (preg_match('/@var\s+(?:array<([^>]+)>|([^\s[]+)\[\]|DataCollection<([^>]+)>|Collection<([^>]+)>)/', $docComment, $matches)) {
                $itemClassName = $matches[1] ?: $matches[2] ?: $matches[3] ?: $matches[4] ?: null;

                if ($itemClassName) {
                    $itemClassName = self::resolveClassName($itemClassName, $property);

                    if ($itemClassName && is_subclass_of($itemClassName, Data::class)) {
                        if ($transformer && $components) {
                            $ref = ClassBasedReference::create('schemas', $itemClassName, $components);

                            if (! $components->hasSchema($ref->fullName)) {
                                $schema = self::buildSchemaFromDataClass($itemClassName, $transformer, $components);
                                $components->addSchema($ref->fullName, Schema::fromType($schema));
                            }

                            $arrayType->setItems($ref);

                            return $arrayType;
                        }

                        $arrayType->setItems(
                            self::buildSchemaFromDataClass($itemClassName, $transformer, $components)
                        );

                        return $arrayType;
                    }
                }
            }
        }

        return $arrayType;
    }

    private static function resolveClassName(string $shortName, ReflectionProperty $property): ?string
    {
        if (class_exists($shortName)) {
            return $shortName;
        }

        $declaringClass = $property->getDeclaringClass();
        $namespace = $declaringClass->getNamespaceName();
        $fullName = $namespace.'\\'.$shortName;

        if (class_exists($fullName)) {
            return $fullName;
        }

        $fileName = $declaringClass->getFileName();

        if ($fileName && file_exists($fileName)) {
            $contents = file_get_contents($fileName);

            if (preg_match('/use\s+([^\s;]+\\\\'.preg_quote($shortName, '/').')\s*;/', $contents, $matches)) {
                if (class_exists($matches[1])) {
                    return $matches[1];
                }
            }
        }

        return null;
    }
}
