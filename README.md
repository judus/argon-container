[![PHP](https://img.shields.io/badge/php-8.2+-blue)](https://www.php.net/)
[![Build](https://github.com/judus/argon-container/actions/workflows/php.yml/badge.svg)](https://github.com/judus/argon-container/actions)
[![codecov](https://codecov.io/gh/judus/argon-container/branch/master/graph/badge.svg)](https://codecov.io/gh/judus/argon-container)
[![Psalm Level](https://shepherd.dev/github/judus/argon-container/coverage.svg)](https://shepherd.dev/github/judus/argon-container)
[![Latest Version](https://img.shields.io/packagist/v/maduser/argon-container.svg)](https://packagist.org/packages/maduser/argon-container)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

# Argon Service Container

Argon is a high-performance, PSR-11 compliant dependency injection container with optional compilation.

It compiles your service graph into native PHP, eliminating reflection and runtime resolution overhead in production. Explicit bindings are preferred, but autowiring remains available when desired — allowing you to choose between strict, predictable behavior and dynamic flexibility.

**Key Characteristics**
- PSR-11 compliant
- Optional compilation to pure PHP
- Strict mode for explicit service registration
- Autowiring support when enabled
- No annotations, attributes, or external configuration formats
- Framework-agnostic

**Strict vs Dynamic**

Strict mode resolves only explicitly registered services and throws NotFoundException for unbound classes.

Dynamic mode (default) prefers explicit bindings but can autowire instantiable classes and invoke closures when no compiled binding exists.

Strictness can be enforced at runtime or baked into the compiled container.

---
## Installation

```bash
$ composer require maduser/argon-container
```

Requires PHP 8.2+

### Tests & QA

```bash
$ composer install
$ vendor/bin/phpunit
$ vendor/bin/psalm
$ vendor/bin/phpcs

# or all checks combined
$ composer check
```

---

## Usage

### Binding and Resolving Services

```php
// Register shared services (default)
$container->set(MyService::class);
$container->set(MyService::class, MyService::class); // explicit form

// Register transient (non-shared) services
$container->set(MyOtherService::class)->transient();
$container->set(ReportGenerator::class, AsyncReportGenerator::class)->transient();

// Register interface to concrete binding
$container->set(CacheInterface::class, InMemoryCache::class);

// Always bind dependencies before services that consume them
$container->set(LoggerInterface::class, FileLogger::class);
$container->set(ServiceNeedingLogger::class);

// Resolve services
$shared = $container->get(MyService::class);
$transient = $container->get(MyOtherService::class);
$cache = $container->get(CacheInterface::class);
$logger = $container->get(LoggerInterface::class);
```

> **Tip:** Bind aliases or interfaces *before* registering the services that consume them. Resolution happens immediately, so the container can only error, not infer, when a dependency is missing.

By default, every binding is shared. Prefer transient lifecycles instead? Pass `sharedByDefault: false`
to the constructor and opt specific services back into shared mode when needed:

```php
$container = new ArgonContainer(sharedByDefault: false);

$container->set(CacheInterface::class, InMemoryCache::class); // transient baseline
$container->set(LoggerInterface::class, FileLogger::class)->shared(); // explicit singleton
```
### Binding Arguments

When registering a service, you can provide **constructor arguments** using an associative array.  
These arguments are matched by **name** to the constructor’s parameter list — no need for full signatures or complex configuration.

```php
class ApiClient
{
    public function __construct(string $apiKey, string $apiUrl) {}
}
```

#### Set arguments during binding

```php
$container->set(ApiClient::class, args: [
    'apiKey' => 'dev-123',
    'apiUrl' => 'https://api.example.com',
]);
```

These arguments are attached to the service binding and used **every time** it's resolved.

#### Pass arguments during resolution

```php
$client = $container->get(ApiClient::class, args: [
    'apiKey' => 'prod-999',
    'apiUrl' => 'https://api.example.com',
]);
```

For **transient** services, runtime arguments may vary on every call. For **shared** services, runtime arguments
are accepted only before the shared instance has been created; after that, passing runtime arguments throws a
`ContainerException` instead of silently ignoring them. Prefer binding arguments for stable singleton configuration.

### Automatic Dependency Resolution

```php
class Logger {}

class UserService
{
    public function __construct(Logger $logger, string $env = 'prod') {}
}

$container->get(UserService::class); // Works out of the box

$strict = new ArgonContainer(strictMode: true);
$strict->set(Logger::class);
$strict->get(UserService::class); // OK

$strict->get(Logger::class); // ✅ registered binding
```
Argon will resolve `Logger` by class name, and skip `env` because it's optional. In **strict mode**, autowiring only works when you bind the dependency (as shown above); otherwise a `NotFoundException` is thrown.

What will **NOT** work in either mode:
```php
interface LoggerInterface {}

class UserService
{
    public function __construct(LoggerInterface $logger, string $env) {}
}

$container->get(UserService::class); // 500: No interface binding, no default value for $env.
```
In this case, you must bind the interface to a concrete class first and provide a default value for the primitive:
```php
$container->set(LoggerInterface::class, FileLogger::class);
$container->set(UserService::class, args: [
    'env' => $_ENV['APP_ENV'],
]);
```


### Parameter Registry

The **parameter registry** is a built-in key/value store used to centralize application configuration. It is fully 
compatible with the **compiled container** — values are embedded directly into the generated service code.

Use it to define reusable values, inject environment settings.

#### Set and retrieve values

```php
$parameters = $container->getParameters();

$parameters->set('apiKey', $_ENV['APP_ENV'] === 'prod' ? 'prod-key' : 'dev-key');
$parameters->set('apiUrl', 'https://api.example.com');

$apiKey = $parameters->get('apiKey');
```

#### Use parameters in bindings or at resolution

```php
$container->set(ApiClient::class, args: [
    'apiKey' => $parameters->get('apiKey'),
    'apiUrl' => $parameters->get('apiUrl'),
]);
```
TIP: you can wrap the parameter registry with your own "ConfigRepository" and implement validation, scopes via dot notation, etc.


### Factory Bindings

Use `factory()` to bind a service to a dedicated factory class.  
The factory itself is resolved via the container and may define either an `__invoke()` method or a named method.
Arguments bound to the product service, or passed to `get()` for the product service, are applied to the factory
method only. If the factory object needs configuration, bind the factory class itself with its own arguments.

```php
$container->set(ClockInterface::class)
    ->factory(ClockFactory::class);
```

This resolves and calls `ClockFactory::__invoke()`.

To use a specific method:

```php
$container->set(ClockInterface::class)
    ->factory(ClockFactory::class, 'create');
```

### Contextual Bindings

Contextual bindings allow different consumers to receive different implementations of the same interface.

```php
interface LoggerInterface {}
class FileLogger implements LoggerInterface {}
class DatabaseLogger implements LoggerInterface {}

class ServiceA 
{
    public function __construct(LoggerInterface $logger) {}
}

class ServiceB 
{
    public function __construct(LoggerInterface $logger) {}
}

$container->for(ServiceA::class)
    ->set(LoggerInterface::class, DatabaseLogger::class);

// Same Interface, different implementation
$container->for(ServiceB::class)
    ->set(LoggerInterface::class, FileLogger::class);
```

### Service Providers

Service providers allow grouping service bindings and optional boot-time logic.

```php
class AppServiceProvider implements ServiceProviderInterface
{
    // called before compilation and should be used to declare bindings
    public function register(ArgonContainer $container): void 
    {
        $container->set(LoggerInterface::class, FileLogger::class);
        $container->set(CacheInterface::class, RedisCache::class)->transient();
    }
    
    // Executed after compilation, once the container is ready to resolve services
    public function boot(ArgonContainer $container): void 
    {
        // Optional setup logic
    }
}

$container->register(AppServiceProvider::class);
$container->boot();
```

### Interceptors

Think of interceptors as a tiny, service-aware middleware pipeline wrapped around the resolver.  
Each interceptor:

- announces whether it applies by implementing a static `supports()` check (no global pipeline to walk for every service);
- can run **before** construction to tweak arguments or short-circuit with its own instance;
- can run **after** construction to decorate, validate, or otherwise finalise the resolved object.

Because they are scoped to specific services (or interfaces), you get the flexibility of middleware without forcing every resolution through a monolithic stack.

Compiled containers embed the same pre/post pipeline: when you call `compile()`, the current interceptors are snapshot and generated into the compiled `get()` method, so short-circuit logic and decorators behave exactly as they do at runtime.

#### Post-Resolution Interceptors

These are executed **after** a service is created, and can modify the object (e.g., inject metadata, call validation, register hooks).

```php
use Maduser\Argon\Container\Contracts\PostResolutionInterceptorInterface;

interface Validatable
{
    public function validate(): void;
}

class MyDTO implements Validatable
{
    public function validate(): void
    {
        // Verify required state
    }
}

class ValidationInterceptor implements PostResolutionInterceptorInterface
{
    public static function supports(string $id): bool
    {
        return is_subclass_of($id, Validatable::class);
    }

    public function intercept(object $instance): void
    {
        $instance->validate();
    }
}

$container->registerInterceptor(ValidationInterceptor::class);
$dto = $container->get(MyDTO::class); // validate() is automatically called
```

#### Pre-Resolution Interceptors

These run **before** a service is instantiated. They can modify constructor parameters or short-circuit the entire resolution.

```php
use Maduser\Argon\Container\Contracts\PreResolutionInterceptorInterface;

class EnvOverrideInterceptor implements PreResolutionInterceptorInterface
{
    public static function supports(string $id): bool
    {
        return $id === ApiClient::class;
    }

    public function intercept(string $id, array &$parameters): ?object
    {
        $parameters['apiKey'] = $_ENV['APP_ENV'] === 'prod'
            ? 'prod-key'
            : 'dev-key';

        return null; // let container continue
    }
}

class StubInterceptor implements PreResolutionInterceptorInterface
{
    public static function supports(string $id): bool
    {
        return $id === SomeHeavyService::class && $_ENV['TESTING'];
    }

    public function intercept(string $id, array &$parameters): ?object
    {
        return new FakeService(); // short-circuit
    }
}

$container->registerInterceptor(EnvOverrideInterceptor::class);
$container->registerInterceptor(StubInterceptor::class);
```

- Interceptors must implement either `PreResolutionInterceptorInterface` or `PostResolutionInterceptorInterface`.
- Both varieties require a static `supports()` method so only matching services trigger them.
- Pre-interceptors may *short-circuit* by returning an object; leaving `null` continues the pipeline.
- Interceptors are resolved lazily and only when matched.
- You can register as many interceptors as you want. They're evaluated in the order they were added.

### Extending Services

`extend()` decorates a resolved service instance during runtime. It calls `get()` internally, so the service does
not need to have been resolved before you extend it: a bound service is resolved immediately, then the decorated
object replaces the binding for later calls.

```php
// For example in a ServiceProvider 
public function boot(ArgonContainer $container): void
{
    $container->extend(LoggerInterface::class, function (object $logger): object {
        return new BufferingLogger($logger);
    });
}
```

From this point on, all calls to `get(LoggerInterface::class)` will return the wrapped instance.

In dynamic mode, `extend()` can also decorate an unbound concrete class that the container can autowire. In strict
mode, or for missing non-autowireable IDs, it fails the same way `get()` would. Extending a transient binding
currently replaces it with the decorated object for future resolutions.

### Tags

```php
$container->tag(FileLogger::class, ['loggers', 'file']);
$container->tag(DatabaseLogger::class, ['loggers', 'db']);

/** @var iterable<LoggerInterface> $loggers */
$loggers = $container->getTagged('loggers');

foreach ($loggers as $logger) {
    $logger->log('Hello from tagged logger!');
}
```

### Conditional Service Access

`optional()` is intentionally **binding-based**: it resolves the service only when a binding exists, otherwise it
returns a no-op proxy. This is useful for optional collaborators, plugins, or integrations where "not registered"
should mean "do nothing", even in dynamic mode where `get()` could autowire an unbound concrete class.

```php
// Suppose SomeLogger is optional
$container->optional(SomeLogger::class)->log('Only if logger exists');

// This won't throw, even if SomeLogger wasn't registered.
// If SomeLogger is autowireable but unbound, optional() still returns the proxy.
```

### Closure Bindings with Autowired Parameters

Closure bindings are convenient for CLI tools, prototyping, or runtime-only services. Their parameters are resolved
through the container at runtime, so dependencies can still be autowired.

Closures are not part of the compiled service graph. If a closure binding exists before compilation, either:

- register it during the `boot()` phase of a service provider, after the compiled container has been created;
- or explicitly mark it as excluded from compilation via `skipCompilation()`.

```php
// In a ServiceProvider — boot() runs at runtime, safe for closures
public function boot(ArgonContainer $container): void
{
    $container->set(LoggerInterface::class, fn (Config $config) => {
        return new FileLogger($config->get('log.path'));
    });
}
```
```php
// Exclude from compilation explicitly
$container->set(LoggerInterface::class, fn (Config $config) => {
    return new FileLogger($config->get('log.path'));
})->skipCompilation();
```

Skipped closures are not embedded in the generated class. In dynamic compiled containers, class-based skipped
bindings can still fall back to normal autowiring; non-class runtime services should be registered during boot.

### Compiling the Container

```php
$file = __DIR__ . '/CompiledContainer.php';

if (file_exists($file) && !$_ENV['DEV']) {
    require_once $file;
    $container = new App\Compiled\ProdContainer();
} else {
    $container = new ArgonContainer(strictMode: true);
    // configure $container...

    $compiler = new ContainerCompiler($container);
    $compiler->compile(
        $file,
        className: 'ProdContainer',
        namespace: 'App\\Compiled',
        strictMode: true
    );
}
```
The compiled container is a pure PHP class with zero runtime resolution logic for standard bindings. In **strict mode** the generated class omits the dynamic fallback entirely—missing registrations fail fast with `NotFoundException`. In magic mode it continues to fall back to the runtime resolver when needed.

When `strictMode` is omitted, the compiler mirrors the source container's current strict-mode setting. Pass
`strictMode: false` explicitly to force a lenient compiled container from a strict runtime container.

## `ArgonContainer` API

| ArgonContainer            | Parameters                                      | Return                                     | Description                                                                       |
|---------------------------|-------------------------------------------------|--------------------------------------------|-----------------------------------------------------------------------------------|
| `set()`                   | `string $id`, `Closure\|string\|null $concrete` | `BindingBuilderInterface`                  | Registers a service using the container's default lifecycle (override with `->transient()` or `->shared()`). |
| `get()`                   | `string $id`                                    | `object`                                   | Resolves and returns the service.                                                 |
| `has()`                   | `string $id`                                    | `bool`                                     | Checks if a service binding exists.                                               |
| `getBindings()`           | –                                               | `array<string, ServiceDescriptor>`         | Returns all registered service descriptors.                                       |
| `getContextualBindings()` | –                                               | `ContextualBindingsInterface`              | Returns all contextual service descriptors.                                       |
| `getDescriptor()`         | `string $id`                                    | `ServiceDescriptorInterface`               | Returns the service description associated with the id.                           |
| `getParameters()`         | –                                               | `ParameterStoreInterface`                  | Access the parameter registry for raw or shared values.                           |
| `registerInterceptor()`   | `class-string<InterceptorInterface> $class`     | `ArgonContainer`                           | Registers a type interceptor.                                                     |
| `register()`              | `class-string<ServiceProviderInterface>\|list<class-string<ServiceProviderInterface>> $providers` | `ArgonContainer`                           | Registers service provider(s) and runs their `register()` hooks.                  |
| `tag()`                   | `string $id`, `list<string> $tags`              | `ArgonContainer`                           | Tags a service with one or more labels.                                           |
| `getTags()`               | –                                               | `array<string, list<string>>`              | Returns all tag definitions in the container.                                     |
| `getTagged()`             | `string $tag`                                   | `list<object>`                             | Resolves all services tagged with the given label.                                |
| `boot()`                  | –                                               | `ArgonContainer`                           | Bootstraps all registered service providers.                                      |
| `extend()`                | `string $id`  `callable $decorator`             | `ArgonContainer`                           | Resolves then decorates a service, replacing the binding for future resolutions.  |
| `for()`                   | `string $target`                                | `ContextualBindingBuilder`                 | Begins a contextual binding chain — call `->set()` to define per-target bindings. |
| `getPreInterceptors()`    | –                                               | `list<class-string<InterceptorInterface>>` | Lists all registered pre-interceptors.                                            |
| `getPostInterceptors()`   | –                                               | `list<class-string<InterceptorInterface>>` | Lists all registered post-interceptors.                                           |
| `invoke()`                | `callable $target`, `array $params = []`        | `mixed`                                    | Calls a method or closure with injected dependencies.                             |
| `isResolvable()`          | `string $id`                                    | `bool`                                     | Checks if a service can be resolved, even if not explicitly bound.                |
| `optional()`              | `string $id`                                    | `object`                                   | Resolves an explicitly bound service or returns a NullServiceProxy if unbound.    |
| `isStrictMode()`          | –                                               | `bool`                                     | Indicates whether the container was instantiated in strict mode.                 |
| `isSharedByDefault()`     | –                                               | `bool`                                     | Reveals whether new bindings default to shared or transient lifecycle.           |

## `BindingBuilder` API

When you call `set()`, it returns a `BindingBuilder`, which lets you **configure** the binding fluently.

| Method               | Parameters                                       | Return                       | Description                                                                              |
|----------------------|--------------------------------------------------|------------------------------|------------------------------------------------------------------------------------------|
| `transient()`        | –                                                | `BindingBuilderInterface`    | Marks the service as non-shared. A new instance will be created for each request.        |
| `shared()`           | –                                                | `BindingBuilderInterface`    | Marks the service as shared, overriding a transient default if configured.              |
| `skipCompilation()`  | –                                                | `BindingBuilderInterface`    | Excludes this binding from the compiled container. Useful for closures or dynamic logic. |
| `tag()`              | `string\|list<string> $tags`                     | `BindingBuilderInterface`    | Assigns one or more tags to this service.                                                |
| `factory()`          | `string $factoryClass`, `?string $method = null` | `BindingBuilderInterface`    | Uses a factory class (optionally a method) to construct the service.                     |
| `defineInvocation()` | `string $methodName`, `array $args = []`         | `BindingBuilderInterface`    | Pre-defines arguments for a later `invoke()` call. Avoids reflection at runtime.         |
| `getDescriptor()`    | –                                                | `ServiceDescriptorInterface` | Returns the internal service descriptor for advanced inspection or modification.         |

---

### Troubleshooting

- **Circular dependency detected**: The message `Circular dependency detected for service '...'.` means your dependencies form a loop (e.g., `Foo → Bar → Foo`). Refactor one side to depend on an interface, inject a factory, or defer the dependency until after construction—the container intentionally refuses to pick a side. Argon can’t stop you from wiring a loop; it just halts resolution before PHP spins forever.
- **Service not found**: A `NotFoundException` signals that no binding or autowireable class exists for the requested ID (or strict mode forbids the fallback). Verify the identifier, register a binding, or disable strict mode for dynamic lookups. The attached JSON `DebugTrace` shows which parameter triggered the lookup.
- **Primitive parameter unresolved**: Errors such as `Cannot resolve primitive parameter '$env'` happen when constructors require scalars with no default. Provide them when binding (`set(Service::class, args: ['env' => 'prod'])`), supply a contextual override, or make the constructor argument optional.
- **Union type ambiguity**: Union-typed dependencies are rejected unless a contextual binding points to exactly one concrete. Bind per consumer with `$container->for(Consumer::class)->set(Interface::class, Implementation::class);`.
- **Factory argument mismatch**: `Missing required argument 'foo'` raised from a factory method means the binding’s arguments didn’t cover the method signature. Pass overrides in `$container->get(Service::class, ['foo' => ...])` or attach them to the binding via `set(..., args: [...])`.

When errors persist, inspect the `DebugTrace::toJson()` output embedded in exceptions—it captures the entire resolution path that led to the failure.

---


## License

MIT License
<!--
Argon is free and open-source. If you use it commercially or benefit from it in your work, please consider sponsoring or contributing back to support continued development.
-->
