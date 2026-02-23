<?php

namespace WanPHP\Core\AuthAction;

use Psr\Http\Message\ResponseInterface as Response;
use Throwable;
use WanPHP\Core\Action;
use WanPHP\Core\Attribute\Route;

class CheckLoginAction extends Action
{

  #[Route(path: '/auth/checkLogin', methods: ['POST'], name: 'auth.checkLogin')]
  /**
   * @inheritDoc
   * @throws Throwable
   */
  protected function action(): Response
  {
    if ($this->request->getMethod() == 'POST') {
      if (!empty($_SESSION['login_openid'])) return $this->respondWithData(['logged' => 'OK']);
      else return $this->respondWithError('尚未授权！');
    } else {
      return $this->respondWithError('授权验证失败！');
    }
  }
}
