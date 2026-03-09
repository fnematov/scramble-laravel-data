<?php

namespace Dedoc\Scramble\Support\TypeToSchemaExtensions;

use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\OperationExtensions\ParameterExtractor\ParameterExtractor;
use Dedoc\Scramble\Support\OperationExtensions\RequestBodyExtension;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\ParametersExtractionResult;
use Dedoc\Scramble\Support\RouteInfo;
use ReflectionNamedType;
use ReflectionParameter;
use Spatie\LaravelData\Data;

class LaravelDataParameterExtractor implements ParameterExtractor
{
    /**
     * @param  ParametersExtractionResult[]  $parameterExtractionResults
     * @return ParametersExtractionResult[]
     */
    public function handle(RouteInfo $routeInfo, array $parameterExtractionResults): array
    {
        if (! $dataClassName = $this->getDataClassName($routeInfo)) {
            return $parameterExtractionResults;
        }

        $in = in_array(mb_strtolower($routeInfo->method), RequestBodyExtension::HTTP_METHODS_WITHOUT_REQUEST_BODY)
            ? 'query'
            : 'body';

        $schema = LaravelDataTypeToSchema::buildSchemaFromDataClass($dataClassName);

        $parameters = [];

        foreach ($schema->properties as $name => $property) {
            $parameter = Parameter::make($name, $in)
                ->setSchema(Schema::fromType($property));

            if (in_array($name, $schema->required)) {
                $parameter->required(true);
            }

            $parameters[] = $parameter;
        }

        $parameterExtractionResults[] = new ParametersExtractionResult(
            parameters: $parameters,
            schemaName: class_basename($dataClassName),
        );

        return $parameterExtractionResults;
    }

    private function getDataClassName(RouteInfo $routeInfo): ?string
    {
        if (! $reflectionAction = $routeInfo->reflectionAction()) {
            return null;
        }

        /** @var ReflectionParameter|null $dataParam */
        $dataParam = collect($reflectionAction->getParameters())->first(function (ReflectionParameter $param) {
            $type = $param->getType();

            if (! $type instanceof ReflectionNamedType) {
                return false;
            }

            $className = $type->getName();

            return class_exists($className) && is_subclass_of($className, Data::class);
        });

        if (! $dataParam) {
            return null;
        }

        return $dataParam->getType()->getName();
    }
}
