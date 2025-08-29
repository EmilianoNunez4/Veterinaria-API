<?php

require_once './db/conexion.php';

class MascotaController {

    public static function AgregarMascota($request, $response, $args) {
        $data = $request->getParsedBody();
        $pdo = Conexion::obtenerConexion();
        $token = $request->getAttribute('jwt');

        if (!$token || !isset($token->id)) {
            $response->getBody()->write(json_encode(["error" => "Token inválido o no proporcionado."]));
            return $response->withStatus(401);
        }

        $stmt = $pdo->prepare("INSERT INTO mascotas (usuario_id, nombre, especie) VALUES (?, ?, ?)");
        $stmt->execute([$token->id, $data['nombre'], $data['especie']]);

        $response->getBody()->write(json_encode(["mensaje" => "Mascota agregada exitosamente."]));
        return $response;
    }

    public static function ListarTodasLasMascotas($request, $response, $args) {
        $pdo = Conexion::obtenerConexion();
        $token = $request->getAttribute('jwt');

        // Solo admin puede ver todas
        if ($token->rol !== 'admin') {
            $response->getBody()->write(json_encode(["error" => "Acceso denegado. Solo admin."]));
            return $response->withStatus(403);
        }

        $stmt = $pdo->query("SELECT m.id, m.nombre, m.especie, u.nombre AS propietario, u.email 
                             FROM mascotas m 
                             JOIN usuarios u ON m.usuario_id = u.id");
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

        if (!$token || !isset($token->id)) {
            $response->getBody()->write(json_encode(["error" => "Token inválido o no proporcionado."]));
            return $response->withStatus(401);
        }

        $stmt = $pdo->prepare("SELECT id, nombre, especie FROM mascotas WHERE usuario_id = ?");
        $stmt->execute([$token->id]);
        $mascotas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($mascotas)) {
            $response->getBody()->write(json_encode(["mensaje" => "No tienes mascotas registradas."]));
            return $response->withStatus(200);
        }

        $response->getBody()->write(json_encode($mascotas));
        return $response;
    }
}
