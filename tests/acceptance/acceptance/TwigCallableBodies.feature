@symfony-common
Feature: Twig callable bodies
  A filter, a function or a test of a template calls its PHP callable as a PHP call does: its arguments go into the
  parameters of the callable, whose body takes them, and what the body returns comes out of the call

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

  Scenario Outline: A tainted parameter given to a filter, a function or a method whose body escapes it without saying so is not displayed with the raw filter
    Given I have the following code in "TwigExtension.php"
      """
      <?php

      namespace App;

      use Twig\Extension\AbstractExtension;
      use Twig\TwigFilter;
      use Twig\TwigFunction;

      final class TwigExtension extends AbstractExtension
      {
          #[\Override]
          public function getFilters(): array
          {
              return [new TwigFilter('app_escape', [self::class, 'appEscape'])];
          }

          #[\Override]
          public function getFunctions(): array
          {
              return [new TwigFunction('app_escape', [self::class, 'appEscape'])];
          }

          public static function appEscape(string $value): string
          {
              return htmlspecialchars($value, ENT_QUOTES);
          }
      }

      /**
       * @psalm-api
       */
      final class Helper
      {
          public function helperEscape(string $value): string
          {
              return htmlspecialchars($value, ENT_QUOTES);
          }
      }
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted'], 'helper' => new App\Helper()]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ <expression>|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see no errors

    Examples:
      | expression                    |
      | untrusted\|app_escape         |
      | app_escape(untrusted)         |
      | helper.helperEscape(untrusted) |

  Scenario Outline: A tainted parameter given to a filter, a function or a method whose body returns it is displayed with the raw filter
    Given I have the following code in "TwigExtension.php"
      """
      <?php

      namespace App;

      use Twig\Extension\AbstractExtension;
      use Twig\TwigFilter;
      use Twig\TwigFunction;

      final class TwigExtension extends AbstractExtension
      {
          #[\Override]
          public function getFilters(): array
          {
              return [new TwigFilter('app_pass', [self::class, 'appPass'])];
          }

          #[\Override]
          public function getFunctions(): array
          {
              return [new TwigFunction('app_pass', [self::class, 'appPass'])];
          }

          public static function appPass(string $value): string
          {
              $passed = trim($value);

              return $passed;
          }
      }

      /**
       * @psalm-api
       */
      final class Helper
      {
          public function helperPass(string $value): string
          {
              return trim($value);
          }
      }
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted'], 'helper' => new App\Helper()]);
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
      | expression                  |
      | untrusted\|app_pass         |
      | app_pass(untrusted)         |
      | helper.helperPass(untrusted) |

  Scenario Outline: A tainted parameter given to a filter, a function, a test or a method whose body outputs it is reported, though the call is not displayed
    Given I have the following code in "TwigExtension.php"
      """
      <?php

      namespace App;

      use Twig\Extension\AbstractExtension;
      use Twig\TwigFilter;
      use Twig\TwigFunction;
      use Twig\TwigTest;

      final class TwigExtension extends AbstractExtension
      {
          #[\Override]
          public function getFilters(): array
          {
              return [new TwigFilter('app_echo', [self::class, 'appEcho'])];
          }

          #[\Override]
          public function getFunctions(): array
          {
              return [new TwigFunction('app_echo', [self::class, 'appEcho'])];
          }

          #[\Override]
          public function getTests(): array
          {
              return [new TwigTest('app_echoed', [self::class, 'appEchoed'])];
          }

          public static function appEcho(string $value): string
          {
              echo $value;

              return '';
          }

          public static function appEchoed(string $value): bool
          {
              echo $value;

              return true;
          }
      }

      /**
       * @psalm-api
       */
      final class Helper
      {
          public function helperEcho(string $value): string
          {
              echo $value;

              return '';
          }
      }
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted'], 'helper' => new App\Helper()]);
      """
    And I have the following "index.html.twig" template
      """
      <template>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

    Examples:
      | template                                                      |
      | <h1>{{ untrusted\|app_echo }}</h1>                            |
      | <h1>{{ app_echo(untrusted) }}</h1>                            |
      | <h1>{{ helper.helperEcho(untrusted) }}</h1>                   |
      | {% if untrusted is app_echoed %}<h1>Shown</h1>{% endif %}     |
      | {% if (untrusted\|app_echo) == '' %}<h1>Shown</h1>{% endif %} |
      | <h1>{{ untrusted is app_echoed ? 'yes' : 'no' }}</h1>         |
      | {% do app_echo(untrusted) %}                                  |

  Scenario Outline: The value a filter or a function needing the charset, the environment or the context is given, and its arguments, go to the parameters following those Twig gives first, which do not return them
    Given I have the following code in "TwigExtension.php"
      """
      <?php

      namespace App;

      use Twig\Environment;
      use Twig\Extension\AbstractExtension;
      use Twig\TwigFilter;
      use Twig\TwigFunction;

      final class TwigExtension extends AbstractExtension
      {
          #[\Override]
          public function getFilters(): array
          {
              return [
                  new TwigFilter('app_charset', [self::class, 'appCharset'], ['needs_charset' => true]),
                  new TwigFilter('app_environment', [self::class, 'appEnvironment'], ['needs_environment' => true]),
                  new TwigFilter('app_context', [self::class, 'appContext'], ['needs_context' => true]),
                  new TwigFilter('app_all', [self::class, 'appAll'], ['needs_charset' => true, 'needs_environment' => true, 'needs_context' => true]),
              ];
          }

          #[\Override]
          public function getFunctions(): array
          {
              return [new TwigFunction('app_environment', [self::class, 'appEnvironmentFunction'], ['needs_environment' => true])];
          }

          public static function appCharset(string $charset, string $value, string $suffix): string
          {
              return '' === $charset || '' === $value ? '' : $suffix;
          }

          public static function appEnvironment(Environment $environment, string $value, string $suffix): string
          {
              return '' === $environment->getCharset() || '' === $value ? '' : $suffix;
          }

          /**
           * @param array<string, mixed> $context
           */
          public static function appContext(array $context, string $value, string $suffix): string
          {
              return [] === $context || '' === $value ? '' : $suffix;
          }

          /**
           * @param array<string, mixed> $context
           */
          public static function appAll(string $charset, Environment $environment, array $context, string $value, string $suffix): string
          {
              return '' === $charset || '' === $environment->getCharset() || [] === $context || '' === $value ? '' : $suffix;
          }

          public static function appEnvironmentFunction(Environment $environment, string $value, string $suffix): string
          {
              return '' === $environment->getCharset() || '' === $value ? '' : $suffix;
          }
      }
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ <call>|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see no errors

    Examples:
      | call                                                  |
      | untrusted\|app_charset('suffix')                      |
      | untrusted\|app_environment('suffix')                  |
      | untrusted\|app_context('suffix')                      |
      | untrusted\|app_all('suffix')                          |
      | app_environment(untrusted, 'suffix')                  |
      | app_environment(value = untrusted, suffix = 'suffix') |

  Scenario Outline: The arguments of a filter or a function needing the charset, the environment or the context go to the parameters following those Twig gives first, and the value it is given, which return them
    Given I have the following code in "TwigExtension.php"
      """
      <?php

      namespace App;

      use Twig\Environment;
      use Twig\Extension\AbstractExtension;
      use Twig\TwigFilter;
      use Twig\TwigFunction;

      final class TwigExtension extends AbstractExtension
      {
          #[\Override]
          public function getFilters(): array
          {
              return [
                  new TwigFilter('app_charset', [self::class, 'appCharset'], ['needs_charset' => true]),
                  new TwigFilter('app_environment', [self::class, 'appEnvironment'], ['needs_environment' => true]),
                  new TwigFilter('app_context', [self::class, 'appContext'], ['needs_context' => true]),
                  new TwigFilter('app_all', [self::class, 'appAll'], ['needs_charset' => true, 'needs_environment' => true, 'needs_context' => true]),
              ];
          }

          #[\Override]
          public function getFunctions(): array
          {
              return [new TwigFunction('app_environment', [self::class, 'appEnvironmentFunction'], ['needs_environment' => true])];
          }

          public static function appCharset(string $charset, string $value, string $suffix): string
          {
              return '' === $charset || '' === $value ? '' : $suffix;
          }

          public static function appEnvironment(Environment $environment, string $value, string $suffix): string
          {
              return '' === $environment->getCharset() || '' === $value ? '' : $suffix;
          }

          /**
           * @param array<string, mixed> $context
           */
          public static function appContext(array $context, string $value, string $suffix): string
          {
              return [] === $context || '' === $value ? '' : $suffix;
          }

          /**
           * @param array<string, mixed> $context
           */
          public static function appAll(string $charset, Environment $environment, array $context, string $value, string $suffix): string
          {
              return '' === $charset || '' === $environment->getCharset() || [] === $context || '' === $value ? '' : $suffix;
          }

          public static function appEnvironmentFunction(Environment $environment, string $value, string $suffix): string
          {
              return '' === $environment->getCharset() || '' === $value ? '' : $suffix;
          }
      }
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ <call>|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

    Examples:
      | call                                                 |
      | 'value'\|app_charset(untrusted)                      |
      | 'value'\|app_environment(untrusted)                  |
      | 'value'\|app_context(untrusted)                      |
      | 'value'\|app_all(untrusted)                          |
      | app_environment('value', untrusted)                  |
      | app_environment(suffix = untrusted, value = 'value') |

  Scenario: A tainted variable of the template read from its context by a filter needing it is displayed with the raw filter
    Given I have the following code in "TwigExtension.php"
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
              return [new TwigFilter('app_from_context', [self::class, 'appFromContext'], ['needs_context' => true])];
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
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ 'untrusted'|app_from_context|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario Outline: A tainted argument of a filter, a function or a method given to a sink of its body is reported
    Given I have the following code in "TwigExtension.php"
      """
      <?php

      namespace App;

      use Twig\Extension\AbstractExtension;
      use Twig\TwigFilter;
      use Twig\TwigFunction;

      final class TwigExtension extends AbstractExtension
      {
          #[\Override]
          public function getFilters(): array
          {
              return [new TwigFilter('app_log', [self::class, 'appLog'], ['is_safe' => ['html']])];
          }

          #[\Override]
          public function getFunctions(): array
          {
              return [new TwigFunction('app_log', [self::class, 'appLog'], ['is_safe' => ['html']])];
          }

          public static function appLog(string $value, string $message = '', string $file = 'app.log'): string
          {
              file_put_contents($file, $message);

              return htmlspecialchars($value, ENT_QUOTES);
          }
      }

      /**
       * @psalm-api
       */
      final class Helper
      {
          public function helperLog(string $value, string $message = '', string $file = 'app.log'): string
          {
              file_put_contents($file, $message);

              return htmlspecialchars($value, ENT_QUOTES);
          }
      }
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted'], 'helper' => new App\Helper()]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ <expression> }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message                        |
      | TaintedFile | Detected tainted file handling |
    And I see no other errors

    Examples:
      | expression                                  |
      | 'value'\|app_log('message', untrusted)      |
      | 'value'\|app_log(file = untrusted)          |
      | app_log('value', 'message', untrusted)      |
      | app_log('value', file = untrusted)          |
      | helper.helperLog('value', 'message', untrusted) |

  Scenario: A tainted value given to a filter or a function whose body only escapes it is not reported, though its other arguments reach a sink
    Given I have the following code in "TwigExtension.php"
      """
      <?php

      namespace App;

      use Twig\Extension\AbstractExtension;
      use Twig\TwigFilter;
      use Twig\TwigFunction;

      final class TwigExtension extends AbstractExtension
      {
          #[\Override]
          public function getFilters(): array
          {
              return [new TwigFilter('app_log', [self::class, 'appLog'], ['is_safe' => ['html']])];
          }

          #[\Override]
          public function getFunctions(): array
          {
              return [new TwigFunction('app_log', [self::class, 'appLog'], ['is_safe' => ['html']])];
          }

          public static function appLog(string $value, string $message = '', string $file = 'app.log'): string
          {
              file_put_contents($file, $message);

              return htmlspecialchars($value, ENT_QUOTES);
          }
      }
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|app_log('message') }}</h1>
      <h2>{{ app_log(untrusted, 'message') }}</h2>
      """
    When I run Psalm with taint analysis
    Then I see no errors

  Scenario: The calls of a filter whose PHP callable is specialized to its calls give each call what it is given only
    Given I have the following code in "TwigExtension.php"
      """
      <?php

      namespace App;

      use Twig\Extension\AbstractExtension;
      use Twig\TwigFilter;

      /**
       * @psalm-api
       */
      class TwigExtension extends AbstractExtension
      {
          public static int $calls = 0;

          #[\Override]
          public function getFilters(): array
          {
              return [new TwigFilter('app_count', [self::class, 'appCount'])];
          }

          /**
           * @psalm-taint-specialize
           */
          public static function appCount(string $value): string
          {
              ++self::$calls;

              return $value;
          }
      }
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted'], 'trusted' => 'trusted']);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|app_count }}</h1>
      <h2>{{ trusted|app_count|raw }}</h2>
      """
    When I run Psalm with taint analysis
    Then I see no errors

  Scenario: The calls of a filter whose PHP callable isn't specialized to its calls all give what one of them is given
    Given I have the following code in "TwigExtension.php"
      """
      <?php

      namespace App;

      use Twig\Extension\AbstractExtension;
      use Twig\TwigFilter;

      /**
       * @psalm-api
       */
      class TwigExtension extends AbstractExtension
      {
          public static int $calls = 0;

          #[\Override]
          public function getFilters(): array
          {
              return [new TwigFilter('app_count', [self::class, 'appCount'])];
          }

          public static function appCount(string $value): string
          {
              ++self::$calls;

              return $value;
          }
      }
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted'], 'trusted' => 'trusted']);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|app_count }}</h1>
      <h2>{{ trusted|app_count|raw }}</h2>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario Outline: A filter, a function or a method whose PHP callable escapes what it is given under a condition on its literal arguments escapes it if they meet it
    Given I have the following code in "TwigExtension.php"
      """
      <?php

      namespace App;

      use Twig\Extension\AbstractExtension;
      use Twig\TwigFilter;
      use Twig\TwigFunction;

      final class TwigExtension extends AbstractExtension
      {
          #[\Override]
          public function getFilters(): array
          {
              return [
                  new TwigFilter('app_encode', [self::class, 'appEncode']),
                  new TwigFilter('*_encoded', [self::class, 'appEncoded']),
              ];
          }

          #[\Override]
          public function getFunctions(): array
          {
              return [new TwigFunction('app_encode', [self::class, 'appEncode'])];
          }

          /**
           * @psalm-taint-escape ($strategy is 'html' ? 'html'|'has_quotes' : null)
           */
          public static function appEncode(string $value, string $strategy = 'html'): string
          {
              return 'html' === $strategy ? htmlspecialchars($value, ENT_QUOTES) : $value;
          }

          /**
           * @psalm-taint-escape ($strategy is 'html' ? 'html'|'has_quotes' : null)
           */
          public static function appEncoded(string $strategy, string $value): string
          {
              return 'html' === $strategy ? htmlspecialchars($value, ENT_QUOTES) : $value;
          }
      }

      /**
       * @psalm-api
       */
      final class Helper
      {
          /**
           * @psalm-taint-escape ($strategy is 'html' ? 'html'|'has_quotes' : null)
           */
          public function helperEncode(string $value, string $strategy = 'html'): string
          {
              return 'html' === $strategy ? htmlspecialchars($value, ENT_QUOTES) : $value;
          }
      }
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted'], 'strategy' => $_GET['strategy'], 'helper' => new App\Helper()]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ <expression>|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see no errors

    Examples:
      | expression                               |
      | untrusted\|app_encode                    |
      | untrusted\|app_encode('html')            |
      | untrusted\|html_encoded                  |
      | app_encode(untrusted)                    |
      | app_encode(untrusted, 'html')            |
      | helper.helperEncode(untrusted)           |
      | helper.helperEncode(untrusted, 'html')   |

  Scenario Outline: A filter, a function or a method whose PHP callable escapes what it is given under a condition on its arguments does not escape it if they do not meet it, or are not literals
    Given I have the following code in "TwigExtension.php"
      """
      <?php

      namespace App;

      use Twig\Extension\AbstractExtension;
      use Twig\TwigFilter;
      use Twig\TwigFunction;

      final class TwigExtension extends AbstractExtension
      {
          #[\Override]
          public function getFilters(): array
          {
              return [
                  new TwigFilter('app_encode', [self::class, 'appEncode']),
                  new TwigFilter('*_encoded', [self::class, 'appEncoded']),
              ];
          }

          #[\Override]
          public function getFunctions(): array
          {
              return [new TwigFunction('app_encode', [self::class, 'appEncode'])];
          }

          /**
           * @psalm-taint-escape ($strategy is 'html' ? 'html'|'has_quotes' : null)
           */
          public static function appEncode(string $value, string $strategy = 'html'): string
          {
              return 'html' === $strategy ? htmlspecialchars($value, ENT_QUOTES) : $value;
          }

          /**
           * @psalm-taint-escape ($strategy is 'html' ? 'html'|'has_quotes' : null)
           */
          public static function appEncoded(string $strategy, string $value): string
          {
              return 'html' === $strategy ? htmlspecialchars($value, ENT_QUOTES) : $value;
          }
      }

      /**
       * @psalm-api
       */
      final class Helper
      {
          /**
           * @psalm-taint-escape ($strategy is 'html' ? 'html'|'has_quotes' : null)
           */
          public function helperEncode(string $value, string $strategy = 'html'): string
          {
              return 'html' === $strategy ? htmlspecialchars($value, ENT_QUOTES) : $value;
          }
      }
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted'], 'strategy' => $_GET['strategy'], 'helper' => new App\Helper()]);
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
      | expression                               |
      | untrusted\|app_encode('url')             |
      | untrusted\|app_encode(strategy)          |
      | untrusted\|url_encoded                   |
      | app_encode(untrusted, 'url')             |
      | app_encode(untrusted, strategy)          |
      | helper.helperEncode(untrusted, 'url')    |
      | helper.helperEncode(untrusted, strategy) |

  Scenario Outline: A parameter whose sink is conditional on a literal argument of a filter is a sink of the taints the condition gives
    Given I have the following code in "TwigExtension.php"
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
              return [new TwigFilter('app_run', [self::class, 'appRun'])];
          }

          /**
           * @psalm-taint-sink ($kind is 'shell' ? 'shell' : 'sql') $command
           */
          public static function appRun(string $command, string $kind): string
          {
              return '' === $command ? '' : $kind;
          }
      }
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|app_run('<kind>') }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type     | Message   |
      | <type>   | <message> |
      | <type>   | <message> |
    And I see no other errors

    Examples:
      | kind  | type         | message                     |
      | shell | TaintedShell | Detected tainted shell code |
      | sql   | TaintedSql   | Detected tainted SQL        |

  Scenario Outline: A tainted parameter given to a filter or a function whose PHP callable is not analyzed is displayed with the raw filter
    Given I have the following code in "TwigExtension.php"
      """
      <?php

      namespace App;

      use Lib\Library;
      use Twig\Extension\AbstractExtension;
      use Twig\TwigFilter;
      use Twig\TwigFunction;

      require_once __DIR__.'/Library.php';

      final class TwigExtension extends AbstractExtension
      {
          #[\Override]
          public function getFilters(): array
          {
              return [
                  new TwigFilter('lib_pass', [Library::class, 'pass']),
                  new TwigFilter('lib_escape', [Library::class, 'escape']),
              ];
          }

          #[\Override]
          public function getFunctions(): array
          {
              return [
                  new TwigFunction('lib_pass', [Library::class, 'pass']),
                  new TwigFunction('lib_escape', [Library::class, 'escape']),
              ];
          }
      }
      """
    And I have the following code in "Library.php"
      """
      <?php

      namespace Lib;

      final class Library
      {
          public static function pass(string $value): string
          {
              return $value;
          }

          /**
           * @psalm-taint-escape html
           * @psalm-taint-escape has_quotes
           */
          public static function escape(string $value): string
          {
              return htmlspecialchars($value, ENT_QUOTES);
          }
      }
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
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
      | expression          |
      | untrusted\|lib_pass |
      | lib_pass(untrusted) |

  Scenario Outline: A tainted parameter given to a filter or a function whose PHP callable is not analyzed is not displayed with the raw filter if the callable says it escapes it
    Given I have the following code in "TwigExtension.php"
      """
      <?php

      namespace App;

      use Lib\Library;
      use Twig\Extension\AbstractExtension;
      use Twig\TwigFilter;
      use Twig\TwigFunction;

      require_once __DIR__.'/Library.php';

      final class TwigExtension extends AbstractExtension
      {
          #[\Override]
          public function getFilters(): array
          {
              return [
                  new TwigFilter('lib_pass', [Library::class, 'pass']),
                  new TwigFilter('lib_escape', [Library::class, 'escape']),
              ];
          }

          #[\Override]
          public function getFunctions(): array
          {
              return [
                  new TwigFunction('lib_pass', [Library::class, 'pass']),
                  new TwigFunction('lib_escape', [Library::class, 'escape']),
              ];
          }
      }
      """
    And I have the following code in "Library.php"
      """
      <?php

      namespace Lib;

      final class Library
      {
          public static function pass(string $value): string
          {
              return $value;
          }

          /**
           * @psalm-taint-escape html
           * @psalm-taint-escape has_quotes
           */
          public static function escape(string $value): string
          {
              return htmlspecialchars($value, ENT_QUOTES);
          }
      }
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ <expression>|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see no errors

    Examples:
      | expression            |
      | untrusted\|lib_escape |
      | lib_escape(untrusted) |

  Scenario Outline: A tainted parameter given to a closure filter or function whose body escapes it is not displayed with the raw filter
    Given I have the following code in "TwigExtension.php"
      """
      <?php

      namespace App;

      use Twig\Extension\AbstractExtension;
      use Twig\TwigFilter;
      use Twig\TwigFunction;

      final class TwigExtension extends AbstractExtension
      {
          #[\Override]
          public function getFilters(): array
          {
              return [
                  new TwigFilter('app_escape', static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES)),
                  new TwigFilter('app_pass', static fn (string $value): string => trim($value)),
              ];
          }

          #[\Override]
          public function getFunctions(): array
          {
              return [
                  new TwigFunction('app_escape', static function (string $value): string {
                      return htmlspecialchars($value, ENT_QUOTES);
                  }),
                  new TwigFunction('app_echo', static function (string $value): string {
                      echo $value;

                      return '';
                  }),
              ];
          }
      }
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ <expression>|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see no errors

    Examples:
      | expression            |
      | untrusted\|app_escape |
      | app_escape(untrusted) |

  Scenario Outline: A tainted parameter given to a closure filter or function returning or outputting it is reported
    Given I have the following code in "TwigExtension.php"
      """
      <?php

      namespace App;

      use Twig\Extension\AbstractExtension;
      use Twig\TwigFilter;
      use Twig\TwigFunction;

      final class TwigExtension extends AbstractExtension
      {
          #[\Override]
          public function getFilters(): array
          {
              return [
                  new TwigFilter('app_escape', static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES)),
                  new TwigFilter('app_pass', static fn (string $value): string => trim($value)),
              ];
          }

          #[\Override]
          public function getFunctions(): array
          {
              return [
                  new TwigFunction('app_escape', static function (string $value): string {
                      return htmlspecialchars($value, ENT_QUOTES);
                  }),
                  new TwigFunction('app_echo', static function (string $value): string {
                      echo $value;

                      return '';
                  }),
              ];
          }
      }
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
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
      | expression               |
      | untrusted\|app_pass\|raw |
      | app_echo(untrusted)      |

  Scenario Outline: The arguments of a variadic filter go to the variadic parameter of its PHP callable
    Given I have the following code in "TwigExtension.php"
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
                  new TwigFilter('app_join', [self::class, 'appJoin'], ['is_variadic' => true]),
                  new TwigFilter('app_join_array', [self::class, 'appJoinArray'], ['is_variadic' => true]),
                  new TwigFilter('app_first', [self::class, 'appFirst'], ['is_variadic' => true]),
              ];
          }

          public static function appJoin(string $value, string ...$parts): string
          {
              return $value.implode('', $parts);
          }

          /**
           * @param array<string> $parts
           */
          public static function appJoinArray(string $value, array $parts = []): string
          {
              return $value.implode('', $parts);
          }

          public static function appFirst(string $value, string ...$parts): string
          {
              return '' === implode('', $parts) ? '' : $value;
          }
      }
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
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
      | expression                                   |
      | 'value'\|app_join('part', untrusted)         |
      | 'value'\|app_join_array('part', untrusted)   |
      | 'value'\|app_join_array(other = untrusted)   |
      | untrusted\|app_first('part')                 |

  Scenario: The arguments of a variadic filter whose PHP callable does not return them are not displayed
    Given I have the following code in "TwigExtension.php"
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
              return [new TwigFilter('app_first', [self::class, 'appFirst'], ['is_variadic' => true])];
          }

          public static function appFirst(string $value, string ...$parts): string
          {
              return '' === implode('', $parts) ? '' : $value;
          }
      }
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ 'value'|app_first('part', untrusted)|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see no errors
