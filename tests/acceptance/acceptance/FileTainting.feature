@symfony-common
Feature: File tainting

  Background:
    Given I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\Finder\Finder;
      use Symfony\Component\Mime\Email;
      use Symfony\Component\Mime\Part\DataPart;
      use Symfony\Component\Mime\Part\File;
      """

  Scenario Outline: A path from the request is opened
    Given I have the following code
      """
      $path = (string) $_GET['path'];
      <code>;
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message                        |
      | TaintedFile | Detected tainted file handling |
    And I see no other errors
    Examples:
      | code                                 |
      | (new Finder())->in($path)            |
      | (new Finder())->in([$path])          |
      | (new Email())->attachFromPath($path) |
      | (new Email())->embedFromPath($path)  |
      | DataPart::fromPath($path)            |
      | new File($path)                      |
