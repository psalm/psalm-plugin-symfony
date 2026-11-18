@symfony-common
Feature: Request body and upload tainting

  Background:
    Given I have issue handlers "UnusedVariable" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\File\UploadedFile;
      use Symfony\Component\HttpFoundation\Request;
      use Symfony\Component\HttpFoundation\Response;
      """

  Scenario: The body of the request is printed in the body of a Response object
    Given I have the following code
      """
      final class MyController
      {
        public function __invoke(Request $request): Response
        {
          return new Response($request->getContent());
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message               |
      | TaintedHtml | Detected tainted HTML |
    And I see no other errors

  Scenario Outline: The name the client gave an uploaded file is used as a path
    Given I have the following code
      """
      final class MyController
      {
        public function __invoke(Request $request): Response
        {
          $file = $request->files->get('upload');
          if ($file instanceof UploadedFile) {
            unlink('/var/uploads/' . $file-><method>());
          }

          return new Response('');
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message                        |
      | TaintedFile | Detected tainted file handling |
    And I see no other errors
    Examples:
      | method                |
      | getClientOriginalName |
      | getClientOriginalPath |

  Scenario: An uploaded file is fetched from the files of the request
    Given I have the following code
      """
      final class MyController
      {
        public function __invoke(Request $request): Response
        {
          /** @psalm-trace $file */
          $file = $request->files->get('upload');

          return new Response('');
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                                                      |
      | Trace | $file: Symfony\Component\HttpFoundation\File\UploadedFile\|array<array-key, mixed>\|null |
    And I see no other errors
