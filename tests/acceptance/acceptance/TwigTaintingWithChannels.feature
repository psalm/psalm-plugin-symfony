@symfony-common
Feature: Twig tainting with an integration giving apart what a variable holds and what it prints

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
          <plugin filename="../../tests/fixture/Twig/ChannelPlugin.php" />
        </plugins>
        <issueHandlers>
          <UnusedParam errorLevel="info"/>
          <MissingPureAnnotation errorLevel="info"/>
        </issueHandlers>
      </psalm>
      """
    And I have the following code preamble
      """
      <?php

      /**
       * Renders $template, whose variable `value` holds $value, and gives $printed when printed (see ChannelPlugin).
       */
      function render_view(string $template, mixed $value, string $printed): void {}

      """

  Scenario: A variable given safe to print, holding a tainted attribute, is displayed with only the raw filter
    Given I have the following code
      """
      $url = \is_string($_GET['url']) ? $_GET['url'] : '';
      render_view('index.html.twig', ['url' => $url], htmlspecialchars($url, ENT_QUOTES));
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ value|raw }}</h1>
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: The tainted attribute of a variable given safe to print is displayed with only the raw filter
    Given I have the following code
      """
      $url = \is_string($_GET['url']) ? $_GET['url'] : '';
      render_view('index.html.twig', ['url' => $url], htmlspecialchars($url, ENT_QUOTES));
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ value.url|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A variable given safe to print, holding a tainted attribute, is displayed with only the raw filter through another variable
    Given I have the following code
      """
      $url = \is_string($_GET['url']) ? $_GET['url'] : '';
      render_view('index.html.twig', ['url' => $url], htmlspecialchars($url, ENT_QUOTES));
      """
    And I have the following "index.html.twig" template
      """
      {% set copy = value %}<h1>{{ copy|raw }}</h1>
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: A variable given safe to print, holding a tainted attribute, goes through a filter and is displayed with only the raw filter
    Given I have the following code
      """
      $url = \is_string($_GET['url']) ? $_GET['url'] : '';
      render_view('index.html.twig', ['url' => $url], htmlspecialchars($url, ENT_QUOTES));
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ value|upper|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: The items of a variable given safe to print, which are those it holds, are displayed in a loop with only the raw filter
    Given I have the following code
      """
      $url = \is_string($_GET['url']) ? $_GET['url'] : '';
      render_view('index.html.twig', ['url' => $url], htmlspecialchars($url, ENT_QUOTES));
      """
    And I have the following "index.html.twig" template
      """
      {% for item in value %}{{ item|raw }}{% endfor %}
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A variable given safe to print, holding a tainted attribute, is displayed with only the raw filter by an included template
    Given I have the following code
      """
      $url = \is_string($_GET['url']) ? $_GET['url'] : '';
      render_view('index.html.twig', ['url' => $url], htmlspecialchars($url, ENT_QUOTES));
      """
    And I have the following "index.html.twig" template
      """
      <h1>{% include 'part.html.twig' %}</h1>
      """
    And I have the following "part.html.twig" template
      """
      {{ value|raw }}
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: The tainted attribute of a variable given safe to print is displayed with only the raw filter through another variable
    Given I have the following code
      """
      $url = \is_string($_GET['url']) ? $_GET['url'] : '';
      render_view('index.html.twig', ['url' => $url], htmlspecialchars($url, ENT_QUOTES));
      """
    And I have the following "index.html.twig" template
      """
      {% set copy = value %}<h1>{{ copy.url|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: The tainted attribute of a variable given safe to print is displayed with only the raw filter by an included template
    Given I have the following code
      """
      $url = \is_string($_GET['url']) ? $_GET['url'] : '';
      render_view('index.html.twig', ['url' => $url], htmlspecialchars($url, ENT_QUOTES));
      """
    And I have the following "index.html.twig" template
      """
      <h1>{% include 'part.html.twig' %}</h1>
      """
    And I have the following "part.html.twig" template
      """
      {{ value.url|raw }}
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A variable given safe to print, given to an included template with `with`, is displayed with only the raw filter
    Given I have the following code
      """
      $url = \is_string($_GET['url']) ? $_GET['url'] : '';
      render_view('index.html.twig', ['url' => $url], htmlspecialchars($url, ENT_QUOTES));
      """
    And I have the following "index.html.twig" template
      """
      {% include 'part.html.twig' with {copy: value} %}
      """
    And I have the following "part.html.twig" template
      """
      {{ copy|raw }}
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: A variable given safe to print, given to an included template with `with` and `only`, is displayed with only the raw filter
    Given I have the following code
      """
      $url = \is_string($_GET['url']) ? $_GET['url'] : '';
      render_view('index.html.twig', ['url' => $url], htmlspecialchars($url, ENT_QUOTES));
      """
    And I have the following "index.html.twig" template
      """
      {% include 'part.html.twig' with {value: value} only %}
      """
    And I have the following "part.html.twig" template
      """
      {{ value|raw }}
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: The tainted attribute of a variable given safe to print, given to an included template with `with`, is displayed with only the raw filter
    Given I have the following code
      """
      $url = \is_string($_GET['url']) ? $_GET['url'] : '';
      render_view('index.html.twig', ['url' => $url], htmlspecialchars($url, ENT_QUOTES));
      """
    And I have the following "index.html.twig" template
      """
      {% include 'part.html.twig' with {copy: value} %}
      """
    And I have the following "part.html.twig" template
      """
      {{ copy.url|raw }}
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: The tainted attribute of a variable given safe to print, given to an included template with `with` and `only`, is displayed with only the raw filter
    Given I have the following code
      """
      $url = \is_string($_GET['url']) ? $_GET['url'] : '';
      render_view('index.html.twig', ['url' => $url], htmlspecialchars($url, ENT_QUOTES));
      """
    And I have the following "index.html.twig" template
      """
      {% include 'part.html.twig' with {value: value} only %}
      """
    And I have the following "part.html.twig" template
      """
      {{ value.url|raw }}
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: The items of a variable given safe to print are displayed in a loop with only the raw filter by an included template
    Given I have the following code
      """
      $url = \is_string($_GET['url']) ? $_GET['url'] : '';
      render_view('index.html.twig', ['url' => $url], htmlspecialchars($url, ENT_QUOTES));
      """
    And I have the following "index.html.twig" template
      """
      {% include 'part.html.twig' %}
      """
    And I have the following "part.html.twig" template
      """
      {% for item in value %}{{ item|raw }}{% endfor %}
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: The items of a variable given safe to print are displayed in a loop over another variable with only the raw filter
    Given I have the following code
      """
      $url = \is_string($_GET['url']) ? $_GET['url'] : '';
      render_view('index.html.twig', ['url' => $url], htmlspecialchars($url, ENT_QUOTES));
      """
    And I have the following "index.html.twig" template
      """
      {% set copy = value %}{% for item in copy %}{{ item|raw }}{% endfor %}
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A variable given safe to print, set to another variable in a condition, is displayed with only the raw filter
    Given I have the following code
      """
      $url = \is_string($_GET['url']) ? $_GET['url'] : '';
      render_view('index.html.twig', ['url' => $url], htmlspecialchars($url, ENT_QUOTES));
      """
    And I have the following "index.html.twig" template
      """
      {% set copy = '' %}{% if value %}{% set copy = value %}{% endif %}{{ copy|raw }}
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: A variable given safe to print, set to another variable, is displayed with only the raw filter by an included template
    Given I have the following code
      """
      $url = \is_string($_GET['url']) ? $_GET['url'] : '';
      render_view('index.html.twig', ['url' => $url], htmlspecialchars($url, ENT_QUOTES));
      """
    And I have the following "index.html.twig" template
      """
      {% set copy = value %}{% include 'part.html.twig' %}
      """
    And I have the following "part.html.twig" template
      """
      {{ copy|raw }}
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: A variable given safe to print, given to a macro, is displayed with only the raw filter by the macro
    Given I have the following code
      """
      $url = \is_string($_GET['url']) ? $_GET['url'] : '';
      render_view('index.html.twig', ['url' => $url], htmlspecialchars($url, ENT_QUOTES));
      """
    And I have the following "index.html.twig" template
      """
      {% macro show(shown) %}{{ shown|raw }}{% endmacro %}{{ _self.show(value) }}
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: The tainted attribute of a variable given safe to print, given to a macro, is displayed with only the raw filter by the macro
    Given I have the following code
      """
      $url = \is_string($_GET['url']) ? $_GET['url'] : '';
      render_view('index.html.twig', ['url' => $url], htmlspecialchars($url, ENT_QUOTES));
      """
    And I have the following "index.html.twig" template
      """
      {% macro show(shown) %}{{ shown.url|raw }}{% endmacro %}{{ _self.show(value) }}
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors
