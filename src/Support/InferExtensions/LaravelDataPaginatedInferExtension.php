<?php

namespace Dedoc\Scramble\Support\InferExtensions;

use Dedoc\Scramble\Infer\Extensions\Event\MethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\Event\StaticMethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\MethodReturnTypeExtension;
use Dedoc\Scramble\Infer\Extensions\StaticMethodReturnTypeExtension;
use Dedoc\Scramble\Support\LaravelData\DataPaginatedResponse;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\GenericClassStringType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Spatie\LaravelData\Data;

/**
 * Intercepts Data::paginated() and DataPaginatedResponse::from() calls
 * to infer which Data class is being paginated for Scramble docs.
 */
class LaravelDataPaginatedInferExtension implements MethodReturnTypeExtension, StaticMethodReturnTypeExtension
{
    public function shouldHandle(ObjectType|string $type): bool
    {
        if (! class_exists(Data::class)) {
            return false;
        }

        if (is_string($type)) {
            return is_a($type, Data::class, true)
                || is_a($type, DataPaginatedResponse::class, true);
        }

        return $type->isInstanceOf(Data::class)
            || $type->isInstanceOf(DataPaginatedResponse::class);
    }

    public function getMethodReturnType(MethodCallEvent $event): ?Type
    {
        // Handle DataPaginatedResponse::from(UserData::class, $paginator) as instance call
        if ($event->name === 'from' && $event->getInstance()->isInstanceOf(DataPaginatedResponse::class)) {
            $dataClassArg = $event->getArg('dataClass', 0);

            return new Generic(DataPaginatedResponse::class, [$dataClassArg]);
        }

        return null;
    }

    public function getStaticMethodReturnType(StaticMethodCallEvent $event): ?Type
    {
        $callee = $event->getCallee();

        // Handle UserData::paginated($paginator)
        if ($event->name === 'paginated' && is_a($callee, Data::class, true)) {
            $dataClassType = new GenericClassStringType(new ObjectType($callee));

            return new Generic(DataPaginatedResponse::class, [$dataClassType]);
        }

        // Handle DataPaginatedResponse::from(UserData::class, $paginator)
        if ($event->name === 'from' && is_a($callee, DataPaginatedResponse::class, true)) {
            $dataClassArg = $event->getArg('dataClass', 0);

            return new Generic(DataPaginatedResponse::class, [$dataClassArg]);
        }

        return null;
    }
}
