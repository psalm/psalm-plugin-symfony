@symfony-common
Feature: Messenger Envelope

  Background:
    Given I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\Messenger\Envelope;
      use Symfony\Component\Messenger\Stamp\StampInterface;

      class TestStamp implements StampInterface
      {
          public function getDummy(): string
          {
              return 'dummy';
          }
      }

      $envelope = new Envelope(new stdClass());
      """

  Scenario: Envelope is aware of the message class
    Given I have the following code
      """
      /** @psalm-trace $envelope */
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                                   |
      | Trace | $envelope: Symfony\Component\Messenger\Envelope<stdClass> |
    And I see no other errors

  Scenario: Wrapped envelope has the same message class
    Given I have the following code
      """
      $newEnvelope = Envelope::wrap($envelope);
      /** @psalm-trace $newEnvelope */
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                                      |
      | Trace | $newEnvelope: Symfony\Component\Messenger\Envelope<stdClass> |
    And I see no other errors

  Scenario: Envelope::with returns an envelope with the same message class
    Given I have the following code
      """
      $newEnvelope = $envelope->with();
      /** @psalm-trace $newEnvelope */
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                                      |
      | Trace | $newEnvelope: Symfony\Component\Messenger\Envelope<stdClass> |
    And I see no other errors

  Scenario: Envelope::withoutAll returns an envelope with the same message class
    Given I have the following code
      """
      $newEnvelope = $envelope->withoutAll(TestStamp::class);
      /** @psalm-trace $newEnvelope */
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                                      |
      | Trace | $newEnvelope: Symfony\Component\Messenger\Envelope<stdClass> |
    And I see no other errors

  Scenario: Envelope::withoutAll accepts only a FQCN
    Given I have the following code
      """
      $newEnvelope = $envelope->withoutAll('type');
      """
    When I run Psalm
    Then I see these errors
      | Type                 | Message                                                                                                          |
      | ArgumentTypeCoercion | Argument 1 of Symfony\Component\Messenger\Envelope::withoutAll expects class-string, parent type "type" provided |
      | UndefinedClass       | Class, interface or enum named type does not exist                                                               |
    And I see no other errors

  Scenario: Envelope::withoutStampsOfType returns an envelope with the same message class
    Given I have the following code
      """
      $newEnvelope = $envelope->withoutStampsOfType(TestStamp::class);
      /** @psalm-trace $newEnvelope */
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                                      |
      | Trace | $newEnvelope: Symfony\Component\Messenger\Envelope<stdClass> |
    And I see no other errors

  Scenario: Envelope::withoutStampsOfType accepts only a FQCN
    Given I have the following code
      """
      $newEnvelope = $envelope->withoutStampsOfType('type');
      """
    When I run Psalm
    Then I see these errors
      | Type                 | Message                                                                                                                   |
      | ArgumentTypeCoercion | Argument 1 of Symfony\Component\Messenger\Envelope::withoutStampsOfType expects class-string, parent type "type" provided |
      | UndefinedClass       | Class, interface or enum named type does not exist                                                                        |
    And I see no other errors

  Scenario: Envelope::last() expects a class name implementing StampInterface
    Given I have the following code
      """
      $stamp = $envelope->last(Symfony\Component\Messenger\Worker::class);
      """
    When I run Psalm
    Then I see these errors
      | Type            | Message                                                                                                                                                                             |
      | InvalidArgument | Argument 1 of Symfony\Component\Messenger\Envelope::last expects class-string<Symfony\Component\Messenger\Stamp\StampInterface>, Symfony\Component\Messenger\Worker::class provided |
    And I see no other errors

  Scenario: Envelope::last() returns a nullable object of the provided class name
    Given I have the following code
      """
      $stamp = $envelope->last(TestStamp::class);
      if ($stamp !== null) {
          $dummy = $stamp->getDummy();
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Envelope::all() expects a class name implementing StampInterface
    Given I have the following code
      """
      $stamps = $envelope->all(Symfony\Component\Messenger\Worker::class);
      """
    When I run Psalm
    Then I see these errors
      | Type            | Message                                                                                                                                                                                  |
      | InvalidArgument | Argument 1 of Symfony\Component\Messenger\Envelope::all expects class-string<Symfony\Component\Messenger\Stamp\StampInterface>\|null, Symfony\Component\Messenger\Worker::class provided |
    And I see no other errors

  Scenario: Envelope::all() returns a list with objects of the provided class name
    Given I have the following code
      """
      $stamps = $envelope->all(TestStamp::class);
      foreach ($stamps as $stamp) {
          $dummy = $stamp->getDummy();
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Envelope::all() does not have a required argument
    Given I have the following code
      """
      $stamps = $envelope->all();
      """
    When I run Psalm
    Then I see no errors

  Scenario: Envelope::all() returns a nested array when no argument is provided
    Given I have the following code
      """
      $stamps = $envelope->all();
      foreach ($stamps as $className => $classStamps) {
          foreach ($classStamps as $stamp) {
              /** @psalm-trace $stamp */
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                                  |
      | Trace | $stamp: Symfony\Component\Messenger\Stamp\StampInterface |
    And I see no other errors

  Scenario: Envelope::getMessage returns a message of a valid class
    Given I have the following code
      """
      $message = $envelope->getMessage();
      /** @psalm-trace $message */
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message            |
      | Trace | $message: stdClass |
    And I see no other errors

  Scenario: Message can be any object
    Given I have the following code
      """
      /** @psalm-suppress UnusedParam */
      function foo(Envelope $envelope): void {};
      foo($envelope);
      """
    When I run Psalm
    Then I see no errors
