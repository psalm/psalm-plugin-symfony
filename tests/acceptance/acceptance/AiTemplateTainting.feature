@symfony-common
Feature: Symfony AI message templates tainted by their variables

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
          <file name="../../tests/acceptance/stubs/SymfonyAi.phpstub"/>
        </stubs>
        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin" />
        </plugins>
        <issueHandlers>
          <UnusedVariable errorLevel="info"/>
          <UnusedParam errorLevel="info"/>
          <UnusedFunctionCall errorLevel="info"/>
          <UnusedMethodCall errorLevel="info"/>
          <MixedArgumentTypeCoercion errorLevel="info"/>
        </issueHandlers>
      </psalm>
      """
    And I have the following code preamble
      """
      <?php

      use Symfony\AI\Agent\AgentInterface;
      use Symfony\AI\Agent\Input;
      use Symfony\AI\Agent\InputProcessor\SystemPromptInputProcessor;
      use Symfony\AI\Platform\Message\Message;
      use Symfony\AI\Platform\Message\MessageBag;
      use Symfony\AI\Platform\Message\Template;
      use Symfony\AI\Platform\PlatformInterface;

      /**
       * @psalm-suppress InvalidReturnType
       * @psalm-capabilities read-props
       */
      function platform(): PlatformInterface {}

      /**
       * @psalm-suppress InvalidReturnType
       * @psalm-capabilities read-props
       */
      function agent(): AgentInterface {}

      $untrusted = $_GET['untrusted'] ?? '';
      if (!is_string($untrusted)) {
          exit;
      }
      """

  Scenario: A tainted variable is rendered into a system prompt
    Given I have the following code
      """
      $messages = new MessageBag(
          Message::forSystem(Template::string('You are helping {name}.')),
          Message::ofUser('What is PHP?'),
      );
      platform()->invoke('gpt', $messages, ['template_vars' => ['name' => $untrusted]]);
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type             | Message                     |
      | TaintedLlmPrompt | Detected tainted LLM prompt |
    And I see no other errors

  Scenario: A tainted variable is only rendered into a user message
    Given I have the following code
      """
      $messages = new MessageBag(
          Message::forSystem(Template::string('You are a {role} assistant.')),
          Message::ofUser(Template::string('Tell me about {topic}')),
      );
      platform()->invoke('gpt', $messages, ['template_vars' => ['role' => 'programming', 'topic' => $untrusted]]);
      """
    When I run Psalm with taint analysis
    Then I see no errors

  Scenario: A system prompt without placeholders is not tainted by the variables
    Given I have the following code
      """
      $messages = new MessageBag(Message::forSystem(Template::string('You are a helpful assistant.')));
      platform()->invoke('gpt', $messages, ['template_vars' => ['name' => $untrusted]]);
      """
    When I run Psalm with taint analysis
    Then I see no errors

  Scenario: A tainted variable is given to an agent as a named argument
    Given I have the following code
      """
      agent()->call(
          new MessageBag(Message::forSystem(Template::string('Answer in {language}.'))),
          options: ['template_vars' => ['language' => $untrusted]],
      );
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type             | Message                     |
      | TaintedLlmPrompt | Detected tainted LLM prompt |
    And I see no other errors

  Scenario: A system prompt template built with its constructor reads its placeholders
    Given I have the following code
      """
      $systemPrompt = new SystemPromptInputProcessor(new Template('You are helping {name}.', 'string'));
      agent()->call('What is PHP?', ['template_vars' => ['name' => $untrusted]]);
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type             | Message                     |
      | TaintedLlmPrompt | Detected tainted LLM prompt |
    And I see no other errors

  Scenario: A dotted placeholder reads the variable holding its object
    Given I have the following code
      """
      $messages = new MessageBag(Message::forSystem(Template::string('You are helping {user.name}.')));
      platform()->invoke('gpt', $messages, ['template_vars' => ['user' => ['name' => $untrusted]]]);
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type             | Message                     |
      | TaintedLlmPrompt | Detected tainted LLM prompt |
    And I see no other errors

  Scenario: A tainted variable changed by an input processor is rendered into a system prompt
    Given I have the following code
      """
      $messages = new MessageBag(Message::forSystem(Template::string('You are helping {name}.')));
      function process(Input $input, string $name): void
      {
          $input->setOptions(['template_vars' => ['name' => $name]]);
      }
      process(new Input(), $untrusted);
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type             | Message                     |
      | TaintedLlmPrompt | Detected tainted LLM prompt |
    And I see no other errors

  Scenario: Variables whose names are not known reach every system prompt with placeholders
    Given I have the following code
      """
      /** @param array<string, string> $variables */
      function ask(array $variables): void
      {
          $messages = new MessageBag(Message::forSystem(Template::string('You are helping {name}.')));
          platform()->invoke('gpt', $messages, ['template_vars' => $variables]);
      }
      ask(['name' => $untrusted]);
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type             | Message                     |
      | TaintedLlmPrompt | Detected tainted LLM prompt |
    And I see no other errors

  Scenario: An expression system prompt reads every variable
    Given I have the following code
      """
      $messages = new MessageBag(Message::forSystem(Template::expression('"You are helping " ~ name')));
      platform()->invoke('gpt', $messages, ['template_vars' => ['name' => $untrusted]]);
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type             | Message                     |
      | TaintedLlmPrompt | Detected tainted LLM prompt |
    And I see no other errors

  Scenario: Untainted variables are rendered into a system prompt
    Given I have the following code
      """
      $messages = new MessageBag(Message::forSystem(Template::string('You are helping {name}.')));
      platform()->invoke('gpt', $messages, ['template_vars' => ['name' => 'Fabien']]);
      """
    When I run Psalm with taint analysis
    Then I see no errors
