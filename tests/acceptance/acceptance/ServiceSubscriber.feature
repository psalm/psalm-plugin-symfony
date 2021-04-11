@symfony-4 @symfony-5
Feature: Service Subscriber

  Background:
    Given I have Symfony plugin enabled with the following config
      """
      <containerXml>../../tests/acceptance/container.xml</containerXml>
      """

  Scenario: Asserting psalm recognizes return type of services defined in getSubscribedServices
    Given I have the following code
      """
      <?php

      use Doctrine\ORM\EntityManagerInterface;
      use Psr\Container\ContainerInterface;
      use Symfony\Contracts\Service\ServiceSubscriberInterface;
      use Symfony\Component\Validator\Validator\ValidatorInterface;

      class SomeController implements ServiceSubscriberInterface
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
      <?php

      use Doctrine\ORM\EntityManagerInterface;
      use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

      class SomeController extends AbstractController
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
