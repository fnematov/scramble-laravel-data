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
use Spatie\LaravelData\Attributes\Validation\StringValidationAttribute;
use Spatie\LaravelData\Attributes\Validation\ValidationAttribute;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class LaravelDataTypeToSchema extends TypeToSchemaExtension
{
    /** @var array<string, bool> Track classes currently being built to prevent infinite recursion */
    private static array $buildingClasses = [];

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
        // Prevent infinite recursion for self-referencing Data classes
        if (isset(self::$buildingClasses[$className])) {
            return new OpenApiObjectType;
        }

        self::$buildingClasses[$className] = true;

        try {
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
                $openApiType = self::applyValidationAttributes($property, $openApiType);
                $schema->addProperty($propertyName, $openApiType);

                if ($propertyType && ! $propertyType->allowsNull() && ! $property->hasDefaultValue()) {
                    $required[] = $propertyName;
                }
            }

            if ($required) {
                $schema->setRequired($required);
            }

            return $schema;
        } finally {
            unset(self::$buildingClasses[$className]);
        }
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
            $backingType = (new \ReflectionEnum($className))->getBackingType()?->getName();

            $openApiType = $backingType === 'int' ? new OpenApiIntegerType : new OpenApiStringType;
            $openApiType->enum($values);

            return $openApiType;
        }

        // Nested Data classes
        if (is_subclass_of($className, Data::class)) {
            // Self-referencing: return plain object to avoid infinite nesting
            if (isset(self::$buildingClasses[$className])) {
                return new OpenApiObjectType;
            }

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
        if (is_subclass_of($className, Collection::class) || is_a($className, DataCollection::class, true)) {
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

        if (! $docComment) {
            return $arrayType;
        }

        // Try to extract item type from @var annotation
        $itemType = self::extractArrayItemType($docComment);

        if ($itemType === null) {
            return $arrayType;
        }

        // Shape type: array{key: type, ...}
        if ($shapeSchema = self::parseShapeType($itemType)) {
            $arrayType->setItems($shapeSchema);

            return $arrayType;
        }

        // Class name (Data class)
        $itemClassName = self::resolveClassName($itemType, $property);

        if ($itemClassName && is_subclass_of($itemClassName, Data::class)) {
            // Self-referencing: return plain array to avoid infinite nesting
            if (isset(self::$buildingClasses[$itemClassName])) {
                return $arrayType;
            }

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

        // Enum item type
        if ($itemClassName && is_subclass_of($itemClassName, BackedEnum::class)) {
            $cases = $itemClassName::cases();
            $values = array_map(fn ($case) => $case->value, $cases);
            $backingType = (new \ReflectionEnum($itemClassName))->getBackingType()?->getName();

            $enumType = $backingType === 'int' ? new OpenApiIntegerType : new OpenApiStringType;
            $enumType->enum($values);
            $arrayType->setItems($enumType);

            return $arrayType;
        }

        // Primitive item type
        if ($primitiveType = self::resolvePrimitiveType($itemType)) {
            $arrayType->setItems($primitiveType);
        }

        return $arrayType;
    }

    /**
     * Extract the array item type string from a @var doc comment.
     */
    private static function extractArrayItemType(string $docComment): ?string
    {
        // array<int, ValueType> or array<ValueType>
        if (preg_match('/@var\s+array<(?:[^,>]+,\s*)?(.+)>/', $docComment, $matches)) {
            return trim($matches[1]);
        }

        // ClassName[] or ClassName[]|null
        if (preg_match('/@var\s+([^\s\[|]+)\[\]/', $docComment, $matches)) {
            return $matches[1];
        }

        // DataCollection<ClassName> or Collection<ClassName>
        if (preg_match('/@var\s+(?:DataCollection|Collection)<([^>]+)>/', $docComment, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Parse a PHPDoc shape type like array{key: type, ...} into an OpenAPI ObjectType.
     */
    private static function parseShapeType(string $typeStr): ?OpenApiObjectType
    {
        if (! preg_match('/^array\{(.+)\}$/', $typeStr, $matches)) {
            return null;
        }

        $schema = new OpenApiObjectType;
        $required = [];
        $fields = preg_split('/,\s*/', $matches[1]);

        foreach ($fields as $field) {
            $field = trim($field);

            if (! preg_match('/^(\w+)(\?)?\s*:\s*(.+)$/', $field, $parts)) {
                continue;
            }

            $name = $parts[1];
            $optional = $parts[2] === '?';
            $fieldType = trim($parts[3]);

            $openApiType = self::resolvePrimitiveType($fieldType) ?? new OpenApiUnknownType;

            if ($optional || str_ends_with($fieldType, '|null')) {
                $openApiType->nullable(true);
            }

            $schema->addProperty($name, $openApiType);

            if (! $optional && ! str_ends_with($fieldType, '|null')) {
                $required[] = $name;
            }
        }

        if ($required) {
            $schema->setRequired($required);
        }

        return $schema;
    }

    /**
     * Resolve a primitive type string to an OpenAPI type.
     */
    private static function resolvePrimitiveType(string $typeStr): ?OpenApiType
    {
        $typeStr = str_replace('|null', '', $typeStr);

        return match ($typeStr) {
            'string' => new OpenApiStringType,
            'int', 'integer' => new OpenApiIntegerType,
            'float', 'double', 'number' => new OpenApiNumberType,
            'bool', 'boolean' => new OpenApiBooleanType,
            default => null,
        };
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

    /**
     * Apply Spatie Laravel Data validation attributes to the OpenAPI type.
     */
    private static function applyValidationAttributes(ReflectionProperty $property, OpenApiType $openApiType): OpenApiType
    {
        $attributes = $property->getAttributes(ValidationAttribute::class, \ReflectionAttribute::IS_INSTANCEOF);

        foreach ($attributes as $attribute) {
            try {
                $instance = $attribute->newInstance();
            } catch (\Throwable) {
                continue;
            }

            if (! $instance instanceof StringValidationAttribute) {
                continue;
            }

            $keyword = $instance::keyword();
            $params = $instance->parameters();

            $openApiType = self::applyValidationRule($openApiType, $keyword, $params);
        }

        return $openApiType;
    }

    /**
     * Apply a single validation rule keyword to the OpenAPI type.
     */
    private static function applyValidationRule(OpenApiType $type, string $keyword, array $params): OpenApiType
    {
        return match ($keyword) {
            'email' => $type instanceof OpenApiStringType ? $type->format('email') : $type,
            'url' => $type instanceof OpenApiStringType ? $type->format('uri') : $type,
            'uuid' => $type instanceof OpenApiStringType ? $type->format('uuid') : $type,
            'ulid' => $type instanceof OpenApiStringType ? $type->format('ulid') : $type,
            'ip' => $type instanceof OpenApiStringType ? $type->format('ipv4') : $type,
            'ipv4' => $type instanceof OpenApiStringType ? $type->format('ipv4') : $type,
            'ipv6' => $type instanceof OpenApiStringType ? $type->format('ipv6') : $type,
            'date_format' => self::applyDateFormat($type, $params),
            'min' => self::applyMinConstraint($type, $params),
            'max' => self::applyMaxConstraint($type, $params),
            'size' => self::applySizeConstraint($type, $params),
            'between' => self::applyBetweenConstraint($type, $params),
            'regex' => self::applyRegexPattern($type, $params),
            'nullable' => $type->nullable(true),
            default => $type,
        };
    }

    private static function applyDateFormat(OpenApiType $type, array $params): OpenApiType
    {
        if (! $type instanceof OpenApiStringType || empty($params)) {
            return $type;
        }

        $format = is_array($params[0]) ? ($params[0][0] ?? null) : $params[0];

        return match ($format) {
            'Y-m-d' => $type->format('date'),
            'Y-m-d H:i:s' => $type->format('date-time'),
            default => $type,
        };
    }

    private static function applyMinConstraint(OpenApiType $type, array $params): OpenApiType
    {
        if (empty($params) || ! is_numeric($params[0])) {
            return $type;
        }

        if ($type instanceof OpenApiStringType || $type instanceof OpenApiArrayType || $type instanceof OpenApiNumberType) {
            $type->setMin((float) $params[0]);
        }

        return $type;
    }

    private static function applyMaxConstraint(OpenApiType $type, array $params): OpenApiType
    {
        if (empty($params) || ! is_numeric($params[0])) {
            return $type;
        }

        if ($type instanceof OpenApiStringType || $type instanceof OpenApiArrayType || $type instanceof OpenApiNumberType) {
            $type->setMax((float) $params[0]);
        }

        return $type;
    }

    private static function applySizeConstraint(OpenApiType $type, array $params): OpenApiType
    {
        $type = self::applyMinConstraint($type, $params);

        return self::applyMaxConstraint($type, $params);
    }

    private static function applyBetweenConstraint(OpenApiType $type, array $params): OpenApiType
    {
        if (count($params) < 2) {
            return $type;
        }

        $type = self::applyMinConstraint($type, [$params[0]]);

        return self::applyMaxConstraint($type, [$params[1]]);
    }

    private static function applyRegexPattern(OpenApiType $type, array $params): OpenApiType
    {
        if (empty($params) || ! $type instanceof OpenApiStringType) {
            return $type;
        }

        $pattern = $params[0];

        // Strip PHP regex delimiters (e.g., /^[a-z]+$/i → ^[a-z]+$)
        if (preg_match('#^(.)(.+)\1[a-zA-Z]*$#s', $pattern, $matches)) {
            $pattern = $matches[2];
        }

        $type->pattern($pattern);

        return $type;
    }
}
