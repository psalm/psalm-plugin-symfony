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

  Scenario: A property written elsewhere keeps its declared type
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

      final class Formatter {
          public function format(string $value): string { return $value; }
      }

      final class Wither {
          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'format')]
              private Closure $format,
          ) {}

          public function withFormat(Closure $format): self {
              $clone = clone $this;
              $clone->format = $format;

              return $clone;
          }

          public function run(string $value): mixed {
              return ($this->format)($value);
          }
      }

      final class Setter {
          private Closure $format;

          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'format')]
              Closure $format,
          ) {
              $this->format = $format;
          }

          public function setFormat(Closure $format): void {
              $this->format = $format;
          }

          public function run(string $value): mixed {
              return ($this->format)($value);
          }
      }

      trait FormatWither {
          public function withFormat(Closure $format): static {
              $clone = clone $this;
              $clone->format = $format;

              return $clone;
          }
      }

      final class TraitWither {
          use FormatWither;

          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'format')]
              private Closure $format,
          ) {}

          public function run(string $value): mixed {
              return ($this->format)($value);
          }
      }

      final class PublicProperty {
          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'format')]
              public Closure $format,
          ) {}
      }

      function replace(PublicProperty $consumer, Closure $format): void {
          $consumer->format = $format;
      }

      class ProtectedProperty {
          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'format')]
              protected Closure $format,
          ) {}
      }

      final class ChildProperty extends ProtectedProperty {
          public function setFormat(Closure $format): void {
              $this->format = $format;
          }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: A property given to a by-reference parameter keeps its declared type
    Given I have the following code
      """
      <?php
      namespace App;

      use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

      final class Formatter {
          public function format(string $value): string { return $value; }
      }

      /** @param-out \Closure $format */
      function replace(\Closure &$format): void {
          $format = static fn (int $value): int => $value;
      }

      final class Replacer {
          /** @param-out \Closure $format */
          public function __construct(\Closure &$format) {
              $format = static fn (int $value): int => $value;
          }
      }

      final class StaticReference {
          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'format')]
              private \Closure $format,
          ) {}

          public function swap(): void {
              self::replace($this->format);
          }

          /** @param-out \Closure $format */
          private static function replace(\Closure &$format): void {
              $format = static fn (int $value): int => $value;
          }

          public function run(int $value): mixed {
              return ($this->format)($value);
          }
      }

      final class MethodReference {
          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'format')]
              private \Closure $format,
          ) {}

          public function swap(): void {
              $this->replace(format: $this->format);
          }

          /** @param-out \Closure $format */
          private function replace(\Closure &$format): void {
              $format = static fn (int $value): int => $value;
          }

          public function run(int $value): mixed {
              return ($this->format)($value);
          }
      }

      final class FunctionReference {
          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'format')]
              private \Closure $format,
          ) {}

          public function swap(): void {
              replace($this->format);
          }

          public function run(int $value): mixed {
              return ($this->format)($value);
          }
      }

      final class ConstructorReference {
          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'format')]
              private \Closure $format,
          ) {}

          public function swap(): void {
              new Replacer($this->format);
          }

          public function run(int $value): mixed {
              return ($this->format)($value);
          }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: A property given to a parameter taken by value keeps the inferred signature
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

      final class Formatter {
          public function format(string $value): string { return $value; }
      }

      final class Consumer {
          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'format')]
              private Closure $format,
          ) {}

          /**
           * @param list<string> $values
           * @return list<mixed>
           */
          public function formatAll(array $values): array {
              return array_map($this->format, $values);
          }

          public function run(int $value): string {
              return ($this->format)($value);
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                  | Message                                                      |
      | InvalidScalarArgument | Argument 1 expects string, but int provided                  |
    And I see no other errors

  Scenario: A property written by a clone with properties keeps its declared type
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm errorLevel="1" findUnusedCode="false" phpVersion="8.5">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>
        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin"/>
        </plugins>
      </psalm>
      """
    And I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

      final class Formatter {
          public function format(string $value): string { return $value; }
      }

      final class Consumer {
          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'format')]
              private readonly Closure $format,
          ) {}

          public function withLength(): self {
              return clone($this, ['format' => static fn (string $value): int => \strlen($value)]);
          }

          public function run(string $value): mixed {
              return ($this->format)($value);
          }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: A property that only the attribute writes keeps the inferred signature
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

      final class Formatter {
          public function format(string $value): string { return $value; }
      }

      class PrivateProperty {
          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'format')]
              private Closure $format,
          ) {}

          public function run(): string {
              return ($this->format)(1);
          }
      }

      final class ChildOfPrivateProperty extends PrivateProperty {}

      final class ReadonlyProperty {
          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'format')]
              public readonly Closure $format,
          ) {}
      }

      function run(ReadonlyProperty $consumer): string {
          return ($consumer->format)(2);
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                  | Message                                   |
      | InvalidScalarArgument | Argument 1 expects string, but 1 provided |
      | InvalidScalarArgument | Argument 1 expects string, but 2 provided |
    And I see no other errors

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

  Scenario: Invalid bindings honour the suppressions of the method and of the class
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm errorLevel="1" findUnusedCode="false" findUnusedPsalmSuppress="true">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>
        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin"/>
        </plugins>
      </psalm>
      """
    And I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

      final class Formatter {
          public function format(string $value): string { return $value; }
          private function hidden(string $value): string { return $value; }
      }
      interface Converter { public function convert(int $value): int; }

      final class MethodSuppression {
          /** @psalm-suppress InvalidAutowireCallable */
          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'missing')]
              public Closure $missing,
              #[AutowireCallable(service: Formatter::class, method: 'hidden')]
              public Closure $hidden,
              #[AutowireCallable(callable: [Formatter::class, 'format'])]
              public Closure $notStatic,
          ) {}
      }

      /** @psalm-suppress InvalidAutowireCallable */
      final class ClassSuppression {
          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'missing')]
              public Closure $missing,
              #[AutowireCallable(service: Formatter::class, method: 'hidden')]
              public Closure $hidden,
              #[AutowireCallable(callable: [Formatter::class, 'format'])]
              public Closure $notStatic,
              #[AutowireCallable(service: Formatter::class, method: 'format')]
              public Converter $incompatible,
          ) {}
      }

      final class NoSuppression {
          public function __construct(
              #[AutowireCallable(service: Formatter::class, method: 'missing')]
              public Closure $missing,
          ) {}
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                    | Message                                    |
      | InvalidAutowireCallable | Method Formatter::missing() does not exist. |
    And I see no other errors

  Scenario: A static return type is the target class
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

      final class Prefixer {
          public function withPrefix(string $prefix): static { return $this; }
      }

      final class Consumer {
          public function __construct(
              #[AutowireCallable(service: Prefixer::class, method: 'withPrefix')]
              private Closure $withPrefix,
          ) {}

          public function run(): Prefixer {
              return ($this->withPrefix)('prefix');
          }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: A static parameter is the target class when it is final
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

      final class Money {
          /** @param static $other */
          public function add(self $other): static { return $other; }
      }

      final class Consumer {
          public function __construct(
              #[AutowireCallable(service: Money::class, method: 'add')]
              private Closure $add,
          ) {}

          public function run(): Money {
              return ($this->add)(new stdClass());
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type            | Message                                                 |
      | InvalidArgument | Argument 1 expects Money, but stdClass provided |
    And I see no other errors

  Scenario Outline: A static parameter of a class that is not final leaves the signature as declared
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

      class Money {
          /** @param <type> $other */
          public function equals($other): bool { return true; }
      }
      final class Euro extends Money {}

      final class Consumer {
          public function __construct(
              #[AutowireCallable(service: Money::class, method: 'equals')]
              private Closure $equals,
          ) {}

          public function run(): void {
              $equals = $this->equals;
              /** @psalm-trace $equals */
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                 |
      | Trace | /^\$equals: Closure(\[impure\])?$/ |
    And I see no other errors

    Examples:
      | type          |
      | static        |
      | ?static       |
      | list<static>  |

  Scenario: A static nested in the return type of a class that is not final leaves the signature as declared
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

      class Money {
          /** @return Closure(static):bool */
          public function equalTo(): Closure { return static fn (self $other): bool => true; }
      }
      final class Euro extends Money {}

      final class Consumer {
          public function __construct(
              #[AutowireCallable(service: Money::class, method: 'equalTo')]
              private Closure $equalTo,
          ) {}

          public function run(): void {
              $equalTo = $this->equalTo;
              /** @psalm-trace $equalTo */
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                              |
      | Trace | /^\$equalTo: Closure(\[impure\])?$/ |
    And I see no other errors
