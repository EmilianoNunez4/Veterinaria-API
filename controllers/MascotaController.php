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

        // Valores opcionales con null si no vienen
        $descripcion = isset($data['descripcion']) ? $data['descripcion'] : null;
        $foto = isset($data['foto']) ? $data['foto'] : null;

        $stmt = $pdo->prepare("
            INSERT INTO mascotas (usuario_id, nombre, especie, descripcion, foto) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$usuario['id'], $data['nombre'], $data['especie'], $descripcion, $foto]);

        $response->getBody()->write(json_encode(["mensaje" => "Mascota agregada exitosamente."]));
        return $response;
    }

    public static function ListarTodasLasMascotas($request, $response, $args) {
        $pdo = Conexion::obtenerConexion();
        $stmt = $pdo->query("
            SELECT 
                m.id, 
                m.nombre, 
                m.especie, 
                m.descripcion, 
                m.foto, 
                u.nombre AS propietario, 
                u.email 
            FROM mascotas m 
            JOIN usuarios u ON m.usuario_id = u.id
        ");
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

        $stmt = $pdo->prepare("
            SELECT 
                id, 
                nombre, 
                especie, 
                descripcion, 
                foto 
            FROM mascotas 
            WHERE usuario_id = ?
        ");
        $stmt->execute([$usuario['id']]);
        $mascotas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($mascotas)) {
            $response->getBody()->write(json_encode(["mensaje" => "No tienes mascotas registradas."]));
            return $response->withStatus(200);
        }

        $response->getBody()->write(json_encode($mascotas));
        return $response;
    }

    public static function EditarMascota($request, $response, $args) {
        $idMascota = $args['id'];
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

        // Validación básica
        if (!isset($data['nombre']) || !isset($data['especie'])) {
            $response->getBody()->write(json_encode(["error" => "Nombre y especie son obligatorios."]));
            return $response->withStatus(400);
        }

        $descripcion = isset($data['descripcion']) ? $data['descripcion'] : null;
        $foto = isset($data['foto']) ? $data['foto'] : null;

        $stmt = $pdo->prepare("
            UPDATE mascotas 
            SET nombre = ?, especie = ?, descripcion = ?, foto = ? 
            WHERE id = ? AND usuario_id = ?
        ");
        $ok = $stmt->execute([$data['nombre'], $data['especie'], $descripcion, $foto, $idMascota, $usuario['id']]);

        if ($ok) {
            $response->getBody()->write(json_encode(["mensaje" => "Mascota actualizada exitosamente."]));
            return $response->withStatus(200);
        } else {
            $response->getBody()->write(json_encode(["error" => "Error al actualizar la mascota."]));
            return $response->withStatus(500);
        }
    }
    
}