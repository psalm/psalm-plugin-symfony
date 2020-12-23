# Symfony Psalm Plugin

![Integrate](https://github.com/psalm/psalm-plugin-symfony/workflows/Integrate/badge.svg)

### Installation

```
composer require --dev psalm/plugin-symfony
vendor/bin/psalm-plugin enable psalm/plugin-symfony
```

### Features

- Detect `ContainerInterface::get()` result type. Works better if you [configure](#configuration) compiled container XML file.
- Support [Service Subscribers](https://github.com/psalm/psalm-plugin-symfony/issues/20). Works only if you [configure](#configuration) compiled container XML file.
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
- And [much more](https://github.com/psalm/psalm-plugin-symfony/tree/master/tests/acceptance/acceptance)!

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

### Twig tainting (experimental)

When it comes to taint analysis for twig templates, there are currently two approaches :

 - The first is based on a specific file analyzer (`Psalm\SymfonyPsalmPlugin\Twig\TemplateFileAnalyzer`) which leverages the twig parser and visits the AST nodes.
 - The second one is based on the already compiled twig templates, it only bridges calls to `Twig\Environment::render` to the actual `doRender` method of the compiled template.

#### Twig Analyzer

This approach is more robust as it relies on the official twig parser and node visitors mechanisms.
For the moment it can only detects simple taints paths.

To leverage the real Twig file analyzer, you have to configure a checker for the `.twig` extension as follows :

```xml
<fileExtensions>
   <extension name=".php" />
   <extension name=".twig" checker="./vendor/psalm/plugin-symfony/src/Twig/TemplateFileAnalyzer.php"/>
</fileExtensions>
```

#### Cache Analyzer

This approach is more "dirty" as it tries to connect the taints from the application code to the compiled PHP code representing a given template.
It is theoricaly able to detect more taints than the previous approach out-of-the-box, but it still lakes ways to handle inheritance and stuff like that.

To allow the analysis through the cached template files, you have to add the `twigCachePath` entry to the plugin configuration :

```xml
<pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
    <twigCachePath>/cache/twig</twigCachePath>
</pluginClass>
```

### Credits

- Plugin created by [@seferov](https://github.com/seferov)
- [@weirdan](https://github.com/weirdan) for [codeception psalm module](https://github.com/weirdan/codeception-psalm-module)
