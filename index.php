<?php
require_once './vendor/autoload.php';
require_once './controllers/UserController.php';
require_once './controllers/MascotaController.php';
require_once './controllers/TurnoController.php';
require_once './db/conexion.php';
require_once './middlewares/AuthJWT.php';

use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$app = AppFactory::create();
$app->setBasePath('/crud');
$app->addBodyParsingMiddleware();

header('Content-Type: application/json');

// CORS Headers
header("Access-Control-Allow-Origin: *"); // o poné tu origen específico
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Opcional: manejar preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$app->group('/usuarios', function (RouteCollectorProxy $group) {
    $group->post('/registro', \UserController::class . ':Registro');
    $group->post('/login', \UserController::class . ':Login');
    $group->post('/crear-pimer-admin', \UserController::class . ':CrearAdmin');
    $group->post('/admin', \UserController::class . ':CrearAdmin')->add('AuthJWT:VerificarAdmin');
    $group->get('/todos', \UserController::class . ':ListarUsuarios')->add('AuthJWT::VerificarAdmin');
    $group->put('/actualizar', \UserController::class . ':ActualizarUsuario')->add('AuthJWT::VerificarToken');
});     

$app->group('/mascotas', function (RouteCollectorProxy $group) {
    $group->post('/agregar', \MascotaController::class . ':AgregarMascota')->add('AuthJWT::VerificarToken');
    $group->get('/ver-mascotas', \MascotaController::class . ':ListarTodasLasMascotas')->add('AuthJWT::VerificarAdmin');
    $group->get('/mis-mascotas', \MascotaController::class . ':ListarMisMascotas')->add('AuthJWT::VerificarToken');
    $group->put('/editar/{id}', \MascotaController::class . ':EditarMascota')->add('AuthJWT::VerificarToken');
});

$app->group('/turnos', function (RouteCollectorProxy $group) {
    $group->post('/disponibles', \TurnoController::class . ':VerTurnosDisponibles')->add('AuthJWT::VerificarToken');
    $group->post('/habilitar-turnos', \TurnoController::class . ':HabilitarTurnosPorRango')->add('AuthJWT::VerificarAdmin');
    $group->post('/solicitar', \TurnoController::class . ':SolicitarTurno')->add('AuthJWT::VerificarToken');
    $group->get('/mis-turnos', \TurnoController::class . ':VerMisTurnos')->add('AuthJWT::VerificarToken');
    $group->get('/todos', \TurnoController::class . ':VerTodosLosTurnos')->add('AuthJWT::VerificarAdmin');

    $group->put('/cancelar', \TurnoController::class . ':CancelarTurno')->add('AuthJWT::VerificarToken');
    $group->patch('/cambiar-estado', \TurnoController::class . ':CambiarEstadoTurno')->add('AuthJWT::VerificarAdmin');
    $group->put('/deshabilitar-dia', \TurnoController::class . ':DeshabilitarDiaCompleto')->add('AuthJWT::VerificarAdmin');
});


$app->run();