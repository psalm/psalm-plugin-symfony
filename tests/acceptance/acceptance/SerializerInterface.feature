@symfony-common
Feature: Denormalizer interface
  Detect DenormalizerInterface::denormalize() result type

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm errorLevel="1">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>

        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin"/>
        </plugins>
      </psalm>
      """

  Scenario: Asserting psalm recognizes return type
    Given I have the following code
      """
      <?php
      use Symfony\Component\Serializer\SerializerInterface;

      class SomeService
      {
        public function foo(): void {}
      }

      class SomeController
      {
        public function __construct(SerializerInterface $serializer)
        {
          $someService = $serializer->deserialize('[]', SomeService::class, 'json');

          $someService->foo();
        }
      }
      """
    When I run Psalm
    Then I see no errors
