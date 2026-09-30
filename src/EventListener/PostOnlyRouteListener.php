<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BackOfficeDefaultTwigBundle\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * Keeps the actions of this theme that only answer a POST (toggles, deletions,
 * positions, cache flushes...) POST-only when the legacy back-office is
 * installed next to it.
 *
 * Thelia resolves a request through a chain of routers. When a route of the
 * theme refuses the method, the chain moves on to the next router, and the
 * legacy back-office declares most of these paths too, for every method: a
 * GET would then run the legacy action instead of being refused. Runs just
 * before the router listener and answers 405 in that case.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 33)]
final readonly class PostOnlyRouteListener
{
    private const ADMIN_PATH_PREFIX = '/admin';
    private const THEME_CONTROLLERS = 'BackOfficeDefaultTwigBundle\\Controller\\';

    public function __construct(
        #[Autowire(service: 'router.default')]
        private RouterInterface $router,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->isMethod('POST') || !str_starts_with($request->getPathInfo(), self::ADMIN_PATH_PREFIX)) {
            return;
        }

        $context = (new RequestContext())->fromRequest($request);
        $allowedMethods = $this->allowedMethodsWhenRefused($request->getPathInfo(), $context);
        if ($allowedMethods !== ['POST'] || !$this->isThemeRoute($request->getPathInfo(), $context)) {
            return;
        }

        $event->setResponse(new Response(
            'This action only answers a POST request.',
            Response::HTTP_METHOD_NOT_ALLOWED,
            ['Allow' => 'POST'],
        ));
    }

    /**
     * @return list<string>|null the methods the route accepts when it refuses the one of the request, null otherwise
     */
    private function allowedMethodsWhenRefused(string $path, RequestContext $context): ?array
    {
        try {
            $this->match($path, $context);
        } catch (MethodNotAllowedException $exception) {
            return array_values($exception->getAllowedMethods());
        } catch (RoutingException) {
        }

        return null;
    }

    private function isThemeRoute(string $path, RequestContext $context): bool
    {
        try {
            $parameters = $this->match($path, (clone $context)->setMethod('POST'));
        } catch (RoutingException) {
            return false;
        }

        $controller = $parameters['_controller'] ?? null;

        return \is_string($controller) && str_starts_with($controller, self::THEME_CONTROLLERS);
    }

    /**
     * @return array<string, mixed>
     */
    private function match(string $path, RequestContext $context): array
    {
        $previous = $this->router->getContext();
        $this->router->setContext($context);

        try {
            return $this->router->match($path);
        } finally {
            $this->router->setContext($previous);
        }
    }
}
