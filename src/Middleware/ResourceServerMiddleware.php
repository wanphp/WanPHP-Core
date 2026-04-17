<?php

namespace WanPHP\Core\Middleware;


use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use WanPHP\Core\Service\UserService;
use WanPHP\Core\Worker\AuditLogContext;

final readonly class ResourceServerMiddleware implements MiddlewareInterface
{

  /**
   * @param ResourceServer $server
   * @param UserService $userService
   */
  public function __construct(private ResourceServer $server, private UserService $userService)
  {
  }

  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    try {
      $request = $this->server->validateAuthenticatedRequest($request);

      // 日志审计
      AuditLogContext::markActor('user',
        $request->getAttribute('oauth_user_id'),
        $request->getAttribute('oauth_client_id'));

      $scopeIds = $request->getAttribute('oauth_scopes');
      if (!empty($scopeIds)) {
        $scopes = $this->userService->getOauthScope($scopeIds);
        if (!empty($scopes)) $request = $request->withAttribute('oauth_scopes', $scopes);
      }

      return $handler->handle($request);
    } catch (OAuthServerException $exception) {
      return $exception->generateHttpResponse(new Response());
      // @codeCoverageIgnoreStart
    } catch (\Exception $exception) {
      return new OAuthServerException($exception->getMessage(), 0, 'BadRequest')
        ->generateHttpResponse(new Response());
      // @codeCoverageIgnoreEnd
    }
  }
}
