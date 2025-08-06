<?php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthJWT {
    public static function VerificarToken($request, $handler) {
        $header = $request->getHeaderLine('Authorization');

        if ($header) {
            $token = trim(str_replace('Bearer', '', $header));

            try {
                $decoded = JWT::decode($token, new Key("clave_super_secreta", 'HS256'));
                $request = $request->withAttribute('jwt', $decoded);
                return $handler->handle($request);
            } catch (Exception $e) {
                $response = new \Slim\Psr7\Response();
                $response = $response->withHeader('Content-Type', 'application/json');
                $response->getBody()->write(json_encode(['error' => 'Token inválido.']));
                return $response->withStatus(401);
            }
        } else {
            $response = new \Slim\Psr7\Response();
            $response = $response->withHeader('Content-Type', 'application/json');
            $response->getBody()->write(json_encode(['error' => 'Token no proporcionado.']));
            return $response->withStatus(401);
        }
    }

    public static function VerificarAdmin($request, $handler) {
        $header = $request->getHeaderLine('Authorization');

        if ($header) {
            $token = trim(str_replace('Bearer', '', $header));

            try {
                $decoded = JWT::decode($token, new Key("clave_super_secreta", 'HS256'));

                if ($decoded->rol !== 'admin') {
                    $response = new \Slim\Psr7\Response();
                    $response = $response->withHeader('Content-Type', 'application/json');
                    $response->getBody()->write(json_encode(['error' => 'Acceso denegado. Se requiere rol de administrador.']));
                    return $response->withStatus(403);
                }

                $request = $request->withAttribute('jwt', $decoded);
                return $handler->handle($request);
            } catch (Exception $e) {
                $response = new \Slim\Psr7\Response();
                $response = $response->withHeader('Content-Type', 'application/json');
                $response->getBody()->write(json_encode(['error' => 'Token inválido.']));
                return $response->withStatus(401);
            }
        } else {
            $response = new \Slim\Psr7\Response();
            $response = $response->withHeader('Content-Type', 'application/json');
            $response->getBody()->write(json_encode(['error' => 'Token no proporcionado.']));
            return $response->withStatus(401);
        }
    }
}
