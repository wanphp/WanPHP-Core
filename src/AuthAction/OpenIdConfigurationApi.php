<?php

namespace WanPHP\Core\AuthAction;

use Psr\Http\Message\ResponseInterface as Response;
use WanPHP\Core\Action;

class OpenIdConfigurationApi extends Action
{

  /**
   * @inheritDoc
   */
  protected function action(): Response
  {
    $data = [
      "issuer" => $this->httpHost(),
      "authorization_endpoint" => $this->httpHost() . '/auth/authorize',
      "token_endpoint" => $this->httpHost() . '/auth/accessToken',
      "userinfo_endpoint" => $this->httpHost() . '/api/userProfile',
    ];
    return $this->respondWithData($data);
  }
}