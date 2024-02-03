# Symfony Psalm Plugin

![Integrate](https://github.com/psalm/psalm-plugin-symfony/workflows/Integrate/badge.svg)

### Installation

```
composer require --dev psalm/plugin-symfony
vendor/bin/psalm --init
vendor/bin/psalm-plugin enable psalm/plugin-symfony
```

### Versions & Dependencies

| Symfony Psalm Plugin | PHP        | Symfony | Psalm |
|----------------------|------------|---------|-------|
| 5.x                  | ^7.4, ^8.0 | 5, 6    | 5     |
| 4.x                  | ^7.4, ^8.0 | 4, 5, 6 | 4     |
| 3.x                  | ^7.1, ^8.0 | 4, 5, 6 | 4     |
| 2.x                  | ^7.1, ^8.0 | 4, 5    | 4     |
| 1.x                  | ^7.1       | 3, 4, 5 | 3     |

### Features

- Detects the `ContainerInterface::get()` result type. Works better if you [configure](#configuration) a compiled container XML file.
- Supports [Service Subscribers](https://github.com/psalm/psalm-plugin-symfony/issues/20). Works only if you [configure](#configuration) a compiled container XML file.
- Detects return types from console arguments (`InputInterface::getArgument()`) and options (`InputInterface::getOption()`).
Enforces to use "InputArgument" and "InputOption" constants as a best practise.
- Detects Doctrine repository classes associated to entities when configured via annotations.
- Fixes `PossiblyInvalidArgument` for `Symfony\Component\HttpFoundation\Request::getContent()`.
The plugin determines the real return type by checking the given argument and marks it as either "string" or "resource".
- Detects the return type of `Symfony\Component\HttpFoundation\HeaderBag::get()` by checking the default value (third argument for < Symfony 4.4).
- Detects the return types of `Symfony\Component\Messenger\Envelope::last` and `Symfony\Component\Messenger\Envelope::all`, based on the provided argument.
- Taint analysis for Symfony.
- Detects services and parameters [naming conventions](https://symfony.com/doc/current/contributing/code/standards.html#naming-conventions) violations.
- Complains when `Container` is injected in a service, and asks to use dependency-injection instead.
- Fixes `PropertyNotSetInConstructor` false positive issues:
  - $container in AbstractController
  - $context in ConstraintValidator classes
  - properties in custom `@Annotation` classes
- And [much more](https://github.com/psalm/psalm-plugin-symfony/tree/master/tests/acceptance/acceptance)!

### Configuration

If you follow the installation instructions, the psalm-plugin command will add this plugin configuration to the `psalm.xml` configuration file.

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
`containerXml` must be provided.
Example:

```xml
<pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
    <containerXml>var/cache/dev/App_KernelDevDebugContainer.xml</containerXml>
</pluginClass>
```

This file path may change based on your Symfony version, file structure and environment settings.
Default files are:
- Symfony 3: `var/cache/dev/srcDevDebugProjectContainer.xml`
- Symfony 4: `var/cache/dev/srcApp_KernelDevDebugContainer.xml`
- Symfony 5+: `var/cache/dev/App_KernelDevDebugContainer.xml`

Multiple container files can be configured. In this case, the first valid file is taken into account.
If none of the given files is valid, a configuration exception is thrown.
Example:

```xml
<pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
    <containerXml>var/cache/dev/App_KernelDevDebugContainer.xml</containerXml>
    <containerXml>var/cache/dev/App_KernelTestDebugContainer.xml</containerXml>
</pluginClass>
```

If you're using PHP config files for Symfony 5.3+, you also need this for auto-loading of `Symfony\Config`:

```xml
<extraFiles>
    <directory name="var/cache/dev/Symfony/Config" /> <!-- https://github.com/psalm/psalm-plugin-symfony/issues/201 -->
</extraFiles>
```

If you're using Symfony's `env()` or `param()` functions in your PHP config files, you also need this for auto-loading them:

```xml
<stubs>
    <file name="vendor/symfony/dependency-injection/Loader/Configurator/ContainerConfigurator.php" />
</stubs>
```

If you're getting the following error

> MissingFile - config/preload.php - Cannot find file ...var/cache/prod/App_KernelProdContainer.preload.php to include

...you can suppress it like this:

```xml
<issueHandlers>
    <MissingFile> <!-- https://github.com/psalm/psalm-plugin-symfony/issues/205 -->
        <errorLevel type="suppress">
            <file name="config/preload.php" />
        </errorLevel>
    </MissingFile>
</issueHandlers>
```

### Twig tainting (experimental)

When it comes to taint analysis for Twig templates, there are currently two approaches:

 - The first one is based on a specific file analyzer (`Psalm\SymfonyPsalmPlugin\Twig\TemplateFileAnalyzer`) which leverages the Twig parser and visits the AST nodes.
 - The second one is based on the already compiled Twig templates, it only bridges calls from `Twig\Environment::render` to the actual `doRender` method of the compiled template.

#### Twig Analyzer

This approach is more robust since it relies on the official Twig parser and node visitor mechanisms.
For the moment, it is only able to detect simple tainted paths.

To leverage the real Twig file analyzer, you have to configure a checker for the `.twig` extension as follows:

```xml
<fileExtensions>
   <extension name=".php" />
   <extension name=".twig" checker="/vendor/psalm/plugin-symfony/src/Twig/TemplateFileAnalyzer.php"/>
</fileExtensions>
```

[See the currently supported cases.](https://github.com/psalm/psalm-plugin-symfony/blob/master/tests/acceptance/acceptance/TwigTaintingWithAnalyzer.feature)

#### Cache Analyzer

This approach is "dirtier", since it tries to connect the taints from the application code to the compiled PHP code representing a given template.
It is theoretically able to detect more taints than the previous approach out-of-the-box, but it still lacks ways to handle inheritance and stuff like that.

To allow the analysis through the cached template files, you have to add the `twigCachePath` entry to the plugin configuration :

```xml
<pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
    <twigCachePath>/cache/twig</twigCachePath>
</pluginClass>
```

[See the currently supported cases.](https://github.com/psalm/psalm-plugin-symfony/blob/master/tests/acceptance/acceptance/TwigTaintingWithCachedTemplates.feature)

### Credits

- Plugin created by [@seferov](https://github.com/seferov)
- [@weirdan](https://github.com/weirdan) for [codeception psalm module](https://github.com/weirdan/codeception-psalm-module)
