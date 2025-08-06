<?php

require_once './db/conexion.php';

class MascotaController {

    public static function AgregarMascota($request, $response, $args) {
        $data = $request->getParsedBody();
        $pdo = Conexion::obtenerConexion();
        $token = $request->getAttribute('jwt');

        if (!$token || !isset($token->email)) {
            $response->getBody()->write(json_encode(["error" => "Token inválido o no proporcionado."]));
            return $response->withStatus(401);
        }

        $emailUsuario = $token->email;
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt->execute([$emailUsuario]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            $response->getBody()->write(json_encode(["error" => "Usuario no encontrado."]));
            return $response->withStatus(404);
        }

        $stmt = $pdo->prepare("INSERT INTO mascotas (usuario_id, nombre, especie) VALUES (?, ?, ?)");
        $stmt->execute([$usuario['id'], $data['nombre'], $data['especie']]);
        $response->getBody()->write(json_encode(["mensaje" => "Mascota agregada exitosamente."]));
        return $response;
    }

    public static function ListarTodasLasMascotas($request, $response, $args) {
        $pdo = Conexion::obtenerConexion();
        $stmt = $pdo->query("SELECT m.id, m.nombre, m.especie, u.nombre AS propietario, u.email FROM mascotas m JOIN usuarios u ON m.usuario_id = u.id");
        $mascotas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($mascotas)) {
            $response->getBody()->write(json_encode(["mensaje" => "No hay mascotas registradas."]));
            return $response->withStatus(200);
        }
        $response->getBody()->write(json_encode($mascotas));
        return $response;
    }

    public static function ListarMisMascotas($request, $response, $args) {
        $pdo = Conexion::obtenerConexion();
        $token = $request->getAttribute('jwt');

        if (!$token || !isset($token->email)) {
            $response->getBody()->write(json_encode(["error" => "Token inválido o no proporcionado."]));
            return $response->withStatus(401);
        }

        $emailUsuario = $token->email;
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt->execute([$emailUsuario]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            $response->getBody()->write(json_encode(["error" => "Usuario no encontrado."]));
            return $response->withStatus(404);
        }

        $stmt = $pdo->prepare("SELECT id, nombre, especie FROM mascotas WHERE usuario_id = ?");
        $stmt->execute([$usuario['id']]);
        $mascotas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($mascotas)) {
            $response->getBody()->write(json_encode(["mensaje" => "No tienes mascotas registradas."]));
            return $response->withStatus(200);
        }

        $response->getBody()->write(json_encode($mascotas));
        return $response;
    }
}