@tainting
Feature: Twig tainting with cached templates

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles>
            <directory name="../../vendor"/>
            <!-- <directory name="./cache"/> DO NOT UNCOMMENT ! It would make the twig cache taints ignored -->
          </ignoreFiles>
        </projectFiles>
        <fileExtensions>
           <extension name=".php" />
        </fileExtensions>
        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
            <twigCachePath>/cache/twig</twigCachePath>
          </pluginClass>
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

  Scenario: One parameter of the twig rendering is tainted
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
    And the "index.html.twig" template is compiled in the "cache/twig/" directory
    When I run Psalm with taint analysis
    Then I see these errors
      | Type         | Message               |
      | TaintedInput | Detected tainted html |
    And I see no other errors

  # @todo : move this scenario in first position when taint-specialize is working
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
    And the "index.html.twig" template is compiled in the "cache/twig/" directory
    When I run Psalm with taint analysis
    And I see no errors
