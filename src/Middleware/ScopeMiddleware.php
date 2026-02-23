<?php
declare(strict_types=1);

namespace WanPHP\Core\Middleware;

use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class ScopeMiddleware implements MiddlewareInterface
{


  public function __construct(private string|array $requiredScopes)
  {
  }

  /**
   * @throws OAuthServerException
   */
  public function process(
    ServerRequestInterface  $request,
    RequestHandlerInterface $handler
  ): ResponseInterface
  {

    /** @var string[]|null $tokenScopes */
    $tokenScopes = $request->getAttribute('oauth_scopes');

    if (empty($tokenScopes) || !is_array($tokenScopes)) {
      throw OAuthServerException::accessDenied('Missing OAuth scopes');
    }

    foreach ((array)$this->requiredScopes as $required) {
      if (!$this->hasScope($tokenScopes, $required)) {
        throw OAuthServerException::invalidScope($required);
      }
    }

    return $handler->handle($request);
  }

  private function hasScope(array $tokenScopes, string $requiredScope): bool
  {
    if (in_array($requiredScope, $tokenScopes, true)) {
      return true;
    }

    if (!str_contains($requiredScope, '.')) {
      return false;
    }

    [$resource] = explode('.', $requiredScope, 2);

    return in_array($resource . '.all', $tokenScopes, true)
      || in_array($resource . '.*', $tokenScopes, true);
  }

}
