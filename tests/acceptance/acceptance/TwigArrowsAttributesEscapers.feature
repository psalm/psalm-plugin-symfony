@symfony-common
Feature: Twig arrow functions, attribute(), escaping strategies and included contexts
  The arrow functions a template gives a filter are called as the filter calls them, `attribute()` calls the method it
  names, escaping removes only the taints its strategy encodes, and the PHP callables needing the context of an
  included template are given the context the including template gives it

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
        </plugins>
      </psalm>
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

      require_once __DIR__.'/TwigExtension.php';
      """
    And I have the following code in "TwigExtension.php"
      """
      <?php

      namespace App;

      use Twig\Extension\AbstractExtension;
      use Twig\TwigFilter;

      final class TwigExtension extends AbstractExtension
      {
          #[\Override]
          public function getFilters(): array
          {
              return [
                  new TwigFilter('app_sink', [self::class, 'appSink']),
                  new TwigFilter('app_shell', [self::class, 'appShell']),
                  new TwigFilter('app_apply', [self::class, 'appApply']),
                  new TwigFilter('app_from_context', [self::class, 'appFromContext'], ['needs_context' => true]),
              ];
          }

          public static function appSink(string $value): string
          {
              echo $value;

              return '';
          }

          /**
           * @psalm-taint-sink shell $value
           */
          public static function appShell(string $value): string
          {
              return $value;
          }

          /**
           * @param list<string>             $values
           * @param \Closure(string): string $arrow
           *
           * @return list<string>
           */
          public static function appApply(array $values, \Closure $arrow): array
          {
              return array_map($arrow, $values);
          }

          /**
           * @param array<string, mixed> $context
           */
          public static function appFromContext(array $context, string $key): string
          {
              $value = $context[$key] ?? '';

              return \is_string($value) ? $value : '';
          }
      }

      /**
       * @psalm-api
       */
      final class Helper
      {
          public function passThrough(string $value): string
          {
              return $value;
          }

          public function sink(string $value): string
          {
              echo $value;

              return '';
          }

          public function helperEscape(string $value): string
          {
              return htmlspecialchars($value, ENT_QUOTES);
          }
      }

      /**
       * @psalm-api
       */
      final class User
      {
          public function __construct(public string $name)
          {
          }
      }

      /**
       * @psalm-api
       */
      final class Escaper
      {
          public static function passThrough(string $value, string $charset): string
          {
              return $charset !== '' ? $value : '';
          }
      }
      """
    And I have the following code preamble
      """
      <?php

      use Twig\Environment;
      use Twig\Runtime\EscaperRuntime;

      /**
       * @psalm-suppress InvalidReturnType
       * @return Environment
       * @psalm-capabilities read-props
       */
      function twig() {}
      """

  Scenario Outline: A sink in the body of an arrow function is given the items of the array the filter calls it with
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['items' => [$_GET['untrusted']]]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ <expression> }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

    Examples:
      | expression                                               |
      | items\|map(x => x\|app_sink)\|join                       |
      | items\|map((x, k) => k ~ x\|app_sink)\|join              |
      | items\|filter(x => x\|app_sink)\|join                    |
      | items\|find(x => x\|app_sink)                            |
      | items\|sort((a, b) => (a\|app_sink) == b)\|join          |
      | items\|reduce((carry, x) => carry ~ x\|app_sink, '')     |
      | items has some x => x\|app_sink                          |
      | items has every x => x\|app_sink                         |
      | items\|app_apply(x => x\|app_sink)\|join                 |

  Scenario: The initial value of reduce is given to the first parameter of the arrow function
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['items' => ['a'], 'untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ items|reduce((carry, x) => carry|app_sink ~ x, untrusted) }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario Outline: What an arrow function returns of the items comes out of map and reduce
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['items' => [$_GET['untrusted']], 'users' => [new App\User(\is_string($_GET['untrusted']) ? $_GET['untrusted'] : '')]]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ <expression>|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

    Examples:
      | expression                                      |
      | items\|map(x => x)\|first                       |
      | items\|map(x => 'a' ~ x)\|join                  |
      | users\|map(u => u.name)\|first                  |
      | items\|reduce((carry, x) => carry ~ x, '')      |
      | items\|reduce((carry, x) => x)                  |
      | items\|filter(x => x == 'a')\|first             |
      | items\|sort((a, b) => a == b)\|first            |
      | items\|find(x => x == 'a')                      |
      | items\|app_apply(x => x)\|first                 |

  Scenario Outline: What an arrow function escapes does not come out of map and reduce
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['items' => [$_GET['untrusted']]]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ <expression>|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see no errors

    Examples:
      | expression                                      |
      | items\|map(x => x\|e)\|first                    |
      | items\|map(x => x\|e)\|join                     |
      | items\|reduce((carry, x) => carry ~ x\|e, '')   |
      | items\|map(x => 'a')\|first                     |

  Scenario Outline: attribute() with a literal name calls the method it names with its arguments
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted'], 'helper' => new App\Helper()]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ <expression> }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

    Examples:
      | expression                                          |
      | attribute(helper, 'passThrough', [untrusted])\|raw  |
      | attribute(helper, 'sink', [untrusted])              |

  Scenario: attribute() with a literal name does not give what the method it names escapes
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted'], 'helper' => new App\Helper()]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ attribute(helper, 'helperEscape', [untrusted])|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see no errors

  Scenario Outline: attribute() with a name only known when rendering gives any public property or method of the object
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['user' => new App\User(\is_string($_GET['untrusted']) ? $_GET['untrusted'] : ''), 'name' => \is_string($_GET['name']) ? $_GET['name'] : 'name']);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ <expression>|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

    Examples:
      | expression                |
      | attribute(user, name)     |
      | attribute(user, 'name')   |

  Scenario Outline: Escaping with a builtin strategy removes the taints of HTML and quotes
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|<filter>|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see no errors

    Examples:
      | filter                        |
      | e                             |
      | escape                        |
      | e('html')                     |
      | e(strategy = 'html')          |
      | e('js')                       |
      | e('css')                      |
      | e('url')                      |
      | e('html_attr')                |

  Scenario Outline: Escaping with a builtin strategy keeps the taints it does not encode
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ <expression>|app_shell }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type         | Message                     |
      | TaintedShell | Detected tainted shell code |
    And I see no other errors

    Examples:
      | expression              |
      | untrusted\|e            |
      | untrusted\|e('html')    |
      | untrusted\|e('js')      |
      | untrusted\|e('url')     |

  Scenario Outline: Escaping with a custom strategy goes through the PHP callable of its escaper
    Given I have the following code
      """
      twig()->getRuntime(EscaperRuntime::class)->setEscaper('csv', <escaper>);
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|e('csv')|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

    Examples:
      | escaper                                                              |
      | static fn (string $string): string => $string                         |
      | [App\Escaper::class, 'passThrough']                                  |
      | 'App\Escaper::passThrough'                                           |
      | App\Escaper::passThrough(...)                                        |

  Scenario: Escaping with a custom strategy whose escaper escapes is not reported
    Given I have the following code
      """
      twig()->getRuntime(EscaperRuntime::class)->setEscaper('safe', static fn (string $string): string => htmlspecialchars($string, ENT_QUOTES));
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|e('safe')|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see no errors

  Scenario Outline: Escaping with a strategy whose escaper the analysis can't find removes nothing
    Given I have the following code
      """
      twig()->getRuntime(EscaperRuntime::class)->setEscaper('safe', static fn (string $string): string => htmlspecialchars($string, ENT_QUOTES));
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted'], 'strategy' => 'safe']);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|<filter>|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

    Examples:
      | filter            |
      | e('unknown')      |
      | e(strategy)       |

  Scenario Outline: A filter needing the context in an included template is given the context of the including template
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <expression>
      """
    And I have the following "part.html.twig" template
      """
      {% block content %}<h1>{{ 'untrusted'|app_from_context|raw }}</h1>{% endblock %}
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

    Examples:
      | expression                                                                  |
      | {% include 'part.html.twig' %}                                              |
      | {{ include('part.html.twig') }}                                             |
      | {% embed 'part.html.twig' %}{% endembed %}                                  |
      | {% extends 'part.html.twig' %}                                              |
      | {% set local = untrusted %}{% include 'part.html.twig' %}                   |
      | {% include 'part.html.twig' with {other: untrusted} only %}                 |
      | {{ include('part.html.twig', {other: untrusted}, with_context = false) }}   |

  Scenario Outline: A filter needing the context in an included template is not given the context of the including template with only
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <expression>
      """
    And I have the following "part.html.twig" template
      """
      <h1>{{ 'untrusted'|app_from_context|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see no errors

    Examples:
      | expression                                                    |
      | {% include 'part.html.twig' only %}                           |
      | {% include 'part.html.twig' with {other: 'a'} only %}         |
      | {{ include('part.html.twig', with_context = false) }}         |
