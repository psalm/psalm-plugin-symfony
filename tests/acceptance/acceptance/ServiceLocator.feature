@symfony-5 @symfony-6
Feature: ServiceLocator

  Background:
    Given I have Symfony plugin enabled

  Scenario: ServiceLocator will return tagged service
    Given I have the following code
      """
      <?php

      interface StrategyInterface{}

      use Symfony\Component\DependencyInjection\ServiceLocator;

      class MyService
      {
          /** @var ServiceLocator<StrategyInterface> $strategies */
          private ServiceLocator $strategies;

          /** @param ServiceLocator<StrategyInterface> $strategies */
          public function __construct(ServiceLocator $strategies)
          {
              $this->strategies = $strategies;
          }

          public function doSomethingWithStrategy(): void
          {
              $strategy = $this->strategies->get('random_string');
              /** @psalm-trace $strategy */
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                     |
      | Trace | $strategy: StrategyInterface                |
    And I see no other errors

  Scenario: Fixed the return type of getProvidedServices()
    Given I have the following code
      """
      <?php

      interface StrategyInterface{}

      use Symfony\Component\DependencyInjection\ServiceLocator;

      class MyService
      {
          /** @var ServiceLocator<StrategyInterface> $strategies */
          private ServiceLocator $strategies;

          /** @param ServiceLocator<StrategyInterface> $strategies */
          public function __construct(ServiceLocator $strategies)
          {
              $this->strategies = $strategies;
          }

          public function getAll(): void
          {
              $names = $this->strategies->getProvidedServices();
              /** @psalm-trace $names */
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                     |
      | Trace | $names: array<string, string>               |
    And I see no other errors
