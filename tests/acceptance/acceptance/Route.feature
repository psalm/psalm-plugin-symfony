@symfony-6
Feature: Route

  Background:
    Given I have Symfony plugin enabled

  Scenario: ImplicitToStringCast error is not raised when array of EnumRequirement is assigned
    Given I have the following code
      """
      <?php

      use Symfony\Component\Routing\Annotation\Route;
      use Symfony\Component\Routing\Requirement\EnumRequirement;

      #[Route(
          '/route/{type}',
          name: 'test',
          methods: 'GET',
          requirements: [
              'type' => new EnumRequirement(DummyEnum::class),
          ]
      )]
      class DummyAction {}


      enum DummyEnum: string {
          case TEST = 'test';
      }
      """
    When I run Psalm
    Then I see no errors

