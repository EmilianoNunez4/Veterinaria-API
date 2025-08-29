<?php

require_once './db/conexion.php';

class TurnoController {

    public static function VerTurnosDisponibles($request, $response, $args) {
        $data = $request->getParsedBody();
        if (!$data || !isset($data['fecha'])) {
            $response->getBody()->write(json_encode(["error" => "Debe proporcionar una fecha."]));
            return $response->withStatus(400);
        }

        $pdo = Conexion::obtenerConexion();
        $fecha = $data['fecha'];

        $stmt = $pdo->prepare("SELECT id, hora 
                               FROM turnos_disponibles 
                               WHERE fecha = ? AND habilitado = 1 AND disponible = 1");
        $stmt->execute([$fecha]);
        $turnosDisponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($turnosDisponibles)) {
            $response->getBody()->write(json_encode([
                "mensaje" => "No hay turnos disponibles para la fecha seleccionada."
            ]));
            return $response->withStatus(200);
        }
        $response->getBody()->write(json_encode($turnosDisponibles));
        return $response;
    }

    public static function HabilitarTurnosPorRango($request, $response, $args) {
        $token = $request->getAttribute('jwt');
        if ($token->rol !== 'admin') {
            $response->getBody()->write(json_encode(["error" => "Acceso denegado. Solo admin."]));
            return $response->withStatus(403);
        }

        $data = $request->getParsedBody();
        if (!$data || !isset($data['fecha_inicio']) || !isset($data['fecha_fin'])) {
            $response->getBody()->write(json_encode(["error" => "Debe proporcionar fecha_inicio y fecha_fin."]));
            return $response->withStatus(400);
        }

        $pdo = Conexion::obtenerConexion();
        $fechaInicio = new DateTime($data['fecha_inicio']);
        $fechaFin = new DateTime($data['fecha_fin']);
        $horarios = ['09:30:00','10:30:00','11:30:00','13:30:00','14:30:00','15:30:00'];
        $turnosCreados = [];

        while ($fechaInicio <= $fechaFin) {
            if ($fechaInicio->format('N') < 6) { 
                foreach ($horarios as $hora) {
                    $stmt = $pdo->prepare("SELECT * FROM turnos_disponibles WHERE fecha = ? AND hora = ?");
                    $stmt->execute([$fechaInicio->format('Y-m-d'), $hora]);
                    $existe = $stmt->fetch();

                    if (!$existe) {
                        $stmt = $pdo->prepare("INSERT INTO turnos_disponibles (fecha, hora, habilitado, disponible) VALUES (?, ?, 1, 1)");
                        $stmt->execute([$fechaInicio->format('Y-m-d'), $hora]);
                        $turnosCreados[] = ["fecha" => $fechaInicio->format('Y-m-d'), "hora" => $hora];
                    }
                }
            }
            $fechaInicio->modify('+1 day');
        }

        $response->getBody()->write(json_encode([
            "mensaje" => "Turnos habilitados exitosamente para el rango seleccionado.",
            "turnos_creados" => $turnosCreados
        ]));
        return $response;
    }

