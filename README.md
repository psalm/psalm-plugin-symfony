# Symfony Psalm Plugin

![Integrate](https://github.com/psalm/psalm-plugin-symfony/workflows/Integrate/badge.svg)

### Installation

```
composer require --dev psalm/plugin-symfony
vendor/bin/psalm-plugin enable psalm/plugin-symfony
```

### Features

- Detect `ContainerInterface::get()` result type. Works better if you [configure](#configuration) compiled container XML file.
- Fixes `PossiblyInvalidArgument` for `Symfony\Component\HttpFoundation\Request::getContent`.
The plugin calculates real return type by checking the given argument and marks return type as either string or resource.
- Complains when `Container` is injected to a service. Use dependency-injection.

### Configuration

If you followed installation instructions, psalm-plugin command would added plugin configuration to psalm.xml

```xml
<?xml version="1.0"?>
<psalm totallyTyped="true">
    <!--  project configuration -->

    <plugins>
        <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin" />
    </plugins>
</psalm>
```

To be able to detect return types of services using ID (generally starts with `@` in Symfony YAML config files. Ex: `logger` service)
`containerXml` must be provided. Example:
```xml
<pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
    <containerXml>var/cache/dev/App_KernelDevDebugContainer.xml</containerXml>
</pluginClass>
```

This file path may change based on your Symfony version, file structure and environment settings.
Default file for Symfony versions:
- Symfony 3: var/cache/dev/srcDevDebugProjectContainer.xml
- Symfony 4: var/cache/dev/srcApp_KernelDevDebugContainer.xml
- Symfony 5: var/cache/dev/App_KernelDevDebugContainer.xml

### Credits

- [@weirdan](https://github.com/weirdan) for [codeception psalm module](https://github.com/weirdan/codeception-psalm-module)
