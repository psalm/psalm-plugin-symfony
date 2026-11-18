@symfony-common
Feature: Twig taint handlers
  The plugins adding and removing the taints of calls (AddTaintsInterface and RemoveTaintsInterface) are given the PHP
  call a filter, a function, a test or a method of a template makes, as for a PHP call

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true" autoloader="autoload.php">
        <projectFiles>
          <directory name="."/>
          <directory name="templates"/>
          <ignoreFiles allowMissingFiles="true">
            <directory name="../../vendor" />
            <directory name="./cache" />
            <file name="./Library.php" />
            <file name="./TaintHandler.php" />
          </ignoreFiles>
        </projectFiles>
        <fileExtensions>
           <extension name=".php" />
           <extension name=".twig" checker="../../src/Twig/TemplateFileAnalyzer.php" scanner="../../src/Twig/TemplateFileScanner.php"/>
        </fileExtensions>
        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
            <containerXml>container.xml</containerXml>
          </pluginClass>
          <plugin filename="TaintHandler.php" />
        </plugins>
      </psalm>
      """
    And I have the following code in "TaintHandler.php"
      """
      <?php

      use PhpParser\Node\Expr;
      use PhpParser\Node\Identifier;
      use PhpParser\Node\Name;
      use Psalm\Plugin\EventHandler\AddTaintsInterface;
      use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
      use Psalm\Plugin\EventHandler\RemoveTaintsInterface;
      use Psalm\Type\TaintKind;

      /**
       * The calls of a function or a method named clean... escape the html they return, those named dirty... add it
       */
      final class TaintHandler implements AddTaintsInterface, RemoveTaintsInterface
      {
          public static function addTaints(AddRemoveTaintsEvent $event): int
          {
              return str_starts_with(self::getCalledName($event), 'dirty') ? TaintKind::INPUT_HTML : 0;
          }

          public static function removeTaints(AddRemoveTaintsEvent $event): int
          {
              return str_starts_with(self::getCalledName($event), 'clean') ? TaintKind::INPUT_HTML | TaintKind::INPUT_HAS_QUOTES : 0;
          }

          private static function getCalledName(AddRemoveTaintsEvent $event): string
          {
              $expr = $event->getExpr();

              return match (true) {
                  $expr instanceof Expr\FuncCall && $expr->name instanceof Name => $expr->name->getLast(),
                  ($expr instanceof Expr\StaticCall || $expr instanceof Expr\MethodCall) && $expr->name instanceof Identifier => $expr->name->name,
                  default => '',
              };
          }
      }
      """
    And I have the following code in "container.xml"
      """
      <?xml version="1.0" encoding="utf-8"?>
      <container xmlns="http://symfony.com/schema/dic/services">
        <services>
          <service id="twig" class="Twig\Environment" public="true">
            <call method="addExtension"><argument type="service" id="app.twig_extension"/></call>
          </service>
          <service id="app.twig_extension" class="App\TwigExtension"/>
        </services>
      </container>
      """
    And I have the following code in "autoload.php"
      """
      <?php

      require_once __DIR__.'/Library.php';
      require_once __DIR__.'/TwigExtension.php';
      """
    And I have the following code in "Library.php"
      """
      <?php

      namespace Library;

      final class Library
      {
          public static function cleanLibrary(string $value): string
          {
              return $value;
          }

          public static function library(string $value): string
          {
              return $value;
          }
      }
      """
    And I have the following code in "TwigExtension.php"
      """
      <?php

      namespace App;

      use Library\Library;
      use Twig\Extension\AbstractExtension;
      use Twig\TwigFilter;
      use Twig\TwigFunction;

      function cleanFunction(string $value): string
      {
          return $value;
      }

      final class TwigExtension extends AbstractExtension
      {
          #[\Override]
          public function getFilters(): array
          {
              return [
                  new TwigFilter('app_clean_static', [self::class, 'cleanStatic']),
                  new TwigFilter('app_clean_method', [$this, 'cleanMethod']),
                  new TwigFilter('app_clean_function', 'App\cleanFunction'),
                  new TwigFilter('app_clean_library', [Library::class, 'cleanLibrary']),
                  new TwigFilter('app_library', [Library::class, 'library']),
                  new TwigFilter('app_echo', [self::class, 'echo']),
                  new TwigFilter('app_dirty', [self::class, 'dirtyEscape']),
                  new TwigFilter('app_htmlspecialchars', 'htmlspecialchars'),
              ];
          }

          #[\Override]
          public function getFunctions(): array
          {
              return [new TwigFunction('app_clean', [self::class, 'cleanStatic'])];
          }

          public static function cleanStatic(string $value): string
          {
              return $value;
          }

          public function cleanMethod(string $value): string
          {
              return $value;
          }

          public static function echo(string $value): string
          {
              return $value;
          }

          public static function dirtyEscape(string $value): string
          {
              return htmlspecialchars($value, ENT_QUOTES);
          }
      }

      /**
       * @psalm-api
       */
      final class Helper
      {
          public function cleanHelper(string $value): string
          {
              return $value;
          }

          public function helper(string $value): string
          {
              return $value;
          }
      }
      """
    And I have the following code preamble
      """
      <?php

      use Twig\Environment;

      /**
       * @psalm-suppress InvalidReturnType
       * @return Environment
       * @psalm-capabilities read-props
       */
      function twig() {}
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted'], 'helper' => new App\Helper()]);
      """

  Scenario Outline: A tainted value given to a filter, a function or a method whose PHP call a plugin says escapes it is not displayed with the raw filter
    Given I have the following "index.html.twig" template
      """
      <h1>{{ <expression>|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see no errors

    Examples:
      | expression                         |
      | untrusted\|app_clean_static        |
      | untrusted\|app_clean_method        |
      | untrusted\|app_clean_function      |
      | untrusted\|app_clean_library       |
      | app_clean(untrusted)               |
      | helper.cleanHelper(untrusted)      |
      | untrusted\|app_htmlspecialchars    |

  Scenario Outline: A tainted value given to a filter or a method whose PHP call no plugin escapes is displayed with the raw filter
    Given I have the following "index.html.twig" template
      """
      <h1>{{ <expression>|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                             |
      | TaintedHtml           | Detected tainted HTML                               |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes          |
    And I see no other errors

    Examples:
      | expression                    |
      | untrusted\|app_echo           |
      | untrusted\|app_library        |
      | helper.helper(untrusted)      |
      | untrusted\|json_encode        |

  Scenario: A tainted value given to a filter whose body escapes it is displayed with the raw filter if a plugin says its PHP call adds the taint back
    Given I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|app_dirty|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message               |
      | TaintedHtml | Detected tainted HTML |
    And I see no other errors

  Scenario: A tainted value given to the json_encode filter with the flags escaping tags and ampersands has no html, as Psalm's handler of json_encode() says
    Given I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|json_encode(3)|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors
