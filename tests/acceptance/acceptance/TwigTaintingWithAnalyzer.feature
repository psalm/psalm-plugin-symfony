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
           <extension name=".twig" checker="../../src/Twig/TemplateFileAnalyzer.php" scanner="../../src/Twig/TemplateFileScanner.php"/>
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

  Scenario: A template generating PHP code is not parsed as PHP
    Given I have the following code
      """
      twig()->render('index.php.twig', ['name' => 'Foo']);
      """
    And I have the following "index.php.twig" template
      """
      <?php

      final class {{ name }} {
      }
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

  Scenario: A tainted parameter is only the condition of what is displayed
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      echo twig()->render('index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "index.html.twig" template
      """
      <h1 class="{{ untrusted ? 'set' : 'unset' }}">
        {{ (untrusted == 'a')|raw }} {{ (untrusted is empty)|raw }} {{ (untrusted|length + 1)|raw }}
      </h1>
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: A tainted parameter is a branch of what is displayed with the raw filter
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      echo twig()->render('index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>
        {{ (untrusted ? untrusted : 'none')|raw }}
      </h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message               |
      | TaintedHtml           | Detected tainted HTML |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A tainted parameter goes through a filter whose PHP callable returns a number
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      echo twig()->render('index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>
        {{ untrusted|length|raw }}
      </h1>
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: A tainted parameter goes through a filter whose PHP callable returns what it is given, and is displayed with the raw filter
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
      | Type                  | Message               |
      | TaintedHtml           | Detected tainted HTML |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

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
           <extension name=".twig" checker="../../src/Twig/TemplateFileAnalyzer.php" scanner="../../src/Twig/TemplateFileScanner.php"/>
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

  Scenario: Another attribute of a parameter with a tainted attribute is displayed with only the raw filter
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['user' => ['name' => $_GET['untrusted'], 'id' => 'literal']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ user.id|raw }}{{ user['id']|raw }}</h1>
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: The keys of a parameter with tainted items are displayed in a loop with only the raw filter
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['items' => ['literal' => $_GET['untrusted']]]);
      """
    And I have the following "index.html.twig" template
      """
      {% for key, item in items %}
        <li>{{ key|raw }}</li>
      {% endfor %}
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: A tainted parameter given to a function is displayed with only the raw filter
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ max(untrusted, 'literal')|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A tainted parameter given to a method returning it is displayed with only the raw filter
    Given I have the following code
      """
      /**
       * @psalm-api
       * @psalm-pure
       */
      final class Price
      {
          /** @psalm-pure */
          public function formatPrice(string $currency): string
          {
              return '1 '.$currency;
          }
      }

      echo twig()->render('index.html.twig', ['price' => new Price(), 'untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ price.formatPrice(untrusted)|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A tainted parameter given to a getter returning it, called by the name of its attribute, is displayed with only the raw filter
    Given I have the following code
      """
      /**
       * @psalm-api
       * @psalm-pure
       */
      final class Price
      {
          /** @psalm-pure */
          public function getFormattedPrice(string $currency): string
          {
              return '1 '.$currency;
          }
      }

      echo twig()->render('index.html.twig', ['price' => new Price(), 'untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ price.formattedPrice(untrusted)|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A tainted parameter given to a method not returning it is displayed with only the raw filter
    Given I have the following code
      """
      /**
       * @psalm-api
       * @psalm-pure
       */
      final class Price
      {
          /** @psalm-pure */
          public function formatPrice(string $currency, string $city = ''): string
          {
              return '' === $city ? '1 '.$currency : '1';
          }
      }

      echo twig()->render('index.html.twig', ['price' => new Price(), 'untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ price.formatPrice('literal', untrusted)|raw }}</h1>
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: A tainted parameter given to a method no class has is displayed with only the raw filter
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['price' => new stdClass(), 'untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ price.unknownMethod(untrusted)|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A tainted parameter given to a method that an object calls __call for is displayed with only the raw filter
    Given I have the following code
      """
      /**
       * @psalm-api
       * @psalm-pure
       */
      final class Price
      {
          /** @psalm-pure */
          public function formatPrice(string $currency): string
          {
              return '' === $currency ? '1' : '2';
          }
      }

      /**
       * @psalm-api
       * @psalm-pure
       */
      final class PriceProxy
      {
          /** @param list<mixed> $arguments */
          public function __call(string $name, array $arguments): string
          {
              return $name.' '.(string) $arguments[0];
          }
      }

      echo twig()->render('index.html.twig', ['price' => new PriceProxy(), 'untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ price.formatPrice(untrusted)|raw }}</h1>
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

  Scenario: A twig template the analysis cannot parse is skipped
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|some_unknown_filter }}</h1>
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: Two tainted parameters of the twig template are displayed with only the raw filter, the second one
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['first' => $_GET['first'], 'second' => $_GET['second']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ first }}</h1>
      <p>{{ second|raw }}</p>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A parameter of a twig template rendered elsewhere with a tainted parameter of the same name is displayed with only the raw filter
    Given I have the following code
      """
      echo twig()->render('other.html.twig', ['untrusted' => $_GET['untrusted']]);
      echo twig()->render('index.html.twig', ['untrusted' => strtoupper('literal')]);
      """
    And I have the following "other.html.twig" template
      """
      <h1>{{ untrusted }}</h1>
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|raw }}</h1>
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: Two expressions of a line of the twig template, only one of which uses a tainted parameter, are displayed with only the raw filter
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['safe' => ['name' => 'literal'], 'untrusted' => ['name' => $_GET['untrusted']]]);
      """
    And I have the following "index.html.twig" template
      """
      {% set name = untrusted.name %}<h1>{{ safe.name|raw }}</h1>
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: A tainted parameter given to a filter is displayed with only the raw filter
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ 'Hello %s'|format(untrusted)|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A variable set from a tainted parameter, then set in a condition, is displayed with only the raw filter
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted'], 'condition' => true]);
      """
    And I have the following "index.html.twig" template
      """
      {% set value = untrusted %}
      {% if condition %}
        {% set value = 'literal' %}
      {% endif %}
      <h1>{{ value|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A tainted parameter with the name of a loop variable is displayed with only the raw filter after the loop
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['item' => $_GET['untrusted'], 'items' => ['literal']]);
      """
    And I have the following "index.html.twig" template
      """
      {% for item in items %}{% endfor %}
      <h1>{{ item|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A tainted parameter set by an included template is displayed with only the raw filter
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
      {% set untrusted = untrusted|default('literal') %}
      {{ untrusted|raw }}
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A tainted parameter is displayed with only the raw filter by a template included by an included template
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
      {% include 'subpart.html.twig' %}
      """
    And I have the following "subpart.html.twig" template
      """
      {{ untrusted|raw }}
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A tainted parameter given to an included template with `with` and a variable is displayed with only the raw filter
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      {% set variables = {value: untrusted} %}
      <h1>{% include 'part.html.twig' with variables %}</h1>
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

  Scenario: A tainted parameter is displayed with only the raw filter by a template included with the include function
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ include('part.html.twig') }}</h1>
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

  Scenario: A tainted parameter given to an embedded template is displayed with only the raw filter
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      {% embed 'part.html.twig' with {value: untrusted} %}{% endembed %}
      """
    And I have the following "part.html.twig" template
      """
      <h1>{{ value|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A tainted parameter is displayed with only the raw filter by a block overridden by an embed
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      {% embed 'part.html.twig' %}
        {% block content %}{{ untrusted|raw }}{% endblock %}
      {% endembed %}
      """
    And I have the following "part.html.twig" template
      """
      <h1>{% block content %}{% endblock %}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: An embedded template only given its own variables does not display a tainted parameter
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      {% embed 'part.html.twig' with {value: 'literal'} only %}{% endembed %}
      """
    And I have the following "part.html.twig" template
      """
      <h1>{{ value|raw }} {{ untrusted|raw }}</h1>
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: A tainted parameter is displayed with only the raw filter by the template a twig template extends
    Given I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      {% extends 'base.html.twig' %}
      {% block content %}{% endblock %}
      """
    And I have the following "base.html.twig" template
      """
      <title>{{ untrusted|raw }}</title>
      {% block content %}{% endblock %}
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A tainted parameter goes through an escaping filter whose name matches a pattern, and is displayed with the raw filter
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true" autoloader="autoload.php">
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
           <extension name=".twig" checker="../../src/Twig/TemplateFileAnalyzer.php" scanner="../../src/Twig/TemplateFileScanner.php"/>
        </fileExtensions>
        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
            <containerXml>container.xml</containerXml>
          </pluginClass>
        </plugins>
      </psalm>
      """
    And I have the following code in "container.xml"
      """
      <?xml version="1.0" encoding="utf-8"?>
      <container xmlns="http://symfony.com/schema/dic/services">
        <services>
          <service id="twig" class="Twig\Environment" public="true">
            <call method="addExtension"><argument type="service" id="app.twig_extension"/></call>
          </service>
          <service id="app.twig_extension" class="App\TwigExtension"/>
        </services>
      </container>
      """
    And I have the following code in "TwigExtension.php"
      """
      <?php

      namespace App;

      use Twig\Extension\AbstractExtension;
      use Twig\TwigFilter;

      final class TwigExtension extends AbstractExtension
      {
          #[\Override]
          public function getFilters(): array
          {
              return [new TwigFilter('*_escape', [self::class, 'escape'])];
          }

          /**
           * @psalm-pure
           * @psalm-taint-escape html
           * @psalm-taint-escape has_quotes
           */
          public static function escape(string $strategy, string $value): string
          {
              return 'html' === $strategy ? htmlspecialchars($value, ENT_QUOTES) : $value;
          }
      }
      """
    And I have the following code in "autoload.php"
      """
      <?php

      require_once __DIR__.'/TwigExtension.php';
      """
    And I have the following code
      """
      echo twig()->render('index.html.twig', ['untrusted' => $_GET['untrusted']]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|html_escape|raw }}</h1>
      """
    When I run Psalm with taint analysis
    And I see no errors
