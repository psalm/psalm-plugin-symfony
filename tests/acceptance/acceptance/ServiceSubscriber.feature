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
      """

  Scenario: Asserting psalm recognizes return type of services defined in getSubscribedServices
    Given I have the following code
      """
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

        public function __invoke()
        {
          /** @psalm-trace $entityManager */
          $entityManager = $this->container->get('em');

          /** @psalm-trace $validator */
          $validator = $this->container->get(ValidatorInterface::class);
        }

        public static function getSubscribedServices()
        {
          return [
            'em' => EntityManagerInterface::class, // with key
            ValidatorInterface::class, // without key
          ];
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                                              |
      | Trace | $entityManager: Doctrine\ORM\EntityManagerInterface                  |
      | Trace | $validator: Symfony\Component\Validator\Validator\ValidatorInterface |
    And I see no other errors


  Scenario: Asserting psalm recognizes return type of services defined in getSubscribedServices using array_merge
    Given I have the following code
      """
      use Doctrine\ORM\EntityManagerInterface;
      use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

      class DummyController extends AbstractController
      {
        public function __invoke()
        {
          /** @psalm-trace $entityManager */
          $entityManager = $this->container->get('custom_service');
        }

        public static function getSubscribedServices(): array
        {
          return array_merge([
            'custom_service' => EntityManagerInterface::class,
          ], parent::getSubscribedServices());
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                             |
      | Trace | $entityManager: Doctrine\ORM\EntityManagerInterface |
    And I see no other errors

  Scenario: Asserting psalm recognizes return type of services defined in getSubscribedServices, already defined as an alias in containerXml
    Given I have the following code
      """
      use Psr\Container\ContainerInterface;
      use Symfony\Component\HttpKernel\HttpKernelInterface;
      use Symfony\Contracts\Service\ServiceSubscriberInterface;

      class DummyController implements ServiceSubscriberInterface
      {
        private $container;

        public function __construct(ContainerInterface $container)
        {
          $this->container = $container;
        }

        public function __invoke()
        {
          /** @psalm-trace $kernel */
          $kernel = $this->container->get('http_kernel');
        }

        public static function getSubscribedServices(): array
        {
          return [
              'http_kernel' => HttpKernelInterface::class,
          ];
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                          |
      | Trace | $kernel: Symfony\Component\HttpKernel\HttpKernel |
    And I see no other errors
