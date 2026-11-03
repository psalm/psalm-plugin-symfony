@symfony-common @php-8
Feature: Autowired locators

  Background:
    Given I have Symfony plugin enabled

  Scenario: Explicit maps preserve service types through copied values
    Given I have the following code
      """
      <?php
      use Psr\Container\ContainerInterface;
      use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

      interface Mailer {}
      interface Logger {}
      final class Consumer {
          private ContainerInterface $copied;

          public function __construct(
              #[AutowireLocator(['mail' => Mailer::class, Logger::class])]
              private ContainerInterface $services,
          ) {
              $copy = $services;
              $this->copied = $copy;
          }

          public function mailer(): Mailer { return $this->services->get('mail'); }
          public function copied(): Mailer { return $this->copied->get('mail'); }
          public function logger(): Logger { return $this->services->get(Logger::class); }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: SubscribedService entries override keys and declare optional services
    Given I have the following code
      """
      <?php
      use Psr\Container\ContainerInterface;
      use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
      use Symfony\Contracts\Service\Attribute\SubscribedService;

      interface Mailer {}
      interface Logger {}
      final class Consumer {
          public function __construct(
              #[AutowireLocator([
                  'ignored' => new SubscribedService(key: 'mail', type: Mailer::class),
                  new SubscribedService(type: Logger::class),
                  new SubscribedService(key: 'optional', type: Logger::class, nullable: true),
              ])]
              private ContainerInterface $services,
          ) {}

          public function mailer(): Mailer { return $this->services->get('mail'); }
          public function logger(): Logger { return $this->services->get(Logger::class); }
          public function optional(): Logger { return $this->services->get('optional'); }
          public function hasOptional(): bool { return $this->services->has('optional'); }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: SubscribedService methods provide their container entries
    Given I have the following code
      """
      <?php
      use Symfony\Contracts\Service\Attribute\SubscribedService;
      use Symfony\Contracts\Service\ServiceMethodsSubscriberTrait;
      use Symfony\Contracts\Service\ServiceSubscriberInterface;

      interface Mailer {}
      interface Logger {}
      class Subscriber implements ServiceSubscriberInterface {
          use ServiceMethodsSubscriberTrait;

          #[SubscribedService]
          public function mailer(): Mailer { return $this->container->get(__METHOD__); }

          #[SubscribedService(key: 'log')]
          public function logger(): ?Logger { return $this->container->get('log'); }
      }
      final class Child extends Subscriber {
          public function inherited(): Mailer {
              return $this->container->get(Subscriber::class.'::mailer');
          }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: An unknown locator key is rejected
    Given I have the following code
      """
      <?php
      use Psr\Container\ContainerInterface;
      use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

      interface Mailer {}
      final class Consumer {
          public function __construct(
              #[AutowireLocator(['mail' => Mailer::class])]
              private ContainerInterface $services,
          ) {}

          public function run(): void { $this->services->get('missing'); }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type            | Message   |
      | ServiceNotFound | /missing/ |
    And I see no other errors

  Scenario: Service subscribers accept SubscribedService objects in their returned map
    Given I have the following code
      """
      <?php
      use Psr\Container\ContainerInterface;
      use Symfony\Contracts\Service\Attribute\SubscribedService;
      use Symfony\Contracts\Service\ServiceSubscriberInterface;

      interface Mailer {}
      interface Logger {}
      final class Subscriber implements ServiceSubscriberInterface {
          public function __construct(private ContainerInterface $services) {}

          #[\Override]
          public static function getSubscribedServices(): array {
              return [
                  new SubscribedService(key: 'mail', type: Mailer::class),
                  'optional' => new SubscribedService(type: Logger::class, nullable: true),
              ];
          }

          public function mailer(): Mailer { return $this->services->get('mail'); }
          public function logger(): Logger { return $this->services->get('optional'); }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: A locator takes precedence over the global container
    Given I have the following code in "container.xml"
      """
      <?xml version="1.0" encoding="utf-8"?>
      <container xmlns="http://symfony.com/schema/dic/services">
        <services>
          <service id="mail" class="Logger" public="false" />
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
      use Psr\Container\ContainerInterface;
      use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

      interface Mailer {}
      interface Logger {}
      final class Consumer {
          public function __construct(
              #[AutowireLocator(['mail' => Mailer::class])]
              private ContainerInterface $services,
          ) {}

          public function mailer(): Mailer { return $this->services->get('mail'); }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: SubscribedService supports property get hooks
    Given I have the following code
      """
      <?php
      use Symfony\Contracts\Service\Attribute\SubscribedService;
      use Symfony\Contracts\Service\ServiceMethodsSubscriberTrait;
      use Symfony\Contracts\Service\ServiceSubscriberInterface;

      interface Mailer {}
      interface Logger {}
      final class Subscriber implements ServiceSubscriberInterface {
          use ServiceMethodsSubscriberTrait;

          #[SubscribedService]
          public Mailer $mailer {
              get { return $this->container->get(__METHOD__); }
          }

          #[SubscribedService(key: 'log')]
          public ?Logger $logger {
              get { return $this->container->get('log'); }
          }

          public function mailerFromLocator(): Mailer {
              return $this->container->get(self::class.'::$mailer::get');
          }

          public function loggerFromLocator(): Logger {
              return $this->container->get('log');
          }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Locator definitions can use class constants
    Given I have the following code
      """
      <?php
      use Psr\Container\ContainerInterface;
      use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

      interface Mailer {}
      interface Logger {}
      final class Consumer {
          private const SERVICES = ['mail' => Mailer::class, 'optional' => '?'.Logger::class];

          public function __construct(
              #[AutowireLocator(self::SERVICES)]
              private ContainerInterface $services,
          ) {}

          public function mailer(): Mailer { return $this->services->get('mail'); }
          public function logger(): Logger { return $this->services->get('optional'); }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Reassigning a parameter keeps its new type
    Given I have the following code
      """
      <?php
      use Psr\Container\ContainerInterface;
      use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

      interface Mailer {}
      final class Logger {}
      final class OtherContainer implements ContainerInterface {
          #[\Override]
          public function get(string $id): Logger { return new Logger(); }
          #[\Override]
          public function has(string $id): bool { return true; }
      }
      final class Consumer {
          public function __construct(
              #[AutowireLocator(['mail' => Mailer::class])]
              ContainerInterface $services,
          ) {
              $copy = $services;
              $services = new OtherContainer();
              self::acceptLogger($services->get('mail'));
              self::acceptMailer($copy->get('mail'));
          }

          private static function acceptLogger(Logger $logger): void {}
          private static function acceptMailer(Mailer $mailer): void {}
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Literal service types load classes from dependencies
    Given I have the following code
      """
      <?php
      use Psr\Container\ContainerInterface;
      use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

      final class Consumer {
          public function __construct(
              #[AutowireLocator(['logger' => 'Psr\Log\NullLogger'])]
              private ContainerInterface $services,
          ) {}

          public function run(): void { $this->services->get('logger')->info('hello'); }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Invalid service types do not crash analysis
    Given I have the following code
      """
      <?php
      use Psr\Container\ContainerInterface;
      use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

      final class Consumer {
          public function __construct(
              #[AutowireLocator(['invalid' => 'Invalid<'])]
              private ContainerInterface $services,
          ) {}

          public function run(): mixed { return $this->services->get('invalid'); }
      }
      """
    When I run Psalm
    Then I see no errors
