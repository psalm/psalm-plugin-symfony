@symfony-common
Feature: EventDispatcherInterface

  Background:
    Given I have Symfony plugin enabled

  Scenario: EventDispatcherInterface::dispatch() is generic
    Given I have the following code
      """
      <?php

      use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

      class Foo
      {
          public function test(EventDispatcherInterface $dispatcher, Foo $object): Foo
          {
              return $dispatcher->dispatch($object, 'foo');
          }
      }
      """
    When I run Psalm
    Then I see no errors
