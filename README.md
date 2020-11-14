# Symfony Psalm Plugin

![Integrate](https://github.com/psalm/psalm-plugin-symfony/workflows/Integrate/badge.svg)

### Installation

```
composer require --dev psalm/plugin-symfony
vendor/bin/psalm-plugin enable psalm/plugin-symfony
```

### Features

- Detect `ContainerInterface::get()` result type. Works better if you [configure](#configuration) compiled container XML file.
- Support [Service Subscribers](https://github.com/psalm/psalm-plugin-symfony/issues/20).
- Detect return type of console arguments (`InputInterface::getArgument()`) and options (`InputInterface::getOption()`). Enforces
to use InputArgument and InputOption constants as a part of best practise.
- Detects correct Doctrine repository class if entities are configured with annotations.
- Fixes `PossiblyInvalidArgument` for `Symfony\Component\HttpFoundation\Request::getContent`.
The plugin calculates real return type by checking the given argument and marks return type as either string or resource.
- Detect return type of `Symfony\Component\HttpFoundation\HeaderBag::get` (by checking default value and third argument for < Symfony 4.4)
- Detect return type of `Symfony\Component\Messenger\Envelope::last` and `Symfony\Component\Messenger\Envelope::all`, based on the provided argument.
- Taint analysis for Symfony
- Detects service and parameter [naming convention](https://symfony.com/doc/current/contributing/code/standards.html#naming-conventions) violations
- Complains when `Container` is injected to a service. Use dependency-injection.
- Fix false positive `PropertyNotSetInConstructor` issues
  - $container in AbstractController
  - $context in ConstraintValidator classes
  - properties in custom `@Annotation` classes

### Configuration

If you follow installation instructions, psalm-plugin command will add plugin configuration to psalm.xml

```xml
<?xml version="1.0"?>
<psalm errorLevel="1">
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

Multiple container files can be configured. In this case, first valid file is taken into account.
If none of the given files is valid, configuration exception is thrown.
Example:

```xml
<pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
    <containerXml>var/cache/dev/App_KernelDevDebugContainer.xml</containerXml>
    <containerXml>var/cache/dev/App_KernelTestDebugContainer.xml</containerXml>
</pluginClass>
```

#### Twig tainting configuration

There are two approaches to including twig templates for taint analysis  :

 - one based on a specific file analyzer which uses the twig parser to taint twig's AST nodes
 - one based on the already compiled twig templates

To leverage the real Twig file analyzer, you have to configure the `.twig` extension as follows :

```xml
<fileExtensions>
   <extension name=".php" />
   <extension name=".twig" checker="./vendor/psalm/plugin-symfony/src/Twig/TemplateFileAnalyzer.php"/>
</fileExtensions>
```

To allow the analysis through the cached template files, you have to add the `twigCachePath` entry to the plugin configuration :

```xml
<pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
    <twigCachePath>/cache/twig</twigCachePath>
</pluginClass>
```

### Credits

- Plugin created by [@seferov](https://github.com/seferov)
- [@weirdan](https://github.com/weirdan) for [codeception psalm module](https://github.com/weirdan/codeception-psalm-module)
