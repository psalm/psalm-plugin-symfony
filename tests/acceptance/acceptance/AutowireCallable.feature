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

  Scenario: A method inherited from a parent class provides the signature
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

      abstract class BaseFormatter {
          public function format(string $value): string { return $value; }
      }
      final class Formatter extends BaseFormatter {}

      final class Consumer {
          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'format')]
              private Closure $format,
          ) {}

          public function run(): string {
              return ($this->format)(1);
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                  | Message                                   |
      | InvalidScalarArgument | Argument 1 expects string, but 1 provided |
    And I see no other errors

  Scenario: A trait method uses the visibility of its adaptation and the using class as self
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

      trait FormatterTrait {
          private function format(string $value): string { return $value; }
          public function withPrefix(string $prefix): self { return $this; }
          public function hidden(string $value): string { return $value; }
      }
      final class Formatter {
          use FormatterTrait {
              format as public;
              hidden as private;
          }
      }

      final class Consumer {
          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'format')]
              private Closure $format,
              #[AutowireCallable(service: Formatter::class, method: 'withPrefix')]
              private Closure $withPrefix,
              #[AutowireCallable(service: Formatter::class, method: 'hidden')]
              public Closure $hidden,
          ) {}

          public function run(): Formatter {
              ($this->format)('value');

              return ($this->withPrefix)('prefix');
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                    | Message                                  |
      | InvalidAutowireCallable | Method Formatter::hidden() is not public. |
    And I see no other errors
