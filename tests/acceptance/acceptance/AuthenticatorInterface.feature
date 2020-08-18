@symfony-common
Feature: AuthenticatorInterface

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
      </psalm>
      """

  Scenario: Authenticator correctly resolves $credentials and $user types
    Given I have the following code
      """
      <?php

      use Symfony\Component\HttpFoundation\Request;
      use Symfony\Component\Security\Core\User\User;
      use Symfony\Component\Security\Core\User\UserInterface;
      use Symfony\Component\Security\Core\User\UserProviderInterface;
      use Symfony\Component\Security\Guard\AuthenticatorInterface;
      use Symfony\Component\Security\Guard\Token\PreAuthenticationGuardToken;

      /**
       * @implements AuthenticatorInterface<string, User>
       */
      abstract class SomeAuthenticator implements AuthenticatorInterface
      {
        public function getCredentials(Request $request)
        {
          return '';
        }

        public function getUser($credentials, UserProviderInterface $provider)
        {
          /** @psalm-trace $credentials */

          return new User('name', 'pass');
        }

        public function checkCredentials($credentials, UserInterface $user)
        {
          /** @psalm-trace $credentials */

          return true;

          /** @psalm-trace $user */
        }

        public function createAuthenticatedToken(UserInterface $user, string $providerKey)
        {
          /** @psalm-trace $user */

          return new PreAuthenticationGuardToken($user->getPassword(), $providerKey);
        }
      }

      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                          |
      | Trace | $credentials: string                             |
      | Trace | $credentials: string                             |
      | Trace | $user: Symfony\Component\Security\Core\User\User |
      | Trace | $user: Symfony\Component\Security\Core\User\User |
    And I see no other errors
