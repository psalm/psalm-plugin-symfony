@symfony-common @php-8
Feature: Autowired callables

  Background:
    Given I have Symfony plugin enabled

  Scenario: Literal callables preserve optional and variadic parameters
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

      final class Helpers {
          public function __invoke(int $value): bool { return $value > 0; }
          public static function repeat(string $value, int $times = 1): string {
              return str_repeat($value, $times);
          }
      }
      function joinValues(string ...$values): string { return implode('', $values); }

      final class Consumer {
          public function __construct(
              #[AutowireCallable(service: Helpers::class)]
              private Closure $invoke,
              #[AutowireCallable(callable: [Helpers::class, 'repeat'])]
              private Closure $repeat,
              #[AutowireCallable(callable: Helpers::class.'::repeat')]
              private Closure $staticString,
              #[AutowireCallable(callable: 'joinValues')]
              private Closure $join,
              #[AutowireCallable(callable: 'strlen')]
              private Closure $length,
          ) {}

          public function run(string $value): string {
              if (($this->invoke)(1)) {
                  return ($this->repeat)($value, ($this->length)($value));
              }
              return ($this->staticString)(($this->join)($value, '!'));
          }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Service IDs are resolved from the container
    Given I have the following code in "container.xml"
      """
      <?xml version="1.0" encoding="utf-8"?>
      <container xmlns="http://symfony.com/schema/dic/services">
        <services>
          <service id="formatter.alias" class="Formatter" />
        </services>
      </container>
      """
    And I have Symfony plugin enabled with the following config
      """
      <containerXml>container.xml</containerXml>
      """
    And I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;
      use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

      final class Formatter {
          public function format(string $value): string { return $value; }
      }
      final class Consumer {
          public function __construct(
              #[AutowireCallable(service: 'formatter.alias', method: 'format')]
              private Closure $format,
              #[AutowireServiceClosure('formatter.alias')]
              private Closure $formatter,
          ) {}

          public function run(string $value): string {
              return ($this->formatter)()->format(($this->format)($value));
          }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: A compatible interface can use another method name
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireMethodOf;

      interface Formatter { public function format(string $value): string; }
      interface Converter { public function convert(string $value): string; }

      final class Consumer {
          public function __construct(
              #[AutowireMethodOf(Formatter::class)]
              private Converter $format,
          ) {}

          public function run(string $value): string {
              return $this->format->convert($value);
          }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: A bound method is marked as used
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireMethodOf;

      final class Formatter {
          public function format(string $value): string { return $value; }
      }
      final class Consumer {
          public function __construct(
              #[AutowireMethodOf(Formatter::class)]
              private Closure $format,
          ) {}

          public function run(string $value): string {
              return ($this->format)($value);
          }
      }
      $consumer = new Consumer(static fn (string $value): string => $value);
      echo $consumer->run('hello');
      """
    When I run Psalm with dead code detection
    Then I see no errors

  Scenario: A method attribute provides the promoted closure signature
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireMethodOf;

      interface Formatter {
          public function format(string $value): string;
      }

      final class Consumer {
          public function __construct(
              #[AutowireMethodOf(Formatter::class)]
              private Closure $format,
          ) {}

          public function run(string $value): string {
              return ($this->format)($value);
          }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: An incompatible interface adapter is rejected
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireMethodOf;

      interface Formatter { public function format(string $value): string; }
      interface Converter { public function convert(int $value): int; }

      final class Consumer {
          public function __construct(
              #[AutowireMethodOf(Formatter::class)]
              public Converter $format,
          ) {}
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                   | Message                                                              |
      | InvalidAutowireCallable | Callable signature is incompatible with Converter::convert().         |
    And I see no other errors

  Scenario: A missing target method is rejected
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireMethodOf;

      final class Formatter {}
      final class Consumer {
          public function __construct(
              #[AutowireMethodOf(Formatter::class)]
              public Closure $missing,
          ) {}
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                    | Message                                         |
      | InvalidAutowireCallable  | Method Formatter::missing() does not exist.      |
    And I see no other errors

  Scenario: Direct constructor calls must accept the inferred signature
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireMethodOf;

      interface Formatter { public function format(string $value): string; }
      final class Consumer {
          public function __construct(
              #[AutowireMethodOf(Formatter::class)]
              public Closure $format,
          ) {}
      }
      new Consumer(static fn (int $value): int => $value);
      """
    When I run Psalm
    Then I see these errors
      | Type            | Message     |
      | InvalidScalarArgument | /Consumer/  |
    And I see no other errors

  Scenario: Generic callables keep their declared types
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireMethodOf;

      /** @template T */
      interface GenericFormatter {
          /**
           * @param T $value
           * @return T
           */
          public function format($value);
      }
      final class Helpers {
          /**
           * @template T
           * @param T $value
           * @return T
           */
          public function identity($value) { return $value; }
      }
      final class Consumer {
          public function __construct(
              #[AutowireMethodOf(Helpers::class)]
              private Closure $identity,
              #[AutowireMethodOf(GenericFormatter::class)]
              private Closure $format,
          ) {}

          public function run(string $value): void {
              ($this->identity)($value);
              ($this->format)($value);
          }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: A callable attribute provides the parameter signature
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

      final class Formatter {
          public function format(string $value): string { return $value; }
      }

      final class Consumer {
          private Closure $format;

          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'format')]
              Closure $format,
          ) {
              $this->format = $format;
          }

          public function run(string $value): string {
              return ($this->format)($value);
          }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: A service closure returns the service type
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

      final class Formatter {
          public function format(string $value): string { return $value; }
      }

      final class Consumer {
          public function __construct(
              #[AutowireServiceClosure(Formatter::class)]
              private Closure $formatter,
          ) {}

          public function run(string $value): string {
              return ($this->formatter)()->format($value);
          }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Copied properties keep nullability
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

      final class Formatter {
          public function format(string $value): string { return $value; }
      }
      final class Consumer {
          private ?Closure $format;

          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'format')]
              Closure $format,
          ) { $this->format = $format; }

          public function clear(): void { $this->format = null; }
          public function run(string $value): ?string {
              return null === $this->format ? null : ($this->format)($value);
          }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Magic methods keep their declared types
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

      final class Formatter {
          /** @param list<mixed> $arguments */
          public function __call(string $name, array $arguments): string { return $name; }
      }
      final class Consumer {
          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'format', lazy: false)]
              public Closure $format,
          ) {}
      }
      """
    When I run Psalm
    Then I see no errors