    public static function DeshabilitarDiaCompleto($request, $response, $args) {
        $token = $request->getAttribute('jwt');
        if ($token->rol !== 'admin') {
            $response->getBody()->write(json_encode(["error" => "Acceso denegado. Solo admin."]));
            return $response->withStatus(403);
        }

        $data = $request->getParsedBody();
        $pdo = Conexion::obtenerConexion();

        if (!isset($data['fecha'])) {
            $response->getBody()->write(json_encode(["error" => "Debe proporcionar la fecha."]));
            return $response->withStatus(400);
        }

        $fecha = $data['fecha'];
        $stmt = $pdo->prepare("SELECT t.id FROM turnos t 
                               JOIN turnos_disponibles td ON t.turno_disponible_id = td.id 
                               WHERE td.fecha = ? AND t.estado = 'pendiente'");
        $stmt->execute([$fecha]);
        $turnosPendientes = $stmt->fetchAll();

        if (!empty($turnosPendientes)) {
            $response->getBody()->write(json_encode(["error" => "No se puede cancelar el día porque hay turnos reservados."]));
            return $response->withStatus(400);
        }

        $stmt = $pdo->prepare("UPDATE turnos_disponibles SET habilitado = 0 WHERE fecha = ?");
        $stmt->execute([$fecha]);

        $response->getBody()->write(json_encode(["mensaje" => "El día $fecha fue deshabilitado correctamente."]));
        return $response;
    }

    public static function SolicitarTurno($request, $response, $args) {
        $pdo = Conexion::obtenerConexion();
        $token = $request->getAttribute('jwt');

        if (!$token || !isset($token->id)) {
            $response->getBody()->write(json_encode(["error" => "Token inválido o no proporcionado."]));
            return $response->withStatus(401);
        }

        $data = $request->getParsedBody();

        $stmt = $pdo->prepare("SELECT * FROM turnos_disponibles WHERE id = ? AND habilitado = 1 AND disponible = 1");
        $stmt->execute([$data['turno_disponible_id']]);
        $turnoDisponible = $stmt->fetch();

        if (!$turnoDisponible) {
            $response->getBody()->write(json_encode(["error" => "El turno seleccionado no está disponible."]));
            return $response->withStatus(400);
        }

        $stmt = $pdo->prepare("SELECT * FROM mascotas WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$data['mascota_id'], $token->id]);
        $mascota = $stmt->fetch();

        if (!$mascota) {
            $response->getBody()->write(json_encode(["error" => "La mascota no pertenece al usuario logueado."]));
            return $response->withStatus(403);
        }

        $stmt = $pdo->prepare("INSERT INTO turnos (usuario_id, mascota_id, turno_disponible_id, descripcion) VALUES (?, ?, ?, ?)");
        $stmt->execute([$token->id, $data['mascota_id'], $data['turno_disponible_id'], $data['descripcion']]);

        $stmt = $pdo->prepare("UPDATE turnos_disponibles SET disponible = 0 WHERE id = ?");
        $stmt->execute([$data['turno_disponible_id']]);

        $response->getBody()->write(json_encode(["mensaje" => "Turno solicitado exitosamente."]));
        return $response->withStatus(201);
    }

    public static function CancelarTurno($request, $response, $args) {
        $pdo = Conexion::obtenerConexion();
        $token = $request->getAttribute('jwt');

        if (!$token || !isset($token->id)) {
            $response->getBody()->write(json_encode(["error" => "Token inválido o no proporcionado."]));
            return $response->withStatus(401);
        }

        $data = $request->getParsedBody();

        if (!isset($data['id']) || !isset($data['mascota_id']) || !isset($data['descripcion'])) {
            $response->getBody()->write(json_encode(["error" => "Debe proporcionar el id del turno, el id de la mascota y la descripción."]));
            return $response->withStatus(400);
        }

        $stmt = $pdo->prepare("SELECT t.id, t.estado, t.turno_disponible_id 
                               FROM turnos t 
                               WHERE t.id = ? AND t.mascota_id = ? AND t.usuario_id = ?");
        $stmt->execute([$data['id'], $data['mascota_id'], $token->id]);
        $turno = $stmt->fetch();

        if (!$turno) {
            $response->getBody()->write(json_encode(["error" => "Turno no encontrado o no te pertenece."]));
            return $response->withStatus(403);
        }

        if ($turno['estado'] == 'asistido' || $turno['estado'] == 'ausente') {
            $response->getBody()->write(json_encode(["error" => "No se puede cancelar un turno que ya fue asistido o marcado como ausente."]));
            return $response->withStatus(400);
        }

        $stmt = $pdo->prepare("UPDATE turnos SET estado = 'cancelado', descripcion = ? WHERE id = ?");
        $stmt->execute([$data['descripcion'], $data['id']]);

        $stmt = $pdo->prepare("UPDATE turnos_disponibles SET disponible = 1 WHERE id = ?");
        $stmt->execute([$turno['turno_disponible_id']]);

        $response->getBody()->write(json_encode(["mensaje" => "Turno cancelado exitosamente."]));
        return $response;
    }

    public static function VerMisTurnos($request, $response, $args) {
        $pdo = Conexion::obtenerConexion();
        $token = $request->getAttribute('jwt');

        if (!$token || !isset($token->id)) {
            $response->getBody()->write(json_encode(["error" => "Token inválido o no proporcionado."]));
            return $response->withStatus(401);
        }

        $stmt = $pdo->prepare("SELECT t.id, td.fecha, td.hora, t.estado, t.costo, t.descripcion, m.nombre AS mascota 
                               FROM turnos t 
                               JOIN turnos_disponibles td ON t.turno_disponible_id = td.id 
                               JOIN mascotas m ON t.mascota_id = m.id 
                               WHERE t.usuario_id = ?");
        $stmt->execute([$token->id]);
        $turnos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($turnos)) {
            $response->getBody()->write(json_encode(["mensaje" => "No tienes turnos registrados."]));
            return $response->withStatus(200);
        }

        $response->getBody()->write(json_encode($turnos));
        return $response;
    }

    public static function VerTodosLosTurnos($request, $response, $args) {
        $token = $request->getAttribute('jwt');
        if ($token->rol !== 'admin') {
            $response->getBody()->write(json_encode(["error" => "Acceso denegado. Solo admin."]));
            return $response->withStatus(403);
        }

        $pdo = Conexion::obtenerConexion();
        $stmt = $pdo->query("SELECT t.id, td.fecha, td.hora, t.estado, t.costo, t.descripcion, 
                                    m.nombre AS mascota, u.nombre AS usuario, u.email 
                             FROM turnos t 
                             JOIN turnos_disponibles td ON t.turno_disponible_id = td.id 
                             JOIN mascotas m ON t.mascota_id = m.id 
                             JOIN usuarios u ON t.usuario_id = u.id");
        $turnos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($turnos)) {
            $response->getBody()->write(json_encode(["mensaje" => "No hay turnos registrados."]));
            return $response->withStatus(200);
        }

        $response->getBody()->write(json_encode($turnos));
        return $response;
    }

    public static function CambiarEstadoTurno($request, $response, $args) {
        $token = $request->getAttribute('jwt');
        if ($token->rol !== 'admin') {
            $response->getBody()->write(json_encode(["error" => "Acceso denegado. Solo admin."]));
            return $response->withStatus(403);
        }

        $data = $request->getParsedBody();
        if (!isset($data['id']) || !isset($data['estado'])) {
            $response->getBody()->write(json_encode(["error" => "Debe proporcionar el id del turno y el nuevo estado."]));
            return $response->withStatus(400);
        }

        $turnoId = $data['id'];
        $nuevoEstado = $data['estado'];
        $nuevoCosto = isset($data['costo']) ? $data['costo'] : null;
        $estadosPermitidos = ['pendiente', 'asistido', 'ausente', 'cancelado'];

        if (!in_array($nuevoEstado, $estadosPermitidos)) {
            $response->getBody()->write(json_encode(["error" => "Estado no permitido."]));
            return $response->withStatus(400);
        }

        $pdo = Conexion::obtenerConexion();
        $stmt = $pdo->prepare("SELECT * FROM turnos WHERE id = ?");
        $stmt->execute([$turnoId]);
        $turno = $stmt->fetch();

        if (!$turno) {
            $response->getBody()->write(json_encode(["error" => "El turno no existe."]));
            return $response->withStatus(404);
        }

        if ($nuevoCosto !== null) {
            $stmt = $pdo->prepare("UPDATE turnos SET estado = ?, costo = ? WHERE id = ?");
            $stmt->execute([$nuevoEstado, $nuevoCosto, $turnoId]);
        } else {
            $stmt = $pdo->prepare("UPDATE turnos SET estado = ? WHERE id = ?");
            $stmt->execute([$nuevoEstado, $turnoId]);
        }

        $response->getBody()->write(json_encode(["mensaje" => "Estado del turno actualizado a $nuevoEstado."]));
        return $response;
    }
}
