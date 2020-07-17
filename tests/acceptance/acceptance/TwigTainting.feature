Feature: Tainting

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>
        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin"/>
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

  @current
  Scenario: One parameter of the Request is used in a twig template without escaping
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
