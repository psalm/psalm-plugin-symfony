@symfony-5
Feature: InputBag get return type

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm errorLevel="1">
        <projectFiles>
          <directory name="."/>
        </projectFiles>

        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin"/>
        </plugins>
      </psalm>
      """
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\Request;
      """

  Scenario Outline: Return type is string if default argument is string.
    Given I have the following code
      """
      class App
      {
        public function __invoke(Request $request): void
        {
          $string = $request-><property>->get('foo', 'bar');
          trim($string);
        }
      }
      """
    When I run Psalm
    Then I see no errors
    Examples:
      | property |
      | query    |
      | cookies  |

  Scenario Outline: Return type is nullable if default argument is not provided.
    Given I have the following code
      """
      class App
      {
        public function __invoke(Request $request): void
        {
          $nullableString = $request-><property>->get('foo');
          trim($nullableString);
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                 | Message                                                          |
      | PossiblyNullArgument | Argument 1 of trim cannot be null, possibly null value provided  |
    And I see no other errors
    Examples:
      | property |
      | query    |
      | cookies  |
