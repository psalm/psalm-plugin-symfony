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
        <issueHandlers>
          <UnusedVariable errorLevel="info"/>
        </issueHandlers>
      </psalm>
      """
    And I have the following code preamble
      """
      <?php

      use Twig\Environment;

      /**
       * @psalm-suppress InvalidReturnType
       * @return Environment
       * @psalm-capabilities read-props
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
      echo twig()->render('index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>
        {{ untrusted }}
      </h1>
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: One tainted parameter of the twig template is rendered with only the raw filter but the not displayed
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
    And I see no errors

  Scenario: One tainted parameter of the twig template is displayed with only the raw filter
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      echo twig()->render('index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>
        {{ untrusted|raw }}
      </h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: One tainted parameter (in a variable) of the twig template (named in a variable) is displayed with only the raw filter
    Given I have the following code
      """
      $untrustedParameters = ['untrusted' => $_GET['untrusted']];
      $template = 'index.html.twig';

      echo twig()->render($template, $untrustedParameters);
      """
    And I have the following "index.html.twig" template
      """
      <h1>
        {{ untrusted|raw }}
      </h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: One tainted parameter of the twig rendering is displayed with some filter followed by the raw filter
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      echo twig()->render('index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>
        {{ untrusted|upper|raw }}
      </h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: One tainted parameter of the twig rendering is displayed with the raw filter followed by some other filter
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      echo twig()->render('index.html.twig', ['untrusted' => $untrusted]);
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
      echo twig()->render('index.html.twig', ['untrusted' => $untrusted]);
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
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: One tainted parameter of the twig template is displayed with autoescaping deactivated
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      echo twig()->render('index.html.twig', ['untrusted' => $untrusted]);
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
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: One tainted parameter of the twig template is assigned to a variable and this variable is displayed
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      echo twig()->render('index.html.twig', ['untrusted' => $untrusted]);
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
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: One tainted parameter of the twig (oddly located) template is displayed with only the raw filter
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true">
        <projectFiles>
          <directory name="."/>
          <directory name="layouts"/>
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
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
            <twigRootPath>layouts</twigRootPath>
          </pluginClass>
        </plugins>
      </psalm>
      """
    And I have the following code
      """
      $untrusted = $_GET['untrusted'];
      echo twig()->render('index.html.twig', ['untrusted' => $untrusted]);
      """
    And the template root directory is "layouts"
    And I have the following "index.html.twig" template
      """
      <h1>
        {{ untrusted|raw }}
      </h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A tainted attribute of a parameter of the twig template is displayed with only the raw filter
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['user' => ['name' => $_GET['untrusted']]]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ user.name|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A tainted attribute of a parameter of the twig template is displayed with autoescaping on
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['user' => ['name' => $_GET['untrusted']]]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ user.name }}</h1>
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: The items of a tainted parameter of the twig template are displayed in a loop with only the raw filter
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['items' => [$_GET['untrusted']]]);
      """
    And I have the following "index.html.twig" template
      """
      {% for item in items %}
        <li>{{ item|raw }}</li>
      {% endfor %}
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A variable set from an expression using a tainted parameter is displayed with only the raw filter
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      {% set greeting = 'Hello ' ~ untrusted %}
      <h1>{{ greeting|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A tainted parameter of the twig template is displayed with only the raw filter, then escaped
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|raw }}</h1>
      <p>{{ untrusted }}</p>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A tainted parameter is displayed with only the raw filter by an included template
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{% include 'part.html.twig' %}</h1>
      """
    And I have the following "part.html.twig" template
      """
      {{ untrusted|raw }}
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A tainted parameter given to an included template with `with` is displayed with only the raw filter
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{% include 'part.html.twig' with {value: untrusted} %}</h1>
      """
    And I have the following "part.html.twig" template
      """
      {{ value|raw }}
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: An included template only given its own variables does not display a tainted parameter
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{% include 'part.html.twig' with {value: 'safe'} only %}</h1>
      """
    And I have the following "part.html.twig" template
      """
      {{ value|raw }} {{ untrusted|raw }}
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: One tainted parameter of a twig template outside of the template root directory is displayed with only the raw filter
    Given I have the following code
      """
      echo twig()->render('views/index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And the template root directory is "views"
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|raw }}</h1>
      """
    And the template root directory is "templates"
    And I have the following "layout.html.twig" template
      """
      <html></html>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors
