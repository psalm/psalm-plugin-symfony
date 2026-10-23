@symfony-common
Feature: Twig tainting through configured render methods

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
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
            <twigRenderMethod>Renderer::render</twigRenderMethod>
            <twigRenderMethod templateArgument="1" contextArgument="2">\StaticRenderer::fetch</twigRenderMethod>
            <twigRenderMethod>RendererInterface::show</twigRenderMethod>
            <twigRenderMethod templateArgument="1" contextArgument="2" templateDirectoryArgument="0">DirectoryRenderer::fetch</twigRenderMethod>
          </pluginClass>
        </plugins>
        <issueHandlers>
          <UnusedParam errorLevel="info"/>
          <UnusedClass errorLevel="info"/>
          <PossiblyUnusedMethod errorLevel="info"/>
          <MissingPureAnnotation errorLevel="info"/>
        </issueHandlers>
      </psalm>
      """
    And I have the following code preamble
      """
      <?php

      class Renderer
      {
          public function render(string $template, array $context): string
          {
              return '';
          }
      }

      final class OverridingRenderer extends Renderer
      {
          #[\Override]
          public function render(string $template, array $context): string
          {
              return '';
          }
      }

      /** @psalm-pure */
      final class StaticRenderer
      {
          public static function fetch(string $directory, string $template, array $context): string
          {
              return '';
          }
      }

      /** @psalm-pure */
      final class DirectoryRenderer
      {
          public static function fetch(string $directory, string $template, array $context): string
          {
              return '';
          }
      }

      /** @psalm-pure */
      interface RendererInterface
      {
          /** @psalm-pure */
          public function show(string $template, array $context): string;
      }

      /** @psalm-pure */
      final class ImplementingRenderer implements RendererInterface
      {
          #[\Override]
          public function show(string $template, array $context): string
          {
              return '';
          }
      }

      /** @psalm-pure */
      final class UnrelatedRenderer
      {
          public function render(string $template, array $context): string
          {
              return '';
          }
      }

      """

  Scenario: A tainted parameter of a configured render method is displayed with the raw filter
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      echo (new Renderer())->render('index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message               |
      | TaintedHtml           | Detected tainted HTML |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A tainted parameter of a configured render method is displayed with autoescaping
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      echo (new Renderer())->render('index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted }}</h1>
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: A tainted parameter of a method overriding a configured render method is displayed with the raw filter
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      echo (new OverridingRenderer())->render('index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message               |
      | TaintedHtml           | Detected tainted HTML |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A tainted parameter of a static render method taking the template and the variables as later arguments is displayed with the raw filter
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      echo StaticRenderer::fetch(__DIR__, 'index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message               |
      | TaintedHtml           | Detected tainted HTML |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A tainted parameter of a method implementing a configured render method of an interface is displayed with the raw filter
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      echo (new ImplementingRenderer())->show('index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message               |
      | TaintedHtml           | Detected tainted HTML |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A method of the same name of an unrelated class does not render a template
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      echo (new UnrelatedRenderer())->render('index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|raw }}</h1>
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: A tainted parameter of a render method naming the template in a directory of the template root is displayed with the raw filter
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      echo DirectoryRenderer::fetch(__DIR__ . '/templates/emails', 'welcome.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "emails/welcome.html.twig" template
      """
      <h1>{{ untrusted|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message               |
      | TaintedHtml           | Detected tainted HTML |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A render method naming the template in a directory does not render the template of the same name in the template root
    Given I have the following code
      """
      $untrusted = $_GET['untrusted'];
      echo DirectoryRenderer::fetch(__DIR__ . '/templates/emails', 'index.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "index.html.twig" template
      """
      <h1>{{ untrusted|raw }}</h1>
      """
    When I run Psalm with taint analysis
    And I see no errors

  Scenario: A tainted parameter of a render method naming the template in a directory out of the template root is displayed with the raw filter
    Given the template root directory is "views"
    And I have the following code
      """
      $untrusted = $_GET['untrusted'];
      echo DirectoryRenderer::fetch(__DIR__ . '/views', 'page.html.twig', ['untrusted' => $untrusted]);
      """
    And I have the following "page.html.twig" template
      """
      <h1>{{ untrusted|raw }}</h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message               |
      | TaintedHtml           | Detected tainted HTML |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors
