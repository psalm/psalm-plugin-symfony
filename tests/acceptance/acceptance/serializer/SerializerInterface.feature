@symfony-5
Feature: Serializer interface
  Detect SerializerInterface::deserialize() result type

  Background:
    Given I have Symfony plugin enabled

  Scenario: Psalm recognizes deserialization result as an object when a class is passed as a type
    Given I have the following code
      """
      <?php
      use Symfony\Component\Serializer\SerializerInterface;

      function test(SerializerInterface $serializer): void
      {
        $result = $serializer->deserialize([], stdClass::class, 'json');
        /** @psalm-trace $result */
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message           |
      | Trace | $result: stdClass |
    And I see no other errors

  Scenario: Psalm does not recognize deserialization result type when a string is passed as a type
    Given I have the following code
      """
      <?php
      use Symfony\Component\Serializer\SerializerInterface;

      function test(SerializerInterface $serializer): void
      {
        $result = $serializer->deserialize([], 'stdClass[]', 'json');
        /** @psalm-trace $result */
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                   | Message                                                        |
      | MixedAssignment        | Unable to determine the type that $result is being assigned to |
      | Trace                  | $result: mixed                                                 |
    And I see no other errors

  Scenario: Psalm does not complain about the missing $data parameter type in the serializer implementation
    Given I have the following code
      """
      <?php
      use Symfony\Component\Serializer\SerializerInterface;

      final class Serializer implements SerializerInterface
      {
        public function serialize($data, string $format, array $context = [])
        {
          return '';
        }

        public function deserialize($data, string $type, string $format, array $context = [])
        {
          return [];
        }
      }
      """
    When I run Psalm
    Then I see no errors
