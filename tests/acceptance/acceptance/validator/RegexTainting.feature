@symfony-common
Feature: Regex validation removes taints
  A value a Regex constraint checked can't carry the taints the pattern removes once there are no violations

  Background:
    Given I have issue handlers "UnusedVariable" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\Request;
      use Symfony\Component\HttpFoundation\Response;
      use Symfony\Component\Validator\Constraints as Assert;
      use Symfony\Component\Validator\Validator\ValidatorInterface;
      """

  Scenario: A value matching a Regex constraint is safe once there are no violations
    Given I have the following code
      """
      final class MyController
      {
        public function __invoke(Request $request, ValidatorInterface $validator): Response
        {
          $name = (string) $request->query->get('name');
          $violations = $validator->validate($name, [new Assert\NotBlank(), new Assert\Regex('#^[a-zA-Z0-9 _.\-]+$#D')]);
          if (count($violations) > 0) {
            return new Response('', 400);
          }
          return new Response($name);
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see no errors

  Scenario: The value is still tainted where there are violations
    Given I have the following code
      """
      final class MyController
      {
        public function __invoke(Request $request, ValidatorInterface $validator): Response
        {
          $name = (string) $request->query->get('name');
          $violations = $validator->validate($name, new Assert\Regex(pattern: '#^[a-z]+$#D'));
          if ($violations->count() !== 0) {
            return new Response($name);
          }
          return new Response('');
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message               |
      | TaintedHtml | Detected tainted HTML |
    And I see no other errors

  Scenario Outline: A Regex constraint that doesn't check the value as given in the Default group doesn't validate it
    Given I have the following code
      """
      final class MyController
      {
        public function __invoke(Request $request, ValidatorInterface $validator): Response
        {
          $name = (string) $request->query->get('name');
          $violations = $validator->validate($name, <constraints>);
          if (count($violations) > 0) {
            return new Response('', 400);
          }
          return new Response($name);
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message               |
      | TaintedHtml | Detected tainted HTML |
    And I see no other errors
    Examples:
      | constraints                                                    |
      | new Assert\Regex(pattern: '#[<>]#', match: false)              |
      | new Assert\Regex(pattern: '#^[a-z]+$#D', normalizer: 'trim')   |
      | new Assert\Regex(pattern: '#^[a-z]+$#D', groups: ['strict'])   |
      | [new Assert\Regex('#^[a-z]+$#D')], ['strict']                  |
      | [new Assert\NotBlank()]                                        |
