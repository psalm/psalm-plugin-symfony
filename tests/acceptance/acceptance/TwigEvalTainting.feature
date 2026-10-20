@symfony-common
Feature: Twig eval tainting

  Background:
    Given I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Twig\Environment;
      use Twig\Loader\ArrayLoader;
      """

  Scenario Outline: A template source from the request is compiled
    Given I have the following code
      """
      function run(Environment $twig, ArrayLoader $loader): void
      {
          $source = is_string($_GET['source']) ? $_GET['source'] : '';
          <code>;
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message                                         |
      | TaintedEval | Detected tainted code passed to eval or similar |
    And I see no other errors
    Examples:
      | code                                  |
      | $twig->createTemplate($source)        |
      | $loader->setTemplate('name', $source) |
      | new ArrayLoader(['name' => $source])  |

  Scenario: A template name from the request is not compiled
    Given I have the following code
      """
      function run(ArrayLoader $loader): void
      {
          $name = is_string($_GET['name']) ? $_GET['name'] : '';
          $loader->setTemplate($name, 'Hello');
      }
      """
    When I run Psalm with taint analysis
    Then I see no errors

  Scenario: A template name from the request given to an array loader is not compiled
    Given I have the following code
      """
      function run(): ArrayLoader
      {
          $name = is_string($_GET['name']) ? $_GET['name'] : '';

          return new ArrayLoader([$name => 'Hello']);
      }
      """
    When I run Psalm with taint analysis
    Then I see no errors
