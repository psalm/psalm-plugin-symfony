@symfony-common
Feature: Twig tainting with analyzer

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true">
        <projectFiles>
          <directory name="."/>
          <directory name="templates"/>
          <ignoreFiles allowMissingFiles="true">
            <directory name="../../vendor" />
            <directory name="./cache" />
          </ignoreFiles>
        </projectFiles>
        <fileExtensions>
           <extension name=".php" />
           <extension name=".twig" checker="../../src/Twig/TemplateFileAnalyzer.php"/>
        </fileExtensions>
        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin" />
        </plugins>
      </psalm>
      """
    And I have the following code preamble
      """
      <?php

      use Twig\Environment;

      /**
       * @psalm-suppress InvalidReturnType
       * @return Environment
       */
      function twig() {}
      """

  Scenario: The twig rendering has no parameters
    Given I have the following code
      """
      twig()->render('index.html.twig');
      """
    And I have the following "index.html.twig" template
      """
      <h1>
        Nothing.
      </h1>
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: One parameter of the twig rendering is tainted but autoescaping is on
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      twig()->render('index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>
        {{ untrusted }}
      </h1>
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: One tainted parameter of the twig template is displayed with only the raw filter
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      twig()->render('index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>
        {{ untrusted|raw }}
      </h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type         | Message               |
      | TaintedInput | Detected tainted html |
    And I see no other errors

  Scenario: One tainted parameter (in a variable) of the twig template (named in a variable) is displayed with only the raw filter
    Given I have the following code
      """
      $untrustedParameters = ['untrusted' => $_GET['untrusted']];
      $template = 'index.html.twig';

      twig()->render($template, $untrustedParameters);
      """
    And I have the following "index.html.twig" template
      """
      <h1>
        {{ untrusted|raw }}
      </h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type         | Message               |
      | TaintedInput | Detected tainted html |
    And I see no other errors

  Scenario: One tainted parameter of the twig rendering is displayed with some filter followed by the raw filter
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      twig()->render('index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>
        {{ untrusted|upper|raw }}
      </h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type         | Message               |
      | TaintedInput | Detected tainted html |
    And I see no other errors

  Scenario: One tainted parameter of the twig rendering is displayed with the raw filter followed by some other filter
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      twig()->render('index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>
        {{ untrusted|raw|upper }}
      </h1>
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: One parameter of the twig rendering is tainted with inheritance
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      twig()->render('index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "base.html.twig" template
      """
      Base
      {% block body %}{% endblock %}
      """
    And I have the following "index.html.twig" template
      """
      {% extends 'base.html.twig' %}

      {% block body %}
      <h1>
        {{ untrusted|raw }}
      </h1>
      {% endblock %}
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type         | Message               |
      | TaintedInput | Detected tainted html |
    And I see no other errors

  Scenario: One tainted parameter of the twig template is displayed with autoescaping deactivated
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      twig()->render('index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "index.html.twig" template
      """
      {% autoescape false %}
      <h1>
        {{ untrusted }}
      </h1>
      {% endautoescape %}
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type         | Message               |
      | TaintedInput | Detected tainted html |
    And I see no other errors

  Scenario: One tainted parameter of the twig template is assigned to a variable and this variable is displayed
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      twig()->render('index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "index.html.twig" template
      """
      {% set some_local_var = untrusted %}
      {% set displayed_var = some_local_var %}
      <h1>
        {{ displayed_var|raw }}
      </h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type         | Message               |
      | TaintedInput | Detected tainted html |
    And I see no other errors
