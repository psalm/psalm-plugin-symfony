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

  Scenario: Psalm recognizes denormalization result as an object when a class is passed as a type
    Given I have the following code
      """
      <?php
      use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

      function test(DenormalizerInterface $denormalizer): void
      {
        $result = $denormalizer->denormalize([], stdClass::class);
        /** @psalm-trace $result */
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message           |
      | Trace | $result: stdClass |
    And I see no other errors

  Scenario: Psalm does not recognize denormalization result type when a string is passed as a type
    Given I have the following code
      """
      <?php
      use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

      function test(DenormalizerInterface $denormalizer): void
      {
        $result = $denormalizer->denormalize([], 'stdClass[]');
        /** @psalm-trace $result */
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                   | Message                                                        |
      | MixedAssignment        | Unable to determine the type that $result is being assigned to |
      | Trace                  | $result: mixed                                                 |
    And I see no other errors

  Scenario: Psalm does not complain about the missing $data parameter type in the denormalizer implementation
    Given I have the following code
      """
      <?php
      use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

      final class Denormalizer implements DenormalizerInterface
      {
        public function supportsDenormalization($data, string $type, string $format = null)
        {
          return true;
        }

        public function denormalize($data, string $type, string $format = null, array $context = [])
        {
          return null;
        }
      }
      """
    When I run Psalm
    Then I see no errors
