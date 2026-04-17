<?php

namespace WanPHP\Core\AuthAction;

use Psr\Http\Message\ResponseInterface as Response;
use WanPHP\Core\Action;
use WanPHP\Core\Attribute\Route;
use WanPHP\Core\Middleware\ResourceServerMiddleware;
use WanPHP\Core\Service\UserService;

class GetScopeAction extends Action
{

  public function __construct(private readonly UserService $user)
  {
  }

  #[Route(
    path: '/api/client/getScopes',
    methods: ['POST'],
    description: '客户端取授权范围',
    name: 'wx.client.scope.get',
    middleware: [ResourceServerMiddleware::class]
  )]
  protected function action(): Response
  {
    if (!$this->isClient()) return $this->respondWithError('非法请求');
    $data = $this->getFormData();
    if (empty($data['scopeIds'])) return $this->respondWithData();
    return $this->respondWithData($this->user->getOauthScope((array)$data['scopeIds']));
  }
}