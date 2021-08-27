@symfony-4 @symfony-5
Feature: Service Subscriber

  Background:
    Given I have Symfony plugin enabled with the following config
      """
      <containerXml>../../tests/acceptance/container.xml</containerXml>
      """
    And I have the following code preamble
      """
      <?php

      namespace App\Controller;

      use Doctrine\ORM\EntityManagerInterface;
      use Psr\Container\ContainerInterface;
      use Symfony\Contracts\Service\ServiceSubscriberInterface;
      use Symfony\Component\Validator\Validator\ValidatorInterface;

      class DummyController implements ServiceSubscriberInterface
      {
        private $container;

        public function __construct(ContainerInterface $container)
        {
          $this->container = $container;
        }

        public static function getSubscribedServices()
        {
          return [
            // takes container.xml into account
          ];
        }
      """

  Scenario: Asserting psalm recognizes return type of services defined in getSubscribedServices
    Given I have the following code
      """
        public function __invoke()
        {
          /** @psalm-trace $service1 */
          $service1 = $this->container->get('dummy_service_with_locator');

          /** @psalm-trace $service2 */
          $service2 = $this->container->get('dummy_service_with_locator2');

          /** @psalm-trace $service3 */
          $service3 = $this->container->get('dummy_service_with_locator3');
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                                               |
      | Trace | $service1: Psalm\SymfonyPsalmPlugin\Tests\Fixture\DummyPrivateService |
      | Trace | $service2: Psalm\SymfonyPsalmPlugin\Tests\Fixture\DummyPrivateService |
      | Trace | $service3: Psalm\SymfonyPsalmPlugin\Tests\Fixture\DummyPrivateService |
    And I see no other errors

  Scenario: Asserting psalm recognizes return type of services fetched name by PHP constants.
    Given I have the following code
      """
        public function __invoke()
        {
          /** @psalm-trace $service1 */
          $service1 = $this->container->get(\Psalm\SymfonyPsalmPlugin\Tests\Fixture\DummyPrivateService::CUSTOM_SERVICE_NAME);
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                                               |
      | Trace | $service1: Psalm\SymfonyPsalmPlugin\Tests\Fixture\DummyPrivateService |
    And I see no other errors
