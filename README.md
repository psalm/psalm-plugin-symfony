# Symfony Psalm Plugin

![Integrate](https://github.com/psalm/psalm-plugin-symfony/workflows/Integrate/badge.svg)

### Installation

```
composer require --dev psalm/plugin-symfony
vendor/bin/psalm --init
vendor/bin/psalm-plugin enable psalm/plugin-symfony
```

### Versions & Dependencies

| Symfony Psalm Plugin | PHP        | Symfony    | Psalm   |
|----------------------|------------|------------|---------|
| ^5.10                | ^8.2       | 6, 7, 8    | 7       |
| <=5.9                | ^7.4, ^8.0 | 5, 6, 7, 8 | 5, 6, 7 |
| 4.x                  | ^7.4, ^8.0 | 4, 5, 6    | 4       |
| 3.x                  | ^7.1, ^8.0 | 4, 5, 6    | 4       |
| 2.x                  | ^7.1, ^8.0 | 4, 5       | 4       |
| 1.x                  | ^7.1       | 3, 4, 5    | 3       |

### Features

- Detects the `ContainerInterface::get()` result type. Works better if you [configure](#configuration) a compiled container XML file.
- Detects parameter return types from `ContainerInterface::getParameter()`.
- Supports [Service Subscribers](https://github.com/psalm/psalm-plugin-symfony/issues/20) from literal service maps and `#[SubscribedService]` getters. Dynamic maps use the compiled container XML.
- Infers locator service types from explicit `#[AutowireLocator]` maps, including `SubscribedService` entries, and checks unknown keys.
- Infers callable signatures from `#[AutowireCallable]`, `#[AutowireMethodOf]`, and `#[AutowireServiceClosure]`. Checks method targets and interface adapters.
- Detects return types from console arguments (`InputInterface::getArgument()`) and options (`InputInterface::getOption()`).
Enforces to use "InputArgument" and "InputOption" constants as a best practice.
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
  - public typed properties marked with `#[Required]` (setters marked with `#[Required]` are supported too). Reading such a property inside `__construct` is not reported, although it is only injected after construction.
- Psalter can fix `InvalidConsoleArgumentValue` and `InvalidConsoleOptionValue` (integer modes become `InputArgument`/`InputOption` constants) and `RepositoryStringShortcut` (class name strings become `Entity::class`), e.g. `vendor/bin/psalter --issues=RepositoryStringShortcut`.
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

#### Symfony version

By default, the plugin uses the `Kernel::MAJOR_VERSION` constant to determine your version of Symfony. However, this
might not be accurate if you have Psalm installed globally. You can set the version explicitly using
the `symfonyMajorVersion` configuration option:

```xml
<pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
    <symfonyMajorVersion>6</symfonyMajorVersion>
</pluginClass>
```

### Twig tainting (experimental)

When it comes to taint analysis for Twig templates, there are currently two approaches:

 - The first one is based on a specific file analyzer (`Psalm\SymfonyPsalmPlugin\Twig\TemplateFileAnalyzer`) which leverages the Twig parser and visits the AST nodes.
 - The second one is based on the already compiled Twig templates, it only bridges calls from `Twig\Environment::render` to the actual `doRender` method of the compiled template.

#### Twig Analyzer

This approach is more robust since it relies on the official Twig parser and node visitor mechanisms.
For the moment, it is only able to detect simple tainted paths.

To leverage the real Twig file analyzer, you have to configure a checker and a scanner for the `.twig` extension as follows:

```xml
<fileExtensions>
   <extension name=".php" />
   <extension name=".twig" checker="/vendor/psalm/plugin-symfony/src/Twig/TemplateFileAnalyzer.php" scanner="/vendor/psalm/plugin-symfony/src/Twig/TemplateFileScanner.php"/>
</fileExtensions>
```

Without the scanner, Psalm also parses the templates as PHP, and reports parse errors for those containing `<?php`.

The analyzer follows the calls of `Twig\Environment::render()`, `AbstractController::render()` and `AbstractController::renderView()`, and of the methods overriding them.
If your application renders templates through methods of its own, add them to the plugin configuration with the arguments (counted from 0, by default 0 and 1) naming the template and giving its variables.
The calls of the methods overriding them, and of those of the classes implementing them when they are of an interface, are followed as well:

```xml
<pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
    <twigRenderMethod>App\View\Renderer::render</twigRenderMethod>
    <twigRenderMethod templateArgument="1" contextArgument="2">App\View\Mailer::send</twigRenderMethod>
</pluginClass>
```

A method naming its template relative to a directory given in another argument, like `fetch(__DIR__, 'page.html.twig', $context)`, takes that argument as `templateDirectoryArgument`.
The directory must resolve to a literal path in the project: a string, `__DIR__`, a constant or a variable of a literal value, or a concatenation of them.
The template is then the one at that path, named from the template root or, outside of it, from the project:

```xml
<pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
    <twigRenderMethod templateArgument="1" contextArgument="2" templateDirectoryArgument="0">App\View\Templates::fetch</twigRenderMethod>
</pluginClass>
```

A constant whose value Psalm does not know, like one defined at run time, takes its value in the template names and directories from a `twigConstant` (or, for a value known only to another plugin, like a path in the analysed project, from `TwigUtils::addConstant()`):

```xml
<pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
    <twigConstant name="TEMPLATE_DIR">/views</twigConstant>
</pluginClass>
```

The filters, functions and tests a template calls, and the methods it calls on its variables (`item.name(...)`), go through their PHP callables as PHP calls do.
To find the callables of the filters, functions and tests, the analyzer needs the extensions declaring them: those the `twig` service of the `containerXml` adds, loaded by the `autoloader` of the Psalm configuration.
Twig gives the callable the arguments of the call, after those the `needs_charset`, `needs_environment` and `needs_context` options prepend and the parts of a name matching a pattern (`*_escape`), and with arguments given by name (on Twig 3.12 and later) in their places:

- each argument flows into the parameter of the callable taking it: the body of a callable of the project takes it, and a parameter annotated with `@psalm-taint-sink` (or a conditional sink, resolved with the literal arguments of the call) reports it;
- the context `needs_context` gives holds the variables the template is rendered with, those it reads from its context, and those it sets;
- what the callable returns comes out of the call: what its body returns, its `@psalm-flow` from its parameters and its keyed stores, its `@psalm-taint-source`, without the taints it escapes (`@psalm-taint-escape`, also conditionally, resolved with the literal arguments of the call) or its return type cannot hold;
- a callable annotated with `@psalm-taint-specialize` (or whose calls Psalm specializes, like a pure one) returns to each call only what that call gives it;
- the calls in conditions (`{% if x is test %}`, `{% if x|filter == 'a' %}`) and in `{% do %}` are followed too, though their values are not output.

A closure callable is found by its file and line, as is the body of a first-class callable (`$this->filter(...)`).
Where Psalm doesn't analyze the body of the callable (one of a library, of Twig itself, a builtin, a closure the analysis can't find), or the analysis can't tell which parameters the arguments go to, what the call returns also comes from all of its arguments, without the taints the callable says it escapes and its return type cannot hold: taints are not lost through a body that isn't followed.

Not followed yet: the arrow functions given to `map`, `filter`, `reduce` and `sort` (what the filter returns comes from the array and from what the arrow function reads, without going through it), `attribute()`, the custom escapers of the `escape` filter (escaping with any strategy removes all taints), and the taints `AddTaintsInterface` and `RemoveTaintsInterface` handlers add to or remove from a PHP call.
[See the cases of the PHP callables.](https://github.com/psalm/psalm-plugin-symfony/blob/5.x/tests/acceptance/acceptance/TwigCallableBodies.feature)

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
