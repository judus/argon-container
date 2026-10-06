<?php

declare(strict_types=1);

namespace Maduser\Argon\Container\Compiler;

use Maduser\Argon\Container\ArgonContainer;
use Maduser\Argon\Container\Exceptions\NotFoundException;
use Maduser\Argon\Container\Support\CallableInvoker;
use Nette\PhpGenerator\ClassType;

final class CoreContainerGenerator
{
    private bool $strictMode = false;

    public function __construct(private readonly ArgonContainer $container)
    {
    }

    public function generate(CompilationContext $context): void
    {
        $this->strictMode = $context->strictMode;
        $class = $context->class;

        $this->generateConstructor($class);
        $this->generateCoreProperties($class);
        $this->generateInterceptorMethods($class);
        $this->generateObjectResultValidationMethod($class);
        $this->generateHasMethod($class);
        $this->generateGetMethod($class);
        $this->generateGetTaggedMethod($class);
        $this->generateGetTaggedIdsMethod($class);
        $this->generateGetTaggedMetaMethod($class);
        $this->generateInvokeMethod($class);
        $this->generateInvokeServiceMethod($class);
        $this->generateBuildCompiledInvokerMethodName($class);
    }

    private function generateConstructor(ClassType $class): void
    {
        $constructor = $class->addMethod('__construct')->setPublic();
        if ($this->strictMode) {
            $constructor->addBody('parent::__construct(strictMode: true);');
        } else {
            $constructor->addBody(<<<'PHP'
                $bindings = new \Maduser\Argon\Container\ContextualBindings();
                $argumentResolver = new \Maduser\Argon\Container\ArgumentResolver(
                    new \Maduser\Argon\Container\ContextualResolver($this, $bindings),
                    new \Maduser\Argon\Container\ArgumentMap(),
                    $bindings
                );
                parent::__construct(contextualRegistry: $bindings, argumentResolver: $argumentResolver);

                // Fallback construction must resolve dependencies through the compiled graph.
                $argumentResolver->setServiceResolver(
                    new \Maduser\Argon\Container\Support\ContainerServiceResolver($this)
                );
            PHP);
        }

        $parameterStore = $this->container->getParameters()->all();
        if (!empty($parameterStore)) {
            $formatted = var_export($parameterStore, true);
            $constructor->addBody("\$this->getParameters()->setStore({$formatted});");
        }
    }

    private function generateCoreProperties(ClassType $class): void
    {
        $class->addProperty('resolving')->setPrivate()->setType('array')->setValue([]);
        $class->addProperty('tagMap')->setPrivate()->setValue($this->container->getTags(true));
        $class->addProperty('parameters')->setPrivate()->setValue($this->container->getParameters()->all());

        $class->addProperty('preInterceptors')->setPrivate()->setValue(array_map(
            fn($i) => '\\' . ltrim($i, '\\'),
            $this->container->getPreInterceptors()
        ));

        $class->addProperty('postInterceptors')->setPrivate()->setValue(array_map(
            fn($i) => '\\' . ltrim($i, '\\'),
            $this->container->getPostInterceptors()
        ));
    }

    private function generateInterceptorMethods(ClassType $class): void
    {
        $pre = $class->addMethod('applyPreInterceptors');
        $pre->setPrivate()
            ->setReturnType('object')
            ->setReturnNullable(true);

        $pre->addParameter('id')->setType('string');
        $pre->addParameter('args')->setType('array')->setDefaultValue([])->setReference();

        $pre->setBody(<<<'PHP'
            foreach ($this->preInterceptors as $interceptor) {
                if (!$interceptor::supports($id)) {
                    continue;
                }

                $resolved = $this->get($interceptor);
                $result = $resolved->intercept($id, $args);
                if ($result !== null) {
                    return $result;
                }
            }
            return null;
        PHP);

        $post = $class->addMethod('applyPostInterceptors');
        $post->setPrivate()
            ->setReturnType('object');

        $post->addParameter('instance')->setType('object');

        $post->setBody(<<<'PHP'
            foreach ($this->postInterceptors as $interceptor) {
                if (!$interceptor::supports($instance)) {
                    continue;
                }

                $resolved = $this->get($interceptor);
                $resolved->intercept($instance);
            }
            return $instance;
        PHP);
    }

    private function generateObjectResultValidationMethod(ClassType $class): void
    {
        $method = $class->addMethod('ensureObjectServiceResult');
        $method->setPrivate()
            ->setReturnType('object');

        $method->addParameter('id')->setType('string');
        $method->addParameter('value')->setType('mixed');
        $method->addParameter('source')->setType('string');

        $method->setBody(<<<'PHP'
            if (!is_object($value)) {
                throw ContainerException::fromServiceId(
                    $id,
                    sprintf('%s must return an object, got %s.', $source, get_debug_type($value))
                );
            }

            return $value;
        PHP);
    }

    private function generateGetMethod(ClassType $class): void
    {
        $method = $class->addMethod('get')
            ->setReturnType('object');

        $fallback = $this->strictMode
            ? 'throw new NotFoundException($id, \'compiled\');'
            : 'return $this->applyPostInterceptors(parent::get($id, $args));';

        $method->setBody(<<<PHP
            if (
                \$id === \\Maduser\\Argon\\Container\\ArgonContainer::class
                || \$id === \\Psr\\Container\\ContainerInterface::class
            ) {
                return \$this;
            }

            if (isset(\$this->resolving[\$id])) {
                \$chain = array_keys(\$this->resolving);
                \$chain[] = \$id;
                throw ContainerException::forCircularDependency(\$id, \$chain);
            }

            \$this->resolving[\$id] = true;

            try {
                \$instance = \$this->applyPreInterceptors(\$id, \$args);
                if (\$instance !== null) {
                    return \$instance;
                }

                if (isset(\$this->serviceMap[\$id])) {
                    return \$this->{\$this->serviceMap[\$id]}(\$args);
                }

                {$fallback}
            } finally {
                unset(\$this->resolving[\$id]);
            }
        PHP);

        $method->addParameter('id')->setType('string');
        $method->addParameter('args')->setType('array')->setDefaultValue([]);
    }

    private function generateGetTaggedMethod(ClassType $class): void
    {
        $class->addMethod('getTagged')
            ->setReturnType('array')
            ->setBody(<<<'PHP'
            if (!isset($this->tagMap[$tag])) {
                return [];
            }

            $results = [];
            foreach (array_keys($this->tagMap[$tag]) as $id) {
                $results[] = $this->get($id);
            }

            return $results;
        PHP)
            ->addParameter('tag')->setType('string');
    }

    private function generateGetTaggedIdsMethod(ClassType $class): void
    {
        $class->addMethod('getTaggedIds')
            ->setReturnType('array')
            ->setBody('return array_keys($this->tagMap[$tag] ?? []);')
            ->addParameter('tag')->setType('string');
    }

    private function generateGetTaggedMetaMethod(ClassType $class): void
    {
        $class->addMethod('getTaggedMeta')
            ->setReturnType('array')
            ->setBody(<<<'PHP'
            return $this->tagMap[$tag] ?? [];
        PHP)
            ->addParameter('tag')->setType('string');
    }

    private function generateHasMethod(ClassType $class): void
    {
        $body = $this->strictMode
            ? 'return isset($this->serviceMap[$id]);'
            : 'return isset($this->serviceMap[$id]) || parent::has($id);';

        $class->addMethod('has')
            ->setReturnType('bool')
            ->setBody(<<<'PHP'
                if (
                    $id === \Maduser\Argon\Container\ArgonContainer::class
                    || $id === \Psr\Container\ContainerInterface::class
                ) {
                    return true;
                }

            PHP . $body)
            ->addParameter('id')->setType('string');
    }

    private function generateInvokeMethod(ClassType $class): void
    {
        $class->addProperty('callableInvoker')
            ->setPrivate()
            ->setType('?' . CallableInvoker::class)
            ->setValue(null);

        $invoke = $class->addMethod('invoke')
            ->setPublic()
            ->setReturnType('mixed');

        $invoke->addParameter('target')->setType('callable|object|array|string');
        $invoke->addParameter('arguments')->setType('array')->setDefaultValue([]);

        $invoke->setBody(<<<'PHP'
            if ($this->callableInvoker === null) {
                $bindings = $this->getContextualBindings();
                $resolver = new \Maduser\Argon\Container\Support\ContainerServiceResolver($this);
                $argumentResolver = new \Maduser\Argon\Container\ArgumentResolver(
                    new \Maduser\Argon\Container\ContextualResolver($this, $bindings),
                    new \Maduser\Argon\Container\ArgumentMap(),
                    $bindings
                );
                $argumentResolver->setServiceResolver($resolver);
                $this->callableInvoker = new \Maduser\Argon\Container\Support\CallableInvoker(
                    $resolver,
                    $argumentResolver
                );
            }

            return $this->callableInvoker->call($target, $arguments);
        PHP);
    }

    private function generateInvokeServiceMethod(ClassType $class): void
    {
        $method = $class->addMethod('invokeServiceMethod')
            ->setPrivate()
            ->setReturnType('mixed')
            ->setBody($this->strictMode
                ? <<<'PHP'
            $compiledMethod = $this->buildCompiledInvokerMethodName($serviceId, $method);

            if (method_exists($this, $compiledMethod)) {
                return $this->{$compiledMethod}($args);
            }

            throw new NotFoundException($serviceId, 'compiled invoke');
        PHP
                : <<<'PHP'
            $compiledMethod = $this->buildCompiledInvokerMethodName($serviceId, $method);

            if (method_exists($this, $compiledMethod)) {
                return $this->{$compiledMethod}($args);
            }

            return $this->invoke([$serviceId, $method], $args);
        PHP
            );

        $method->addParameter('serviceId')->setType('string');
        $method->addParameter('method')->setType('string');
        $method->addParameter('args')->setType('array')->setDefaultValue([]);
    }

    private function generateBuildCompiledInvokerMethodName(ClassType $class): void
    {
        $method = $class->addMethod('buildCompiledInvokerMethodName')
            ->setPrivate()
            ->setReturnType('string');

        $method->addParameter('serviceId')->setType('string');
        $method->addParameter('method')->setType('string')->setDefaultValue('__invoke');

        $method->setBody(<<<'PHP'
            $sanitizedService = preg_replace('/[^A-Za-z0-9_]/', '_', $serviceId);
            $sanitizedMethod  = preg_replace('/[^A-Za-z0-9_]/', '_', $method);
        
            return 'invoke_' . $sanitizedService . '__' . $sanitizedMethod;
    PHP);
    }
}
