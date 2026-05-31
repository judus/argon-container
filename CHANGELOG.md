# Changelog

All notable changes to `maduser/argon-container` will be documented in this file.

## [Unreleased]

### Fixed

- Removed the duplicate lowercase `readme.md` and kept the full package documentation as `README.md`.

## [1.3.0] - 2026-05-24

### Changed

- Renamed the Composer package from `maduser/argon` to `maduser/argon-container`.
- Added temporary Composer replacement metadata for `maduser/argon`.
- Updated repository, Packagist, and documentation references for the container package identity.

## [1.2.0] - 2026-05-21

### Added

- Added runtime/compiled `ServiceInvoker` parity coverage for route-style invocation maps.

### Fixed

- Container compilation now rejects `defineInvocation()` metadata for missing or non-public target methods before writing generated PHP.
- Factory-backed services now keep factory object resolution arguments separate from factory method arguments.
- Runtime and compiled service factories, closure bindings, and contextual closure bindings now reject non-object service results instead of casting them to objects.

## [1.1.2] - 2026-05-21

### Fixed

- `ContainerCompiler::compile()` now distinguishes omitted strict mode from an explicit `false` value.
- Runtime resolution now detects circular alias chains while following class aliases.
- `registerInterceptor()` now rejects classes that implement neither pre- nor post-resolution interceptor contracts.
- `ParameterStore::get()` now preserves explicitly stored `null` values when a default value is provided.

## [1.1.1] - 2026-05-20

### Fixed

- Runtime resolution now clears the circular-dependency guard after failed resolutions, preventing repeated failures from being misreported as circular dependencies.
- `extend()` API documentation now matches its existing resolve-then-decorate behavior.

## [1.1.0] - 2026-05-20

### Changed

- Runtime arguments for shared services are now accepted only before the shared instance is created. Passing runtime arguments after a shared service has already been resolved throws `ContainerException` instead of silently ignoring them.
- `composer check` is now a non-mutating quality gate. Code style fixes remain available through `composer phpcs:fix`.
- `composer check` now runs Psalm without cache so local static analysis cannot hide stale findings.
- `composer test:coverage` no longer opens the generated coverage report as a GUI side effect.
- Compiler integration tests now write generated containers to isolated temporary directories.
- GitHub Actions now runs the test suite on PHP 8.2, 8.3, 8.4, and experimental PHP 8.5. The experimental PHP 8.5 job ignores dependency PHP platform requirements during install until development tools declare PHP 8.5 support.

### Fixed

- Compiled containers now honor transient lifecycle for regular and factory bindings.
- Compiled shared services now apply post-resolution interceptors only when the shared instance is created.
- Closure bindings now resolve parameters through the container at runtime.
- Contextual closure bindings now resolve parameters through the container at runtime.
- Compiled containers now throw `ContainerException` for missing required runtime arguments instead of leaking undefined-array-key notices.
- Compiled argument resolution now preserves explicit `null` arguments instead of treating them as absent.
- Runtime and compiled argument resolution now share the same resolution plan for constructor and factory method parameters.
- Compiled runtime class-string arguments now resolve through the container when they target object-typed parameters.
- Container compilation now validates closure bindings, factory methods, and non-instantiable concretes before writing generated PHP.
- Closure compilation errors now explicitly point to `skipCompilation()` or boot-time/runtime registration.
- `isResolvable()` now respects strict mode and only treats concrete instantiable classes as implicitly resolvable.
- PHP 8.4 test deprecations for implicit nullable parameters were removed.
- Compiler argument expression tests now exercise the real resolver instead of duplicated fake resolver logic.
- PHP 8.2 compatibility is preserved by avoiding typed class constants in internal argument resolution metadata.
- PHP 8.5 test deprecations from redundant reflection accessibility calls were removed.
- CI Psalm runs no longer report an unused contextual binding property in compiled parameter expression resolution.
- Compiler validation and argument resolution edge cases now remain covered by tests after the 1.1.0 release bump.
- Internal container contracts now use tighter PHPDoc shapes for bindings, contextual bindings, interceptors, and optional resolution.

### Documentation

- Clarified that `optional()` is intentionally binding-based and does not autowire unbound concrete classes.
- Clarified runtime argument behavior for shared services and transient services.
- Clarified runtime-only closure binding behavior and compiled-container limitations.
- Clarified that `extend()` resolves then decorates a service and replaces the binding for future resolutions.
