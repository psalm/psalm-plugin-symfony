# Symfony Psalm Plugin

[![Build Status](https://travis-ci.com/seferov/symfony-psalm-plugin.svg?branch=master)](https://travis-ci.com/seferov/symfony-psalm-plugin)

### Installation

```
composer require --dev seferov/symfony-psalm-plugin
vendor/bin/psalm-plugin enable seferov/symfony-psalm-plugin
```

### Features

- Detect `ContainerInterface::get()` result type
- Fixes `PossiblyInvalidArgument` for `Symfony\Component\HttpFoundation\Request::getContent`.
The plugin calculates real return type by checking the given argument and marks return type as either string or resource.
- Complains when `Container` is injected to a service. Use dependency-injection.

### Credits

- [@weirdan](https://github.com/weirdan) for [codeception psalm module](https://github.com/weirdan/codeception-psalm-module)
