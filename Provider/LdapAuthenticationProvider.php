<?php

namespace IMAG\LdapBundle\Provider;

use Symfony\Component\Security\Core\Authentication\Provider\AuthenticationProviderInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use IMAG\LdapBundle\Authentication\Token\LdapToken;
use IMAG\LdapBundle\Manager\LdapManagerUserInterface;
use IMAG\LdapBundle\Event\LdapUserEvent;
use IMAG\LdapBundle\Event\LdapEvents;
use IMAG\LdapBundle\User\LdapUser;

class LdapAuthenticationProvider implements AuthenticationProviderInterface
{
    private
        $userProvider,
        $ldapManager,
        $dispatcher,
        $providerKey,
        $hideUserNotFoundExceptions,
        $bindUsernameBefore
        ;

    /**
     * Constructor
     *
     * Please note that $hideUserNotFoundExceptions is true by default in order
     * to prevent a possible brute-force attack.
     *
     * @param UserProviderInterface    $userProvider
     * @param LdapManagerUserInterface $ldapManager
     * @param EventDispatcherInterface $dispatcher
     * @param string                   $providerKey
     * @param Boolean                  $hideUserNotFoundExceptions
     * @param Boolean                  $bindUsernameBefore
     */
    public function __construct(
        UserProviderInterface $userProvider,
        LdapManagerUserInterface $ldapManager,
        EventDispatcherInterface $dispatcher = null,
        $providerKey,
        $hideUserNotFoundExceptions = true,
        $bindUsernameBefore = false
    )
    {
        $this->userProvider = $userProvider;
        $this->ldapManager = $ldapManager;
        $this->dispatcher = $dispatcher;
        $this->providerKey = $providerKey;
        $this->hideUserNotFoundExceptions = $hideUserNotFoundExceptions;
        $this->bindUsernameBefore = $bindUsernameBefore;
    }

    /**
     * {@inheritdoc}
     */
    public function authenticate(TokenInterface $token)
    {
        if (!$this->supports($token)) {
            throw new AuthenticationException('Unsupported token');
        }

        if (false === $this->bindUsernameBefore) {
            try {
                $user = $this->userProvider
                    ->loadUserByUsername($token->getUsername());
            } catch (UsernameNotFoundException $userNotFoundException) {
                if ($this->hideUserNotFoundExceptions) {
                    throw new BadCredentialsException('Bad credentials', 0, $userNotFoundException);
                }
                throw $userNotFoundException;
            }
        } else {
            $user = new LdapUser();
            $user->setUsername($token->getUsername());
        }

        if (null !== $this->dispatcher && $user instanceof LdapUser) {
            $userEvent = new LdapUserEvent($user);
            try {
                $this->dispatcher->dispatch(LdapEvents::PRE_BIND, $userEvent);

            } catch(\Exception $expt) {
                if ($this->hideUserNotFoundExceptions) {
                    throw new BadCredentialsException('Bad credentials', 0, $expt);
                }

                throw $expt;
            }
        }

        if ($this->bind($user, $token)) {

            if (true === $this->bindUsernameBefore) {
                $user = $this->reloadUser($user);
            }

            $ldapToken = new LdapToken($user, '', $this->providerKey, $user->getRoles());
            $ldapToken->setAuthenticated(true);
            $ldapToken->setAttributes($token->getAttributes());

            return $ldapToken;
        }

        if ($this->hideUserNotFoundExceptions) {
            throw new BadCredentialsException('Bad credentials');
        } else {
            throw new AuthenticationException('The LDAP authentication failed.');
        }
    }

    /**
     * Authenticate the user with LDAP bind.
     *
     * @param UserInterface  $user
     * @param TokenInterface $token
     *
     * @return boolean
     */
    private function bind(UserInterface $user, TokenInterface $token)
    {
        $this->ldapManager
            ->setUsername($user->getUsername())
            ->setPassword($token->getCredentials());

        if (false === $this->bindUsernameBefore) {
            return (bool)$this->ldapManager->auth();
        } else {
            return (bool)$this->ldapManager->authNoAnonSearch();
        }
    }

    /**
     * Reload user with the username
     *
     * @param \IMAG\LdapBundle\User\LdapBundle $user
     * @return \IMAG\LdapBundle\User\LdapBundle $user
     */
    private function reloadUser(LdapUser $user)
    {
        try {
            $user = $this->userProvider->refreshUser($user);
        } catch (UsernameNotFoundException $userNotFoundException) {
            if ($this->hideUserNotFoundExceptions) {
                throw new BadCredentialsException('Bad credentials', 0, $userNotFoundException);
            }

            throw $userNotFoundException;
        }

        return $user;
    }

    /**
     * Check whether this provider supports the given token.
     *
     * @param TokenInterface $token
     *
     * @return boolean
     */
    public function supports(TokenInterface $token)
    {
        return ( $token instanceof LdapToken
                 || $token instanceof UsernamePasswordToken ) 
            && $token->getProviderKey() === $this->providerKey;
    }

}
