@symfony-common @php-8
Feature: MapQueryString and MapRequestPayload

  Scenario: MapQueryString DTO constructor is not reported as unused
    Given I have Symfony plugin enabled
    And I have the following code
      """
      <?php
      use Symfony\Component\HttpFoundation\Response;
      use Symfony\Component\HttpKernel\Attribute\MapQueryString;
      use Symfony\Component\Routing\Attribute\Route;

      final class SearchQuery
      {
        public function __construct(public readonly string $q = '') {}
      }

      final class SearchController
      {
        #[Route('/search')]
        public function search(#[MapQueryString] SearchQuery $query): Response
        {
          return new Response($query->q);
        }
      }
      """
    When I run Psalm with dead code detection
    Then I see no errors

  Scenario: MapRequestPayload DTO constructor is not reported as unused
    Given I have Symfony plugin enabled
    And I have the following code
      """
      <?php
      use Symfony\Component\HttpFoundation\Response;
      use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
      use Symfony\Component\Routing\Attribute\Route;

      final class CreateUserInput
      {
        public function __construct(public readonly string $name) {}
      }

      final class UserController
      {
        #[Route('/users', methods: ['POST'])]
        public function create(#[MapRequestPayload] CreateUserInput $input): Response
        {
          return new Response($input->name);
        }
      }
      """
    When I run Psalm with dead code detection
    Then I see no errors

  Scenario: Nested DTO constructor is not reported as unused
    Given I have Symfony plugin enabled
    And I have the following code
      """
      <?php
      use Symfony\Component\HttpFoundation\Response;
      use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
      use Symfony\Component\Routing\Attribute\Route;

      final class Address
      {
        public function __construct(public readonly string $city) {}
      }

      final class CreateUserInput
      {
        public function __construct(public readonly Address $address) {}
      }

      final class UserController
      {
        #[Route('/users', methods: ['POST'])]
        public function create(#[MapRequestPayload] CreateUserInput $input): Response
        {
          return new Response($input->address->city);
        }
      }
      """
    When I run Psalm with dead code detection
    Then I see no errors

  Scenario: Self-Referencing DTO does not cause infinite loop
    Given I have Symfony plugin enabled
    And I have the following code
      """
      <?php
      use Symfony\Component\HttpFoundation\Response;
      use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
      use Symfony\Component\Routing\Attribute\Route;

      final class Node
      {
        public function __construct(
          public string $name = '',
          public ?Node $parent = null
        ) {}

        public function __toString(): string
        {
          return $this->name;
        }

        public function unused(): void {}
      }

      final class UserController
      {
        #[Route('/foo', methods: ['POST'])]
        public function create(#[MapRequestPayload] Node $node): Response
        {
          return new Response((string) $node->parent);
        }
        public function unused(): void {}
      }
      """
    When I run Psalm with dead code detection
    Then I see these errors
      | Issue | Message |
      | PossiblyUnusedMethod | Cannot find any calls to method Node::unused           |
      | PossiblyUnusedMethod | Cannot find any calls to method UserController::unused |

    And I see no other errors
