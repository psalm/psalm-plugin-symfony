@symfony-common
Feature: Symfony UX live components tainted by what the browser sends

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>
        <stubs>
          <file name="../../tests/acceptance/stubs/LiveComponent.phpstub"/>
        </stubs>
        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin" />
        </plugins>
        <issueHandlers>
          <UnusedClass errorLevel="info"/>
          <PossiblyUnusedMethod errorLevel="info"/>
          <PossiblyUnusedProperty errorLevel="info"/>
          <PossiblyUnusedParam errorLevel="info"/>
          <MissingConstructor errorLevel="info"/>
        </issueHandlers>
      </psalm>
      """
    And I have the following code preamble
      """
      <?php

      use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
      use Symfony\UX\LiveComponent\Attribute\LiveAction;
      use Symfony\UX\LiveComponent\Attribute\LiveArg;
      use Symfony\UX\LiveComponent\Attribute\LiveListener;
      use Symfony\UX\LiveComponent\Attribute\LiveProp;
      """

  Scenario: A writable live prop is displayed
    Given I have the following code
      """
      #[AsLiveComponent]
      final class Chat
      {
          #[LiveProp(writable: true)]
          public string $message = '';

          #[LiveAction]
          public function submit(): void
          {
              echo $this->message;
          }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A live prop that is not writable is displayed
    Given I have the following code
      """
      #[AsLiveComponent]
      final class Chat
      {
          #[LiveProp]
          public string $message = '';

          #[LiveAction]
          public function submit(): void
          {
              echo $this->message;
          }
      }
      """
    When I run Psalm with taint analysis
    Then I see no errors

  Scenario: A live prop synchronized with the query string is displayed
    Given I have the following code
      """
      #[AsLiveComponent]
      final class Search
      {
          #[LiveProp(url: true)]
          public string $query = '';

          public function show(): void
          {
              echo $this->query;
          }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: A writable path of an object live prop is displayed, but not its other properties
    Given I have the following code
      """
      final class Post
      {
          public string $title = '';
          public string $slug = '';
      }

      #[AsLiveComponent]
      final class EditPost
      {
          #[LiveProp(writable: ['title'])]
          public Post $post;

          #[LiveAction]
          public function save(): void
          {
              echo $this->post->title;
          }

          #[LiveAction]
          public function link(): void
          {
              echo $this->post->slug;
          }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: An argument of a live action is displayed
    Given I have the following code
      """
      #[AsLiveComponent]
      final class Tools
      {
          #[LiveAction]
          public function run(#[LiveArg] string $command, string $unrelated = ''): void
          {
              echo $unrelated;
              echo $command;
          }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: An argument of a live listener is displayed
    Given I have the following code
      """
      #[AsLiveComponent]
      final class Tools
      {
          #[LiveListener('run')]
          public function onRun(#[LiveArg('cmd')] string $command): void
          {
              echo $command;
          }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors

  Scenario: The old value an onUpdated hook of a writable live prop gets is displayed
    Given I have the following code
      """
      #[AsLiveComponent]
      final class Tools
      {
          #[LiveProp(writable: true, onUpdated: 'onCommandUpdated')]
          public string $command = '';

          public function onCommandUpdated(string $previous): void
          {
              echo $previous;
          }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type                  | Message                                    |
      | TaintedHtml           | Detected tainted HTML                      |
      | TaintedTextWithQuotes | Detected tainted text with possible quotes |
    And I see no other errors
