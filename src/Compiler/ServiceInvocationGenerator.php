<?php

declare(strict_types=1);

namespace Maduser\Argon\Container\Compiler;

use Maduser\Argon\Container\ArgonContainer;
use Maduser\Argon\Container\Support\StringHelper;
use Nette\PhpGenerator\ClassType;

final class ServiceInvocationGenerator
{
    public function __construct(private readonly ArgonContainer $container)
    {
    }

    public function generate(ClassType $class): void
    {
        foreach ($this->container->getBindings() as $serviceId => $descriptor) {
            if ($descriptor->shouldCompile() === false) {
                continue;
            }

            foreach ($descriptor->getInvocationMap() as $method => $args) {
                $compiledMethodName = $this->buildMethodInvokerName($serviceId, $method);
                $controllerFetch = "\$controller = \$this->get(" . var_export($serviceId, true) . ");";

                $compiledArgs = [];
                foreach ($args as $name => $value) {
                    if (is_string($value) && str_starts_with($value, '@')) {
                        $className = substr($value, 1);
                        $compiledArgs[] = var_export($name, true) .
                            " => \$this->get(" . var_export($className, true) . ")";
                    } else {
                        $compiledArgs[] = var_export($name, true) .
                            " => " . var_export($value, true);
                    }
                }

                $indent = str_repeat(' ', 20);

                $lines = [
                    $indent . $controllerFetch,
                    $indent . '$mergedArgs = ' . 'array_merge([' . implode(", ", $compiledArgs) . '], $args);',
                ];

                $lines[] = $indent . 'return \\Maduser\\Argon\\Container\\Support\\CompiledCallableInvoker::invoke('
                    . "\$controller->{$method}(...), \$mergedArgs);";

                $body = implode("\n", $lines);

                $class->addMethod($compiledMethodName)
                    ->setPublic()
                    ->setReturnType('mixed')
                    ->setBody($body)
                    ->addParameter('args')->setType('array')->setDefaultValue([]);
            }
        }
    }

    private function buildMethodInvokerName(string $serviceId, string $method): string
    {
        $sanitizedService = StringHelper::sanitizeIdentifier($serviceId);
        $sanitizedMethod  = StringHelper::sanitizeIdentifier($method);

        return 'invoke_' . $sanitizedService . '__' . $sanitizedMethod;
    }
}
