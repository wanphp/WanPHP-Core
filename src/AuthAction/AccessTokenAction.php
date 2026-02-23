<?php

namespace WanPHP\Core\AuthAction;


use League\OAuth2\Server\Middleware\AuthorizationServerMiddleware;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WanPHP\Core\Action;
use WanPHP\Core\Attribute\Route;

class AccessTokenAction extends Action
{

  #[Route(path: '/auth/accessToken', methods: ['POST'], name: 'auth.accessToken', middleware: [AuthorizationServerMiddleware::class])]
  #[OA\Post(
    path: "/auth/accessToken",
    operationId: "getToken",
    summary: "根据授权类型获取访问令牌",
    security: [],
    requestBody: new OA\RequestBody(
      required: true,
      content: new OA\JsonContent(
        required: ["grant_type", "client_id"],
        properties: [
          new OA\Property(property: "grant_type",
            description: "授权类型，可为 authorization_code、client_credentials、password、refresh_token、miniProgram",
            type: "string",
            enum: ["authorization_code", "client_credentials", "password", "refresh_token", "miniProgram"],
            example: "authorization_code"),
          new OA\Property(property: "client_id", description: "客户端ID", type: "string"),
          new OA\Property(property: "client_secret", description: "客户端密钥", type: "string"),
          new OA\Property(property: "code", description: "授权码（authorization_code或miniProgram模式使用）", type: "string"),
          new OA\Property(property: "redirect_uri", description: "重定向URI（与authorize授权时一致）", type: "string", format: "uri"),
          new OA\Property(property: "username", description: "用户名（password模式使用）", type: "string"),
          new OA\Property(property: "password", description: "用户密码（password模式使用）", type: "string"),
          new OA\Property(property: "refresh_token", description: "刷新令牌（refresh_token模式使用）", type: "string")
        ]
      )
    ),
    tags: ["Auth"],
    responses: [
      new OA\Response(response: 200, description: "返回访问令牌", content: new OA\JsonContent(
        required: ["access_token", "token_type", "expires_in"],
        properties: [
          new OA\Property(property: "access_token", description: "颁发的访问令牌。", type: "string"),
          new OA\Property(property: "token_type", description: "令牌类型，通常为 'Bearer'。", type: "string", example: "Bearer"),
          new OA\Property(property: "expires_in", description: "访问令牌的过期时间（秒）。", type: "integer", example: 3600),
          new OA\Property(property: "refresh_token", description: "用于获取新访问令牌的刷新令牌 (可选)。", type: "string", nullable: true),
          new OA\Property(property: "scope", description: "访问令牌授权的范围 (可选)。", type: "string", example: "openid profile", nullable: true)
        ]
      )),
      new OA\Response(response: 400, description: "参数错误")
    ]
  )]
  protected function action(): Response
  {
    return $this->response;
  }
}
