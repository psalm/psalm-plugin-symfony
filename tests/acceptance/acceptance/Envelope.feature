Feature: Messenger Envelope

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

      use Symfony\Component\Messenger\Envelope;
      use Symfony\Component\Messenger\Stamp\ReceivedStamp;

      $envelope = new Envelope(new stdClass());
      """

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
      $stamp = $envelope->last(ReceivedStamp::class);
      if ($stamp !== null) {
          $transport = $stamp->getTransportName();
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
      $stamps = $envelope->all(ReceivedStamp::class);
      foreach ($stamps as $stamp) {
          $transport = $stamp->getTransportName();
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
              if ($stamp instanceof Symfony\Component\Messenger\Stamp\StampInterface) {
                  echo 'always true';
              }
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                                | Message                                                                                                                                                                                                      |
      | RedundantConditionGivenDocblockType | Found a redundant condition when evaluating docblock-defined type $stamp and trying to reconcile type 'Symfony\Component\Messenger\Stamp\StampInterface' to Symfony\Component\Messenger\Stamp\StampInterface |
    And I see no other errors
