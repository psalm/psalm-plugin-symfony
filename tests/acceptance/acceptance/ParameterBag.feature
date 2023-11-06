@symfony-5 @symfony-6
Feature: ParameterBag return type detection if container.xml is provided

  Background:
    Given I have Symfony plugin enabled with the following config
      """
      <containerXml>../../tests/acceptance/container.xml</containerXml>
      """
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
      """

  Scenario: Asserting psalm recognizes return type of Symfony parameters for ParameterBag
    Given I have the following code
      """
      class Foo
      {
        public function __invoke(ParameterBagInterface $parameterBag)
        {
          /** @psalm-trace $kernelEnvironment */
          $kernelEnvironment = $parameterBag->get('kernel.environment');

          /** @psalm-trace $debugEnabled */
          $debugEnabled = $parameterBag->get('debug_enabled');

          /** @psalm-trace $debugDisabled */
          $debugDisabled = $parameterBag->get('debug_disabled');

          /** @psalm-trace $version */
          $version = $parameterBag->get('version');

          /** @psalm-trace $integerOne */
          $integerOne = $parameterBag->get('integer_one');

          /** @psalm-trace $pi */
          $pi = $parameterBag->get('pi');

          /** @psalm-trace $collection1 */
          $collection1 = $parameterBag->get('collection1');
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                      |
      | Trace | $kernelEnvironment: string   |
      | Trace | $debugEnabled: bool          |
      | Trace | $debugDisabled: bool         |
      | Trace | $version: string             |
      | Trace | $integerOne: int             |
      | Trace | $pi: float                   |
      | Trace | $collection1: array          |
    And I see no other errors

  Scenario: Asserting psalm recognizes return type of Symfony parameters for AbstractController
    Given I have the following code
      """
      class Foo extends \Symfony\Bundle\FrameworkBundle\Controller\AbstractController
      {
        public function __invoke()
        {
          /** @psalm-trace $kernelEnvironment */
          $kernelEnvironment = $this->getParameter('kernel.environment');

          /** @psalm-trace $debugEnabled */
          $debugEnabled = $this->getParameter('debug_enabled');

          /** @psalm-trace $debugDisabled */
          $debugDisabled = $this->getParameter('debug_disabled');

          /** @psalm-trace $version */
          $version = $this->getParameter('version');

          /** @psalm-trace $integerOne */
          $integerOne = $this->getParameter('integer_one');

          /** @psalm-trace $pi */
          $pi = $this->getParameter('pi');

          /** @psalm-trace $collection1 */
          $collection1 = $this->getParameter('collection1');
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                      |
      | Trace | $kernelEnvironment: string   |
      | Trace | $debugEnabled: bool          |
      | Trace | $debugDisabled: bool         |
      | Trace | $version: string             |
      | Trace | $integerOne: int             |
      | Trace | $pi: float                   |
      | Trace | $collection1: array          |
    And I see no other errors

  Scenario: Get non-existent parameter
    Given I have the following code
      """
      class Foo
      {
        public function __invoke(ParameterBagInterface $parameterBag)
        {
          /** @psalm-trace $nonExistentParameter */
          $nonExistentParameter = $parameterBag->get('non_existent_parameter');
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                                                |
      | Trace | $nonExistentParameter: UnitEnum\|array<array-key, mixed>\|null\|scalar |
    And I see no other errors
