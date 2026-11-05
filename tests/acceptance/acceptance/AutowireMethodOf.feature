@symfony-7 @symfony-8 @php-8
Feature: Autowired method adapters
  AutowireMethodOf exists since Symfony 7.1

  Background:
    Given I have Symfony plugin enabled

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

  Scenario: A method inherited from a parent interface provides the signature
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireMethodOf;

      interface UrlGenerator { public function generate(string $name): string; }
      interface Router extends UrlGenerator {}

      final class Consumer {
          public function __construct(
              #[AutowireMethodOf(Router::class)]
              private Closure $generate,
          ) {}

          public function home(): string {
              return ($this->generate)('home');
          }

          public function wrong(): string {
              return ($this->generate)(1);
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                  | Message                                   |
      | InvalidScalarArgument | Argument 1 expects string, but 1 provided |
    And I see no other errors
