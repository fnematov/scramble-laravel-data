<?php

namespace Dedoc\Scramble\Support\InferExtensions;

use Dedoc\Scramble\Infer\Extensions\Event\MethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\MethodReturnTypeExtension;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\GenericClassStringType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Spatie\LaravelData\Data;

/**
 * Intercepts Data::paginated() and DataPaginatedResponse::from() calls
 * to infer which Data class is being paginated for Scramble docs.
 */
class LaravelDataPaginatedInferExtension implements MethodReturnTypeExtension
{
    public function shouldHandle(ObjectType $type): bool
    {
        if (! class_exists(Data::class)) {
            return false;
        }

        return $type->isInstanceOf(Data::class)
            || $type->isInstanceOf(\Dedoc\Scramble\Support\LaravelData\DataPaginatedResponse::class);
    }

    public function getMethodReturnType(MethodCallEvent $event): ?Type
    {
        $responseClass = \Dedoc\Scramble\Support\LaravelData\DataPaginatedResponse::class;

        // Handle DataPaginatedResponse::from(UserData::class, $paginator)
        if ($event->name === 'from' && $event->getInstance()->isInstanceOf($responseClass)) {
            $dataClassArg = $event->getArg('dataClass', 0);

            return new Generic($responseClass, [$dataClassArg]);
        }

        // Handle UserData::paginated($paginator) — infer Data class from the caller
        if ($event->name === 'paginated' && $event->getInstance()->isInstanceOf(Data::class)) {
            $callerClass = $event->getInstance()->name;
            $dataClassType = new GenericClassStringType(new ObjectType($callerClass));

            return new Generic($responseClass, [$dataClassType]);
        }

        return null;
    }
}
