<?php

declare(strict_types=1);

namespace Maduser\Argon\Container\Compiler;

use Maduser\Argon\Container\ArgonContainer;
use Maduser\Argon\Container\Exceptions\ContainerException;
use ReflectionException;

final class ContainerCompiler
{
    private CompilationContextFactory $contextFactory;
    private CoreContainerGenerator $coreGenerator;
    private ContainerDescriptorValidator $descriptorValidator;
    private ServiceDefinitionGenerator $serviceDefinitionGenerator;
    private ServiceInvocationGenerator $serviceInvocationGenerator;

    public function __construct(
        private readonly ArgonContainer $container,
        ?CompilationContextFactory $contextFactory = null,
        ?CoreContainerGenerator $coreGenerator = null,
        ?ContainerDescriptorValidator $descriptorValidator = null,
        ?ServiceDefinitionGenerator $serviceDefinitionGenerator = null,
        ?ServiceInvocationGenerator $serviceInvocationGenerator = null,
        ?ParameterExpressionResolver $parameterResolver = null
    ) {
        $this->contextFactory = $contextFactory ?? new CompilationContextFactory();
        $this->coreGenerator = $coreGenerator ?? new CoreContainerGenerator($container);
        $this->descriptorValidator = $descriptorValidator ?? new ContainerDescriptorValidator();

        if ($serviceDefinitionGenerator === null) {
            $parameterResolver ??= new ParameterExpressionResolver($container, $container->getContextualBindings());
            $serviceDefinitionGenerator = new ServiceDefinitionGenerator($parameterResolver);
        }

        $this->serviceDefinitionGenerator = $serviceDefinitionGenerator;
        $this->serviceInvocationGenerator = $serviceInvocationGenerator ?? new ServiceInvocationGenerator($container);
    }

    /**
     * @throws ContainerException
     * @throws ReflectionException
     */
    public function compile(
        string $filePath,
        string $className,
        string $namespace = 'App\\Compiled',
        ?bool $strictMode = null
    ): void {
        if ($strictMode === null) {
            $strictMode = $this->container->isStrictMode();
        }

        $this->descriptorValidator->validate($this->container);

        $context = $this->contextFactory->create($this->container, $namespace, $className, $strictMode);

        $this->coreGenerator->generate($context);
        $this->serviceDefinitionGenerator->generate($context);
        $this->serviceInvocationGenerator->generate($context->class);

        $compiled = (string) $context->file;

        if (!is_file($filePath) || @md5_file($filePath) !== md5($compiled)) {
            $this->publish($filePath, $compiled);
        }
    }

    private function publish(string $filePath, string $compiled): void
    {
        // Resolve existing symlinks so publishing replaces their target, not the link.
        $resolvedPath = realpath($filePath);
        $destination = $resolvedPath === false ? $filePath : $resolvedPath;
        $directory = realpath(dirname($destination));
        if ($directory === false || !is_dir($directory)) {
            throw new ContainerException(
                "Cannot compile container to [$filePath]: destination directory does not exist."
            );
        }

        $permissions = is_file($destination) ? @fileperms($destination) : 0666 & ~umask();
        if ($permissions === false) {
            throw new ContainerException("Cannot read compiled container permissions for [$filePath].");
        }

        $temporary = @tempnam($directory, '.argon-');
        if ($temporary === false) {
            throw new ContainerException("Cannot create temporary compiled container for [$filePath].");
        }

        try {
            // tempnam may fall back to the system temp directory; that cannot guarantee atomic rename.
            if (dirname($temporary) !== $directory) {
                throw new ContainerException("Cannot create temporary compiled container in [$directory].");
            }
            if (@file_put_contents($temporary, $compiled) !== strlen($compiled)) {
                throw new ContainerException("Cannot write complete compiled container for [$filePath].");
            }
            if (!@chmod($temporary, $permissions & 0777)) {
                throw new ContainerException("Cannot set compiled container permissions for [$filePath].");
            }
            if (!@rename($temporary, $destination)) {
                throw new ContainerException("Cannot publish compiled container to [$filePath].");
            }
        } finally {
            if (file_exists($temporary) && !@unlink($temporary)) {
                throw new ContainerException("Cannot remove temporary compiled container [$temporary].");
            }
        }
    }
}
