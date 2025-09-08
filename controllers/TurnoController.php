<?php

require_once './db/conexion.php';


class TurnoController {

public static function VerTurnosDisponibles($request, $response, $args) {
    $token = $request->getAttribute('jwt');

    // validar que sea admin
    if (!$token || !isset($token->rol) || $token->rol !== 'admin') {
        $response->getBody()->write(json_encode(["error" => "Acceso denegado."]));
        return $response->withStatus(403);
    }

    $pdo = Conexion::obtenerConexion();
    $data = $request->getParsedBody();
    $horariosFijos = ['09:30:00', '10:30:00', '11:30:00', '13:30:00', '14:30:00', '15:30:00'];

    if ($data && isset($data['fecha'])) {
        // ✅ Modo usuario → turnos por fecha
        $fecha = $data['fecha'];
        $stmt = $pdo->prepare("
            SELECT id, fecha, hora, habilitado, disponible
            FROM turnos_disponibles
            WHERE fecha = ? 
              AND habilitado = 1 
              AND disponible = 1
              AND hora IN ('09:30:00','10:30:00','11:30:00','13:30:00','14:30:00','15:30:00')
            ORDER BY fecha, hora
        ");
        $stmt->execute([$fecha]);
    } else {
        // ✅ Modo admin → todos los turnos sin filtrar por fecha
        $stmt = $pdo->prepare("
            SELECT id, fecha, hora, habilitado, disponible
            FROM turnos_disponibles
            WHERE habilitado = 1 
              AND disponible = 1
              AND hora IN ('09:30:00','10:30:00','11:30:00','13:30:00','14:30:00','15:30:00')
            ORDER BY fecha, hora
        ");
        $stmt->execute();
    }

    $turnosDisponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($turnosDisponibles)) {
        $response->getBody()->write(json_encode([
            "mensaje" => "No hay turnos disponibles."
        ]));
        return $response->withStatus(200);
    }

    $response->getBody()->write(json_encode($turnosDisponibles));
    return $response->withHeader('Content-Type', 'application/json');
}


    public static function HabilitarTurnosPorRango($request, $response, $args) {
        $data = $request->getParsedBody();
        if (!$data || !isset($data['fecha_inicio']) || !isset($data['fecha_fin'])) {
            $response->getBody()->write(json_encode(["error" => "Debe proporcionar fecha_inicio y fecha_fin."]));
            return $response->withStatus(400);
        }

        $pdo = Conexion::obtenerConexion();
        $fechaInicio = new DateTime($data['fecha_inicio']);
        $fechaFin = new DateTime($data['fecha_fin']);
        $horarios = ['09:30:00', '10:30:00', '11:30:00', '13:30:00', '14:30:00', '15:30:00'];
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
        $data = $request->getParsedBody();
        $pdo = Conexion::obtenerConexion();

        if (!isset($data['fecha'])) {
            $response->getBody()->write(json_encode(["error" => "Debe proporcionar la fecha."]));
            return $response->withStatus(400);
        }

        $fecha = $data['fecha'];
        $stmt = $pdo->prepare("SELECT t.id FROM turnos t JOIN turnos_disponibles td ON t.turno_disponible_id = td.id WHERE td.fecha = ? AND t.estado = 'pendiente'");
        $stmt->execute([$fecha]);
        $turnosPendientes = $stmt->fetchAll();

        if (!empty($turnosPendientes)) {
            $response->getBody()->write(json_encode(["error" => "No se puede cancelar el día porque hay turnos reservados."]));
            return $response->withStatus(400);
        }

        $stmt = $pdo->prepare("UPDATE turnos_disponibles SET habilitado = 0 WHERE fecha = ?");
        $stmt->execute([$fecha]);
        $response->getBody()->write(json_encode(["mensaje" => "El dia $fecha fue deshabilitado correctamente."]));
        return $response;
    }

    public static function SolicitarTurno($request, $response, $args) {
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

        try {
            // 🚀 Arrancamos la transacción
            $pdo->beginTransaction();

            // 🔒 Bloquear el turno para evitar duplicados
            $stmt = $pdo->prepare("SELECT * FROM turnos_disponibles WHERE id = ? AND habilitado = 1 FOR UPDATE");
            $stmt->execute([$data['turno_disponible_id']]);
            $turnoDisponible = $stmt->fetch();

            if (!$turnoDisponible || $turnoDisponible['disponible'] == 0) {
                $pdo->rollBack();
                $response->getBody()->write(json_encode(["error" => "El turno ya no está disponible."]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Verificar que la mascota pertenece al usuario
            $stmt = $pdo->prepare("SELECT * FROM mascotas WHERE id = ? AND usuario_id = ?");
            $stmt->execute([$data['mascota_id'], $usuario['id']]);
            $mascota = $stmt->fetch();
            if (!$mascota) {
                $pdo->rollBack();
                $response->getBody()->write(json_encode(["error" => "La mascota no pertenece al usuario logueado."]));
                return $response->withStatus(403);
            }

            // 👉 Insertar turno con motivo
            $stmt = $pdo->prepare("INSERT INTO turnos (usuario_id, mascota_id, turno_disponible_id, motivo, descripcion) 
                                VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $usuario['id'],
                $data['mascota_id'],
                $data['turno_disponible_id'],
                $data['motivo'] ?? null,       // motivo debe existir en la DB
                $data['descripcion'] ?? null
            ]);

            // 👉 Marcar el turno como no disponible
            $stmt = $pdo->prepare("UPDATE turnos_disponibles SET disponible = 0 WHERE id = ?");
            $stmt->execute([$data['turno_disponible_id']]);

            // 🚀 Confirmamos la transacción
            $pdo->commit();

            $response->getBody()->write(json_encode(["mensaje" => "Turno solicitado exitosamente."]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $pdo->rollBack();
            // ⚠️ devolvemos detalle del error SQL para debug
            $response->getBody()->write(json_encode([
                "error" => "Error al solicitar turno",
                "detalle" => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public static function CancelarTurno($request, $response, $args) {
        $data = $request->getParsedBody();
        $pdo = Conexion::obtenerConexion();
        $token = $request->getAttribute('jwt');
        if (!$token || !isset($token->email)) {
            $response->getBody()->write(json_encode(["error" => "Token inválido o no proporcionado."]));
            return $response->withStatus(401);
        }

        if (!isset($data['id']) || !isset($data['mascota_id']) || !isset($data['descripcion'])) {
            $response->getBody()->write(json_encode(["error" => "Debe proporcionar el id del turno, el id de la mascota y la descripción."]));
            return $response->withStatus(400);
        }

        $turnoId = $data['id'];
        $mascotaId = $data['mascota_id'];
        $descripcion = $data['descripcion'];
        $emailUsuario = $token->email;
        $stmt = $pdo->prepare("SELECT t.id, t.estado, t.turno_disponible_id FROM turnos t JOIN usuarios u ON t.usuario_id = u.id WHERE t.id = ? AND t.mascota_id = ? AND u.email = ?");
        $stmt->execute([$turnoId, $mascotaId, $emailUsuario]);
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
        $stmt->execute([$descripcion, $turnoId]);
        $stmt = $pdo->prepare("UPDATE turnos_disponibles SET disponible = 1 WHERE id = ?");
        $stmt->execute([$turno['turno_disponible_id']]);
        $response->getBody()->write(json_encode(["mensaje" => "Turno cancelado exitosamente."]));
        return $response;
    }

    public static function VerMisTurnos($request, $response, $args) {
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

        $stmt = $pdo->prepare("SELECT t.id, td.fecha, td.hora, t.estado, t.costo, t.motivo, t.descripcion, 
                                    m.nombre AS mascota,
                                    m.nombre AS mascota, m.especie
                            FROM turnos t 
                            JOIN turnos_disponibles td ON t.turno_disponible_id = td.id 
                            JOIN mascotas m ON t.mascota_id = m.id 
                            WHERE t.usuario_id = ?");
        $stmt->execute([$usuario['id']]);
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

        if (!$token || !isset($token->rol) || $token->rol !== 'admin') {
            $response->getBody()->write(json_encode(["error" => "Acceso denegado."]));
            return $response->withStatus(403);
        }

        $pdo = Conexion::obtenerConexion();
        $stmt = $pdo->query("
            SELECT t.id,
                u.nombre AS usuario,
                m.nombre AS mascota,
                td.fecha,
                td.hora,
                t.motivo,
                t.estado,
                t.costo,
                t.descripcion,
                t.fecha_creacion AS fecha_pedido
            FROM turnos t
            INNER JOIN usuarios u ON t.usuario_id = u.id
            INNER JOIN mascotas m ON t.mascota_id = m.id
            INNER JOIN turnos_disponibles td ON t.turno_disponible_id = td.id
            ORDER BY td.fecha, td.hora
        ");
        $turnos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($turnos));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public static function CambiarEstadoTurno($request, $response, $args) {
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