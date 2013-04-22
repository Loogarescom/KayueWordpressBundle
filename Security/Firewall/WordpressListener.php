<?php

namespace Kayue\WordpressBundle\Security\Firewall;

use Kayue\WordpressBundle\Security\Http\WordpressCookieService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\Security\Core\Authentication\AuthenticationManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\SecurityContextInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\Firewall\ListenerInterface;
use Symfony\Component\Security\Http\SecurityEvents;

class WordpressListener implements ListenerInterface
{
    private $securityContext;
    private $cookieService;
    private $authenticationManager;
    private $logger;
    private $dispatcher;

    /**
     * Constructor
     *
     * @param SecurityContextInterface $securityContext
     * @param WordpressCookieService $cookieService
     * @param AuthenticationManagerInterface $authenticationManager
     * @param LoggerInterface $logger
     * @param EventDispatcherInterface $dispatcher
     */
    public function __construct(SecurityContextInterface $securityContext, WordpressCookieService $cookieService, AuthenticationManagerInterface $authenticationManager, LoggerInterface $logger = null, EventDispatcherInterface $dispatcher = null)
    {
        $this->securityContext = $securityContext;
        $this->cookieService = $cookieService;
        $this->authenticationManager = $authenticationManager;
        $this->logger = $logger;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Handles WordPress's cookie based authentication.
     *
     * Since we assume WordPress is the only authentication system in the firewall, it will clear all previous token.
     *
     * @param GetResponseEvent $event A GetResponseEvent instance
     */
    public function handle(GetResponseEvent $event)
    {
        // WordPress firewall will clear all previous token.
        $this->securityContext->setToken(null);

        $request = $event->getRequest();

        try {
            if (null === $returnValue = $this->attemptAuthentication($request)) {
                return;
            }

            $this->onSuccess($event, $request, $returnValue);
        } catch (AuthenticationException $e) {
            $this->onFailure($event, $request, $e);
        }
    }

    protected function attemptAuthentication(Request $request)
    {
        if (null === $token = $this->cookieService->getTokenFromRequest($request)) {
            return null;
        }

        return $this->authenticationManager->authenticate($token);
    }

    private function onSuccess(GetResponseEvent $event, Request $request, TokenInterface $token)
    {
        if (null !== $this->logger) {
            $this->logger->info(sprintf('WordPress user "%s" has been authenticated successfully', $token->getUsername()));
        }

        $this->securityContext->setToken($token);

        if (null !== $this->dispatcher) {
            $loginEvent = new InteractiveLoginEvent($request, $token);
            $this->dispatcher->dispatch(SecurityEvents::INTERACTIVE_LOGIN, $loginEvent);
        }
    }

    private function onFailure($event, $request, $e)
    {
        if (null !== $this->logger) {
            $this->logger->info(sprintf('WordPress authentication failed: %s', $e->getMessage()));
        }

        $this->securityContext->setToken(null);
    }
}