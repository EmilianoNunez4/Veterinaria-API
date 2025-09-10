<?php
require_once './db/conexion.php';
use Firebase\JWT\JWT;

class UserController {
    public static function Registro($request, $response, $args) {
        $data = $request->getParsedBody();
        $pdo = Conexion::obtenerConexion();
        $rol = 'user';
        $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, email, password, rol) VALUES (?, ?, ?, ?)");
        $stmt->execute([$data['nombre'], $data['email'], password_hash($data['password'], PASSWORD_DEFAULT), $rol]);
        $response->getBody()->write(json_encode(["mensaje" => "Usuario creado.", "rol" => $rol]));
        return $response;
    }

    public static function CrearAdmin($request, $response, $args) {
        $data = $request->getParsedBody();
        
        if (!$data || !isset($data['nombre']) || !isset($data['email']) || !isset($data['password'])) {
            $response->getBody()->write(json_encode(["error" => "Datos incompletos."]));
            return $response->withStatus(400);
        }

        $pdo = Conexion::obtenerConexion();
        $rol = 'admin';
        $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, email, password, rol) VALUES (?, ?, ?, ?)");
        $stmt->execute([$data['nombre'], $data['email'], password_hash($data['password'], PASSWORD_DEFAULT), $rol]);
        $response->getBody()->write(json_encode(["mensaje" => "Administrador creado.", "rol" => $rol]));
        return $response;
    }

    public static function Login($request, $response, $args) {
        $data = $request->getParsedBody();
        $pdo = Conexion::obtenerConexion();
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
        $stmt->execute([$data['email']]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            $response->getBody()->write(json_encode(["error" => "El correo no está registrado."]));
            return $response->withStatus(401);
        }

        if (!password_verify($data['password'], $usuario['password'])) {
            $response->getBody()->write(json_encode(["error" => "La contraseña es incorrecta."]));
            return $response->withStatus(401);
        }

        $token = JWT::encode(["email" => $usuario['email'], "rol" => $usuario['rol'], "exp" => time() + 3600], "clave_super_secreta", 'HS256');
        $mensaje = $usuario['rol'] === 'admin' ? "Bienvenido administrador." : "Login exitoso.";
        $response->getBody()->write(json_encode(["mensaje" => $mensaje, "token" => $token, "rol" => $usuario['rol']]));
        return $response;
    }

    public static function ListarUsuarios($request, $response, $args) {
        $token = $request->getAttribute('jwt');

        // Verificar que el token sea válido y que el usuario sea admin
        if (!$token || !isset($token->rol) || $token->rol !== 'admin') {
            $response->getBody()->write(json_encode(["error" => "Acceso denegado."]));
            return $response->withStatus(403);
        }

        $pdo = Conexion::obtenerConexion();
        $stmt = $pdo->query("SELECT id, nombre, email, rol, fecha_registro FROM usuarios");
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($usuarios)) {
            $response->getBody()->write(json_encode(["mensaje" => "No hay usuarios registrados."]));
            return $response->withStatus(200);
        }

        $response->getBody()->write(json_encode($usuarios));
        return $response;
    }

    public static function ActualizarUsuario($request, $response, $args) {
        $token = $request->getAttribute('jwt');

        if (!$token || !isset($token->email)) {
            $response->getBody()->write(json_encode(["error" => "Token inválido o no proporcionado."]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $pdo = Conexion::obtenerConexion();

        // Buscar usuario real en DB por email del token
        $stmt = $pdo->prepare("SELECT id, password FROM usuarios WHERE email = ?");
        $stmt->execute([$token->email]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            $response->getBody()->write(json_encode(["error" => "Usuario no encontrado."]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $data = $request->getParsedBody();

        // Validar contraseña actual
        if (empty($data['password_actual']) || !password_verify($data['password_actual'], $usuario['password'])) {
            $response->getBody()->write(json_encode(["error" => "Contraseña actual incorrecta."]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Validar email si lo envía
        if (!empty($data['email'])) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $response->getBody()->write(json_encode(["error" => "Formato de email inválido."]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
            $stmt->execute([$data['email'], $usuario['id']]);
            if ($stmt->fetch()) {
                $response->getBody()->write(json_encode(["error" => "El email ya está en uso."]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
        }

        // Armar query dinámicamente
        $updates = [];
        $params = [];

        if (!empty($data['nombre'])) {
            $updates[] = "nombre = ?";
            $params[] = trim($data['nombre']);
        }

        if (!empty($data['email'])) {
            $updates[] = "email = ?";
            $params[] = strtolower(trim($data['email']));
        }

        if (!empty($data['password_nueva'])) {
            if (strlen($data['password_nueva']) < 6) {
                $response->getBody()->write(json_encode(["error" => "La nueva contraseña debe tener al menos 6 caracteres."]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $updates[] = "password = ?";
            $params[] = password_hash($data['password_nueva'], PASSWORD_DEFAULT);
        }

        if (!empty($updates)) {
            $params[] = $usuario['id'];
            $sql = "UPDATE usuarios SET " . implode(", ", $updates) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } else {
            $response->getBody()->write(json_encode(["mensaje" => "No se realizaron cambios."]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(["mensaje" => "Usuario actualizado con éxito."]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

}