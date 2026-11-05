@symfony-common
Feature: Denormalizer interface
  Detect DenormalizerInterface::denormalize() result type

  Background:
    Given I have Symfony plugin enabled

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

  Scenario: Constructor of denormalized top-level class is not reported as unused
    Given I have the following code
      """
      <?php
      use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

      final class Address {
        public function __construct(public string $street) {}
      }

      function test(DenormalizerInterface $denormalizer): string
      {
        $address = $denormalizer->denormalize([], Address::class);
        return $address->street;
      }
      """
    When I run Psalm with dead code detection
    Then I see no errors

  Scenario: Constructor of typed sub-property is not reported as unused
    Given I have the following code
      """
      <?php
      use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

      /** @psalm-suppress PossiblyUnusedProperty */
      final class Street {
        public function __construct(public string $name) {}
      }
      /** @psalm-suppress PossiblyUnusedProperty */
      final class Address {
        public function __construct(public Street $street) {}
      }

      function test(DenormalizerInterface $denormalizer): void
      {
        $denormalizer->denormalize([], Address::class);
      }
      """
    When I run Psalm with dead code detection
    Then I see no errors

  Scenario: Constructor of array-collection sub-object is not reported as unused
    Given I have the following code
      """
      <?php
      use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

      /** @psalm-suppress PossiblyUnusedProperty */
      final class Tag {
        public function __construct(public string $name) {}
      }
      /** @psalm-suppress PossiblyUnusedProperty */
      final class Post {
        /** @var Tag[] */
        public array $tags = [];
        public function __construct(public string $title) {}
      }

      function test(DenormalizerInterface $denormalizer): void
      {
        $denormalizer->denormalize([], Post::class);
      }
      """
    When I run Psalm with dead code detection
    Then I see no errors

  Scenario: Constructor of list sub-object is not reported as unused
    Given I have the following code
      """
      <?php
      use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

      /** @psalm-suppress PossiblyUnusedProperty */
      final class Tag {
        public function __construct(public string $name) {}
      }
      /** @psalm-suppress PossiblyUnusedProperty */
      final class Post {
        /** @var list<Tag> */
        public array $tags = [];
        public function __construct(public string $title) {}
      }

      function test(DenormalizerInterface $denormalizer): void
      {
        $denormalizer->denormalize([], Post::class);
      }
      """
    When I run Psalm with dead code detection
    Then I see no errors

  Scenario: Circular object graph does not cause infinite recursion
    Given I have the following code
      """
      <?php
      use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

      /** @psalm-suppress PossiblyUnusedProperty */
      final class Node {
        public ?Node $parent = null;
        public function __construct(public string $value) {}
      }

      function test(DenormalizerInterface $denormalizer): void
      {
        $denormalizer->denormalize([], Node::class);
      }
      """
    When I run Psalm with dead code detection
    Then I see no errors
