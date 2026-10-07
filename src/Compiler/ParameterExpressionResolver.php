<?php

declare(strict_types=1);

namespace Maduser\Argon\Container\Compiler;

use Maduser\Argon\Container\ArgonContainer;
use Maduser\Argon\Container\Contracts\ContextualBindingsInterface;
use Maduser\Argon\Container\Exceptions\ContainerException;
use Maduser\Argon\Container\Support\ArgumentResolutionPlan;
use Maduser\Argon\Container\Support\ArgumentResolutionPlanner;
use Maduser\Argon\Container\Support\ArgumentResolutionStep;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;

final class ParameterExpressionResolver
{
    private readonly ArgumentResolutionPlanner $planner;

    public function __construct(
        private readonly ArgonContainer $container,
        ContextualBindingsInterface $contextualBindings
    ) {
        $this->planner = new ArgumentResolutionPlanner($contextualBindings);
    }

    /**
     * @param class-string $class
     * @return list<string>
     *
     * @throws ReflectionException
     */
    public function resolveConstructorArguments(
        string $class,
        string $serviceId,
        string $argsVar = '$args'
    ): array {
        $reflectionClass = new ReflectionClass($class);
        $constructor = $reflectionClass->getConstructor();

        if (!$constructor) {
            return [];
        }

        $resolved = [];

        foreach ($constructor->getParameters() as $param) {
            $resolved[] = $this->resolveParameter($param, $serviceId, $argsVar);
        }

        return $resolved;
    }

    /**
     * Resolves a constructor parameter for code generation in the compiled container.
     *
     * @throws ContainerException
     */
    public function resolveParameter(
        ReflectionParameter $parameter,
        string $serviceId,
        string $argsVar = '$args'
    ): string {
        $declaringClass = $parameter->getDeclaringClass();
        $context = $declaringClass?->getName() ?? $serviceId;
        $descriptor = $this->container->getDescriptor($serviceId);
        if ($descriptor !== null) {
            $descriptor = (new ServiceDefinitionResolver())->resolve($this->container, $serviceId, $descriptor);
            if ($descriptor instanceof CircularAlias) {
                return 'throw ContainerException::forCircularDependency('
                    . var_export($descriptor->id, true) . ', ' . var_export($descriptor->chain, true) . ')';
            }
        }
        $boundArguments = $descriptor?->getArguments() ?? [];
        $plan = $this->planner->build(
            $parameter,
            $context,
            $serviceId,
            $boundArguments
        );

        return $this->renderPlan($plan, $argsVar);
    }

    private function renderPlan(ArgumentResolutionPlan $plan, string $argsVar): string
    {
        return $this->renderStep($plan, $plan->steps(), 0, $argsVar);
    }

    /**
     * @param list<ArgumentResolutionStep> $steps
     */
    private function renderStep(
        ArgumentResolutionPlan $plan,
        array $steps,
        int $index,
        string $argsVar
    ): string {
        $step = $steps[$index] ?? null;
        if ($step === null) {
            return $this->renderFailure($plan, "Missing required argument '{$plan->parameterName()}'");
        }

        return match ($step->kind()) {
            ArgumentResolutionStep::RUNTIME_ARGUMENT => $this->renderRuntimeArgument(
                $plan,
                $steps,
                $index,
                $argsVar
            ),
            ArgumentResolutionStep::BOUND_ARGUMENT => $this->renderValue($plan, $step->value()),
            ArgumentResolutionStep::CONTEXTUAL_SERVICE => $this->renderContextualService($plan, $step),
            ArgumentResolutionStep::SERVICE => $this->renderServiceStep($plan, $step),
            ArgumentResolutionStep::DEFAULT_VALUE => var_export($step->value(), true),
            ArgumentResolutionStep::NULL_VALUE => 'null',
            ArgumentResolutionStep::PRIMITIVE_FAILURE => $this->renderFailure(
                $plan,
                "Missing required argument '{$plan->parameterName()}'"
            ),
            ArgumentResolutionStep::FAILURE => $this->renderFailure($plan, (string) $step->message()),
            default => $this->renderFailure($plan, "Unknown argument resolution step '{$step->kind()}'."),
        };
    }

    /**
     * @param list<ArgumentResolutionStep> $steps
     */
    private function renderRuntimeArgument(
        ArgumentResolutionPlan $plan,
        array $steps,
        int $index,
        string $argsVar
    ): string {
        $name = var_export($plan->parameterName(), true);
        $runtime = "{$argsVar}[{$name}]";
        $value = $this->renderResolvableExpression($plan, $runtime);
        $fallback = $this->renderStep($plan, $steps, $index + 1, $argsVar);

        return "array_key_exists({$name}, {$argsVar}) ? {$value} : {$fallback}";
    }

    private function renderValue(ArgumentResolutionPlan $plan, mixed $value): string
    {
        $classString = $plan->resolveClassString($value);
        if ($classString !== null) {
            return $this->renderService($classString);
        }

        return var_export($value, true);
    }

    private function renderResolvableExpression(ArgumentResolutionPlan $plan, string $expression): string
    {
        if (!$plan->canResolveClassStringParameter()) {
            return $expression;
        }

        return sprintf(
            '(is_string(%1$s) && is_a(%1$s, %2$s, true) ? $this->get(%1$s) : %1$s)',
            $expression,
            var_export($plan->namedTypeName(), true)
        );
    }

    private function renderContextualService(ArgumentResolutionPlan $plan, ArgumentResolutionStep $step): string
    {
        $serviceId = $step->serviceId();
        if ($serviceId !== null) {
            return $this->renderService($serviceId);
        }

        return $this->renderFailure(
            $plan,
            sprintf(
                "Cannot compile contextual closure binding for parameter '%s'. " .
                "Use skipCompilation() to exclude it, " .
                "or register the closure during boot/runtime after compilation.",
                $plan->parameterName()
            )
        );
    }

    private function renderServiceStep(ArgumentResolutionPlan $plan, ArgumentResolutionStep $step): string
    {
        $serviceId = $step->serviceId();
        if ($serviceId === null) {
            return $this->renderFailure($plan, 'Service resolution step misses service id.');
        }

        return $this->renderService($serviceId);
    }

    private function renderService(string $serviceId): string
    {
        return '$this->get(' . var_export($serviceId, true) . ')';
    }

    private function renderFailure(ArgumentResolutionPlan $plan, string $message): string
    {
        return 'throw ContainerException::fromServiceId(' .
            var_export($plan->serviceId(), true) .
            ', ' .
            var_export($message, true) .
            ')';
    }
}
