@symfony-common
Feature: Voter abstract class

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm errorLevel="1">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>

        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin"/>
        </plugins>
        <issueHandlers>
          <UnusedVariable errorLevel="info"/>
        </issueHandlers>
      </psalm>
      """
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\Security\Core\Authorization\Voter\Voter;
      use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

      class VoterSubject {}
      """

  Scenario: Assert MoreSpecificImplementedParamType is not raised on voteOnAttribute
    Given I have the following code
      """
      class SomeVoter extends Voter
      {
          protected function supports(string $attribute, $subject): bool
          {
              return $subject instanceof VoterSubject;
          }

          /**
           * @param VoterSubject $subject
           */
          protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token): bool
          {
              /** @psalm-trace $subject */
              return true;
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                |
      | Trace | $subject: VoterSubject |
    And I see no other errors
